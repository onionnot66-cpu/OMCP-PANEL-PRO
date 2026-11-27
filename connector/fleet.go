package main

import (
 "encoding/json"
 "errors"
 "fmt"
 "os"
 "path/filepath"
 "regexp"
 "syscall"
 "strings"
 "strconv"
)

var instanceIDPattern=regexp.MustCompile("^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")

type fleetEntry struct{
 ID string `json:"id"`
 Memory int `json:"memory_mib"`
 Port int `json:"port"`
}
type fleet struct{
 lock *os.File
 servers map[string]*supervisor
 ports map[string]int
}
func validateFleet(entries []fleetEntry,budget int)error{
 if len(entries)<1||len(entries)>32||budget<128{return errors.New("invalid fleet size or memory budget")}
 ids:=map[string]bool{};ports:=map[int]bool{};var sum int64
 for _,e:=range entries{
  if !instanceIDPattern.MatchString(e.ID)||ids[e.ID]||ports[e.Port]||e.Port<1024||e.Port>65535||e.Memory<128||e.Memory>1048576{return errors.New("invalid or duplicate instance ID, port or memory")}
  ids[e.ID]=true;ports[e.Port]=true;sum+=int64(e.Memory)
 }
 if sum>int64(budget){return errors.New("local fleet heap budget exceeded")}
 return nil
}
func openFleet(root,manifest string,budget int,provision,console bool)(*fleet,error){
 if os.Geteuid()==0{return nil,errors.New("trusted fleet must run non-root")}
 info,err:=os.Lstat(manifest)
 if err!=nil||!info.Mode().IsRegular()||info.Mode().Perm()&0077!=0||info.Size()>65536{return nil,errors.New("fleet manifest must be a private regular file")}
 b,err:=os.ReadFile(manifest);if err!=nil{return nil,err}
 var entries []fleetEntry
 if err=json.Unmarshal(b,&entries);err!=nil{return nil,err}
 if err=validateFleet(entries,budget);err!=nil{return nil,err}
 root,err=filepath.EvalSymlinks(root);if err!=nil{return nil,err}
 root,err=filepath.Abs(root);if err!=nil{return nil,err}
 info,err=os.Stat(root)
 if err!=nil||!info.IsDir()||info.Mode().Perm()&0077!=0{return nil,errors.New("fleet root must be a private directory")}
 home,err:=os.UserHomeDir();if err!=nil{return nil,err}
 lockdir:=filepath.Join(home,".omcp-connect")
 if err=os.MkdirAll(lockdir,0700);err!=nil{return nil,err}
 lock,err:=os.OpenFile(filepath.Join(lockdir,"native.lock"),os.O_CREATE|os.O_RDWR,0600);if err!=nil{return nil,err}
 if err=syscall.Flock(int(lock.Fd()),syscall.LOCK_EX|syscall.LOCK_NB);err!=nil{lock.Close();return nil,errors.New("another native supervisor is active")}
 f:=&fleet{lock:lock,servers:map[string]*supervisor{},ports:map[string]int{}}
 for _,e:=range entries{
  dir:=filepath.Join(root,e.ID)
  info,err:=os.Lstat(dir)
  if err!=nil&&!os.IsNotExist(err){f.close();return nil,err}
  if err==nil&&(!info.IsDir()||info.Mode().Perm()&0077!=0){f.close();return nil,errors.New("instance directory must be private and not a symlink")}
  f.servers[e.ID]=&supervisor{dir:dir,memory:e.Memory,provision:provision,allowConsole:console}
  f.ports[e.ID]=e.Port
 }
 return f,nil
}
func(f *fleet) execute(j job)(bool,string){
 s,ok:=f.servers[j.Payload.InstanceID];if !ok{return false,"instance is not locally authorized"}
 if j.Payload.Memory!=s.memory||j.Payload.Port!=f.ports[j.Payload.InstanceID]{return false,"reservation does not match local memory/port manifest"}
 info,err:=os.Lstat(s.dir)
 if err!=nil&&!os.IsNotExist(err){return false,"instance directory unavailable"}
 if err==nil&&(!info.IsDir()||info.Mode().Perm()&0077!=0){return false,"unsafe instance directory"}
 switch j.Action{
 case "instance.files","instance.file_read","instance.file_write","instance.file_delete","instance.property":return s.fileJob(j)
 case "instance.install","instance.start","instance.stop","instance.restart","instance.kill","instance.status","instance.console","instance.metrics":
  if j.Action=="instance.start"||j.Action=="instance.restart"{
   // Require the reserved port in canonical setup output, not an arbitrary edited config.
   path:=filepath.Join(s.dir,"server.properties")
   p,e:=os.Lstat(path)
   if e!=nil||!p.Mode().IsRegular()||p.Size()>256*1024{return false,"server properties unavailable"}
   b,e:=os.ReadFile(path);if e!=nil{return false,"cannot read server properties"}
   if !matchesFleetPort(string(b),j.Payload.Port){return false,"configured port does not match local reservation"}
  }
  j.Action="native."+j.Action[len("instance."):]
  return s.executeJob(j)
 default:return false,"unsupported instance action"
 }
}
func(f *fleet) close(){
 for _,s:=range f.servers{if err:=s.stop(false);err!=nil{fmt.Fprintln(os.Stderr,"instance stop:",err)}}
 _=f.lock.Close()
}

func matchesFleetPort(data string,port int)bool{
 found:=false
 for _,line:=range strings.Split(strings.ReplaceAll(data,"\r\n","\n"),"\n"){
  t:=strings.TrimSpace(line)
  if t==""||strings.HasPrefix(t,"#")||strings.HasPrefix(t,"!"){continue}
  if (len(line)-len(strings.TrimRight(line,"\\")))%2!=0{return false}
  k,v,ok:=strings.Cut(t,"=")
  if !ok||k==""||strings.ContainsAny(k,"\\: \t\r\n"){return false}
  if k=="server-port"{if found||v!=strconv.Itoa(port){return false};found=true}
 }
 return found
}

func(f *fleet) enableContainers(image string,cpus,budget int)error{
 binary,err:=checkContainerRuntime(image,cpus)
 if err!=nil{return err}
 var total int64
 for _,s:=range f.servers{total+=int64(s.memory)+512}
 if total>int64(budget){return errors.New("fleet container budget must include 512 MiB overhead per server")}
 for id,s:=range f.servers{
  if strings.ContainsAny(s.dir,":,\n\r"){return errors.New("unsupported bind mount path")}
  s.container=&containerRuntime{binary:binary,image:image,name:"omcp-"+id,port:f.ports[id],memory:s.memory+512,cpus:cpus}
 }
 return nil
}
