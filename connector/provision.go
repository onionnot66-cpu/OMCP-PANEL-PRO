package main

import (
 "context"
 "encoding/json"
 "fmt"
 "os"
 "path/filepath"
 "strconv"
 "strings"
 "syscall"
)

type jobPayload struct {
 OwnerControl bool `json:"owner_control"`
 World string `json:"world"`
 BackupID string `json:"backup_id"`
 BackupSHA256 string `json:"backup_sha256"`
 TransferSHA256 string `json:"transfer_sha256"`
 TransferBytes int64 `json:"transfer_bytes"`
 PluginProvider string `json:"plugin_provider"`
 PluginSHA256 string `json:"plugin_sha256"`
 PluginProject string `json:"plugin_project"`
 PluginVersion string `json:"plugin_version"`
 PluginSHA512 string `json:"plugin_sha512"`
 PluginDisabled bool `json:"plugin_disabled"`
 Runtime string `json:"runtime"`
 Software string `json:"software"`
 Version string `json:"version"`
 TargetVersion string `json:"target_version"`
 ModpackLoader string `json:"modpack_loader"`
 Port int `json:"port"`
 AcceptEULA bool `json:"accept_eula"`
 Command string `json:"command"`
 InstanceID string `json:"instance_id"`
 Memory int `json:"memory_mib"`
 File string `json:"file"`
 Content string `json:"content_base64"`
 Expected string `json:"expected"`
 Key string `json:"key"`
 Value string `json:"value"`
}

func(s *supervisor) executeJob(ctx context.Context, j job)(bool,string){
 switch j.Action{
 case "native.install":
  if !s.provision{return false,"local operator must explicitly enable provisioning"}
  if s.running(){return false,"stop the running process first"}
  if _,err:=os.Lstat(s.dir);!os.IsNotExist(err){return false,"target already exists or cannot be inspected; no overwrite"}
  p:=j.Payload
  if !p.AcceptEULA{return false,"EULA acceptance required"}
  err:=downloadMinecraftContext(ctx, []string{"--software",p.Software,"--version",p.Version,"--dir",s.dir,"--port",strconv.Itoa(p.Port),"--accept-eula"})
  if err!=nil{return false,err.Error()}
  return true,"Minecraft installed in configured directory; not started"
 case "native.command":return s.command(j.Payload.Command)
 case "native.metrics":
  return s.metrics()
 default:return s.execute(ctx,j.Action)
 }
}

func(s *supervisor) metricReport()map[string]any{
 running:=s.running()
 report:=map[string]any{"process_running":running,"java_heap_limit_mib":s.memory}
 path:=s.dir
 if _,err:=os.Stat(path);os.IsNotExist(err){path=filepath.Dir(path)}
 var fs syscall.Statfs_t
 if syscall.Statfs(path,&fs)==nil{
  report["filesystem_available_bytes"]=uint64(fs.Bavail)*uint64(fs.Bsize)
  report["filesystem_capacity_bytes"]=uint64(fs.Blocks)*uint64(fs.Bsize)
 }
 s.backend.reportLimits(report)
 if running && s.backend.name()=="native"{
  data,err:=os.ReadFile(fmt.Sprintf("/proc/%d/status",s.cmd.Process.Pid))
  if err==nil{
   for _,line:=range strings.Split(string(data),"\n"){
    fields:=strings.Fields(line)
    if len(fields)==3 && fields[0]=="VmRSS:" && fields[2]=="kB"{
     if n,e:=strconv.ParseUint(fields[1],10,64);e==nil{report["java_rss_bytes"]=n*1024}
    }
   }
  }
 }
 if s.backend.name()=="native"{if cpu,ok:=s.cpuPercent();ok{report["cpu_host_percent"]=cpu}}
 return report
}

func(s *supervisor) metrics()(bool,string){
 b,err:=json.Marshal(s.metricReport())
 if err!=nil{return false,"metrics serialization failed"}
 return true,string(b)
}
