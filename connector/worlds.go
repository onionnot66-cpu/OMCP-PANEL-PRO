package main

import (
 "archive/zip"
 "context"
 "crypto/sha256"
 "encoding/hex"
 "encoding/json"
 "errors"

 "io"
 "os"
 "path/filepath"
 "regexp"
 "sort"
 "strings"
 "syscall"
)

const worldBytes int64=256*1024*1024
const worldEntries=10000
var worldName=regexp.MustCompile("^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$")
var worldPart=regexp.MustCompile("^[A-Za-z0-9_.+-]{1,128}$")
var worldDigest=regexp.MustCompile("^[a-f0-9]{64}$")

type worldBackup struct{
 ID string `json:"id"`
 World string `json:"world"`
 SHA256 string `json:"sha256"`
 Bytes int64 `json:"bytes"`
}

func validWorldName(name string)bool{
 if !worldName.MatchString(name){return false}
 switch strings.ToLower(name){case "plugins","mods","config","logs","libraries","versions","cache","crash-reports","datapacks":return false}
 return true
}
func validWorldPath(name string)bool{
 if len(name)>512||name==""||strings.Contains(name,"\\"){return false}
 for _,part:=range strings.Split(name,"/"){
  if !worldPart.MatchString(part)||part=="."||part==".."{return false}
 }
 return true
}
func openWorldRegular(path string,limit int64)(*os.File,os.FileInfo,error){
 f,e:=os.OpenFile(path,os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK,0)
 if e!=nil{return nil,nil,e}
 st,e:=f.Stat()
 if e!=nil{f.Close();return nil,nil,e}
 raw,ok:=st.Sys().(*syscall.Stat_t)
 if !ok||!st.Mode().IsRegular()||raw.Nlink!=1||st.Size()<0||st.Size()>limit{f.Close();return nil,nil,errors.New("unsafe or oversized world file")}
 return f,st,nil
}
func worldDirectory(base,name string)(string,error){
 if !validWorldName(name){return "",errors.New("invalid world name")}
 dir:=filepath.Join(base,name)
 st,e:=os.Lstat(dir)
 if e!=nil{return "",e}
 if !st.IsDir()||st.Mode()&os.ModeSymlink!=0{return "",errors.New("world must be a real directory")}
 f,st,e:=openWorldRegular(filepath.Join(dir,"level.dat"),worldBytes)
 if e!=nil{return "",errors.New("world has no safe level.dat")}
 f.Close()
 if st.Size()==0{return "",errors.New("empty level.dat")}
 return dir,nil
}
type worldContextReader struct{ctx context.Context;r io.Reader}
func(r worldContextReader)Read(b []byte)(int,error){if e:=r.ctx.Err();e!=nil{return 0,e};return r.r.Read(b)}
func writeWorldZIP(ctx context.Context,source string,out io.Writer)error{
 z:=zip.NewWriter(out)
 var total int64
 count:=0
 err:=filepath.WalkDir(source,func(path string,d os.DirEntry,e error)error{
  if e!=nil{return e};if e=ctx.Err();e!=nil{return e}
  rel,e:=filepath.Rel(source,path);if e!=nil{return e};if rel=="."{return nil}
  rel=filepath.ToSlash(rel)
  count++
  if count>worldEntries||!validWorldPath(rel){return errors.New("world exceeds entry limit or has unsupported file names")}
  if d.Type()&os.ModeSymlink!=0{return errors.New("symlinks are not backed up")}
  if d.IsDir(){_,e=z.Create(rel+"/");return e}
  f,st,e:=openWorldRegular(path,worldBytes-total);if e!=nil{return e}
  defer f.Close()
  w,e:=z.CreateHeader(&zip.FileHeader{Name:rel,Method:zip.Store});if e!=nil{return e}
  n,e:=io.Copy(w,io.LimitReader(worldContextReader{ctx,f},st.Size()+1))
  if e!=nil{return e}
  if n!=st.Size(){return errors.New("world changed during backup")}
  total+=n
  return nil
 })
 if err!=nil{_ = z.Close();return err}
 return z.Close()
}
func extractWorldZIP(ctx context.Context,r io.ReaderAt,size int64,stage string)error{
 if size<1||size>worldBytes+8*1024*1024{return errors.New("archive exceeds size limit")}
 z,e:=zip.NewReader(r,size);if e!=nil{return e}
 if len(z.File)>worldEntries{return errors.New("too many archive entries")}
 var total uint64
 seen:=map[string]bool{}
 for _,entry:=range z.File{
  name:=strings.TrimSuffix(entry.Name,"/")
  if !validWorldPath(name)||seen[name]{return errors.New("unsafe or duplicate archive path")}
  seen[name]=true
  if entry.Mode()&os.ModeSymlink!=0||(!entry.FileInfo().IsDir()&&!entry.Mode().IsRegular()){return errors.New("unsupported archive entry type")}
  if entry.UncompressedSize64>uint64(worldBytes)-total{return errors.New("expanded world exceeds limit")}
  total+=entry.UncompressedSize64
 }
 for _,entry:=range z.File{
  if e=ctx.Err();e!=nil{return e}
  dest:=filepath.Join(stage,strings.TrimSuffix(entry.Name,"/"))
  if entry.FileInfo().IsDir(){if e=os.MkdirAll(dest,0700);e!=nil{return e};continue}
  if e=os.MkdirAll(filepath.Dir(dest),0700);e!=nil{return e}
  f,e:=os.OpenFile(dest,os.O_WRONLY|os.O_CREATE|os.O_EXCL,0600);if e!=nil{return e}
  body,e:=entry.Open();if e!=nil{f.Close();return e}
  n,e:=io.Copy(f,io.LimitReader(worldContextReader{ctx,body},int64(entry.UncompressedSize64)+1))
  body.Close()
  if e==nil&&n!=int64(entry.UncompressedSize64){e=errors.New("archive size mismatch")}
  if e==nil{e=f.Sync()}
  ce:=f.Close()
  if e!=nil{return e};if ce!=nil{return ce}
 }
 f,st,e:=openWorldRegular(filepath.Join(stage,"level.dat"),worldBytes)
 if e!=nil{return errors.New("archive must contain level.dat at root")}
 f.Close()
 if st.Size()==0{return errors.New("empty level.dat")}
 return nil
}
func (f *fleet) backupDirectory(id string)(string,error){
 if !instanceIDPattern.MatchString(id){return "",errors.New("invalid instance")}
 p:=filepath.Join(f.root,".omcp-backups-"+id)
 if e:=os.Mkdir(p,0700);e!=nil&&!os.IsExist(e){return "",e}
 st,e:=os.Lstat(p);if e!=nil{return "",e}
 raw,ok:=st.Sys().(*syscall.Stat_t)
 if !ok||!st.IsDir()||st.Mode().Perm()&0077!=0||raw.Uid!=uint32(os.Geteuid()){return "",errors.New("unsafe backup directory")}
 return p,nil
}
func listWorldBackups(dir string)([]worldBackup,error){
 d,e:=os.Open(dir);if e!=nil{return nil,e};defer d.Close()
 entries,e:=d.Readdirnames(64);if e!=nil&&e!=io.EOF{return nil,e}
 if len(entries)>=64{return nil,errors.New("backup directory requires local reconciliation")}
 out:=[]worldBackup{}
 for _,name:=range entries{
  id:=strings.TrimSuffix(name,".json")
  if !strings.HasSuffix(name,".json")||!instanceIDPattern.MatchString(id){continue}
  b,e:=readPrivateFile(filepath.Join(dir,name),2048);if e!=nil{return nil,e}
  var item worldBackup
  if json.Unmarshal(b,&item)!=nil||item.ID!=id||!validWorldName(item.World)||!worldDigest.MatchString(item.SHA256)||item.Bytes<1||item.Bytes>worldBytes+8*1024*1024{return nil,errors.New("invalid backup metadata")}
  a,st,e:=openWorldRegular(filepath.Join(dir,id+".zip"),worldBytes+8*1024*1024);if e!=nil{return nil,e};a.Close()
  if st.Size()!=item.Bytes{return nil,errors.New("backup size changed")}
  out=append(out,item)
 }
 sort.Slice(out,func(i,j int)bool{return out[i].ID<out[j].ID})
 return out,nil
}
func createWorldBackup(ctx context.Context,dir,source,world,id string)(worldBackup,error){
 var item worldBackup
 if !instanceIDPattern.MatchString(id){return item,errors.New("invalid backup ID")}
 current,e:=listWorldBackups(dir);if e!=nil{return item,e}
 physical,e:=filepath.Glob(filepath.Join(dir,"*.zip"));if e!=nil{return item,e}
 if len(current)>=5||len(physical)>=5{return item,errors.New("five backups retained; administrator must reconcile storage locally")}
 for _,old:=range current{if old.ID==id{return item,errors.New("backup ID already exists")}}
 tmp,e:=os.CreateTemp(dir,".archive-");if e!=nil{return item,e}
 defer os.Remove(tmp.Name())
 hash:=sha256.New()
 e=writeWorldZIP(ctx,source,io.MultiWriter(tmp,hash))
 if e==nil{e=tmp.Sync()}
 st,se:=tmp.Stat()
 ce:=tmp.Close()
 if e!=nil{return item,e};if se!=nil{return item,se};if ce!=nil{return item,ce}
 if st.Size()>worldBytes+8*1024*1024{return item,errors.New("archive too large")}
 if e=ctx.Err();e!=nil{return item,e}
 item=worldBackup{id,world,hex.EncodeToString(hash.Sum(nil)),st.Size()}
 b,e:=json.Marshal(item);if e!=nil{return item,e}
 meta,e:=os.CreateTemp(dir,".receipt-");if e!=nil{return item,e};defer os.Remove(meta.Name())
 if _,e=meta.Write(b);e==nil{e=meta.Sync()};ce=meta.Close()
 if e!=nil{return item,e};if ce!=nil{return item,ce}
 archive:=filepath.Join(dir,id+".zip")
 if e=os.Link(tmp.Name(),archive);e!=nil{return item,e}
 if e=os.Remove(tmp.Name());e!=nil{return item,e}
 if e=os.Link(meta.Name(),filepath.Join(dir,id+".json"));e!=nil{_ = os.Remove(archive);return item,e}
 if e=os.Remove(meta.Name());e!=nil{return item,e}
 d,e:=os.Open(dir);if e!=nil{return item,e};defer d.Close()
 return item,d.Sync()
}
func restoreWorldBackup(ctx context.Context,dir,game,id,target,expected string)error{
 if !instanceIDPattern.MatchString(id)||!validWorldName(target)||!worldDigest.MatchString(expected){return errors.New("invalid restore selection")}
 current,e:=listWorldBackups(dir);if e!=nil{return e}
 var found bool
 for _,b:=range current{if b.ID==id&&b.SHA256==expected{found=true}}
 if !found{return errors.New("backup receipt not found")}
 dest:=filepath.Join(game,target)
 if _,e=os.Lstat(dest);!os.IsNotExist(e){return errors.New("destination exists; restore never overwrites")}
 f,st,e:=openWorldRegular(filepath.Join(dir,id+".zip"),worldBytes+8*1024*1024);if e!=nil{return e};defer f.Close()
 h:=sha256.New()
 if _,e=io.Copy(h,worldContextReader{ctx,f});e!=nil{return e}
 if hex.EncodeToString(h.Sum(nil))!=expected{return errors.New("backup checksum mismatch")}
 stage,e:=os.MkdirTemp(game,".omcp-world-");if e!=nil{return e};defer os.RemoveAll(stage)
 if e=extractWorldZIP(ctx,f,st.Size(),stage);e!=nil{return e}
 if e=ctx.Err();e!=nil{return e}
 // The fleet worker serializes actions; a trusted offline operator must not
 // concurrently modify the game tree. Destination is checked again.
 if _,e=os.Lstat(dest);!os.IsNotExist(e){return errors.New("destination appeared; restore refused")}
 if e=os.Rename(stage,dest);e!=nil{return e}
 d,e:=os.Open(game);if e!=nil{return e};defer d.Close()
 return d.Sync()
}
func(f *fleet) worldJob(ctx context.Context,s *supervisor,j job)(bool,string){
 if !f.allowWorlds||(f.backend!="docker"&&f.backend!="podman"){return false,"local container world manager opt-in required"}
 if s.running(){return false,"stop the server before world operations"}
 if e:=s.backend.requireAbsent();e!=nil{return false,e.Error()}
 p:=j.Payload
 var result any
 switch j.Action{
 case "instance.world_list":
  d,e:=os.Open(s.dir);if e!=nil{return false,e.Error()};defer d.Close()
  names,e:=d.Readdirnames(1025);if e!=nil&&e!=io.EOF{return false,e.Error()}
  if len(names)>1024{return false,"too many game entries"}
  out:=[]string{}
  for _,name:=range names{if _,e=worldDirectory(s.dir,name);e==nil{out=append(out,name)}}
  if len(out)>40{return false,"too many worlds for this view"}
  sort.Strings(out);result=out
 case "instance.world_backup_list":
  d,e:=f.backupDirectory(p.InstanceID);if e!=nil{return false,e.Error()}
  result,e=listWorldBackups(d);if e!=nil{return false,e.Error()}
 case "instance.world_backup":
  source,e:=worldDirectory(s.dir,p.World);if e!=nil{return false,e.Error()}
  d,e:=f.backupDirectory(p.InstanceID);if e!=nil{return false,e.Error()}
  result,e=createWorldBackup(ctx,d,source,p.World,j.ID);if e!=nil{return false,e.Error()}
 case "instance.world_restore":
  d,e:=f.backupDirectory(p.InstanceID);if e!=nil{return false,e.Error()}
  if e=restoreWorldBackup(ctx,d,s.dir,p.BackupID,p.World,p.BackupSHA256);e!=nil{return false,e.Error()}
  result=map[string]string{"restored":p.World}
 case "instance.world_import","instance.world_export":return f.transferWorld(ctx,s,j)
 case "instance.world_activate":
  if p.Software!="vanilla"{return false,"automatic activation is limited to Vanilla; Paper-family dimensions need manual review"}
  if _,e:=worldDirectory(s.dir,p.World);e!=nil{return false,e.Error()}
  root,e:=openGameRoot(s.dir);if e!=nil{return false,e.Error()};defer root.Close()
  data,e:=readGameFile(root,"server.properties");if e!=nil{return false,e.Error()}
  updated,e:=replaceProperty(string(data),"level-name",p.World);if e!=nil{return false,e.Error()}
  if !matchesFleetPort(updated,p.Port){return false,"invalid reserved port"}
  if len(updated)>fileLimit{return false,"properties too large"}
  if e=ctx.Err();e!=nil{return false,e.Error()}
  if e=replaceGameFile(root,"server.properties",[]byte(updated));e!=nil{return false,e.Error()}
  result=map[string]string{"active_on_restart":p.World}
 default:return false,"unsupported world action"
 }
 b,e:=json.Marshal(result);if e!=nil||len(b)>4096{return false,"world result exceeds limit"}
 return true,string(b)
}
