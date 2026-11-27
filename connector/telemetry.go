package main

import (
 "errors"
 "fmt"
 "io"
 "os"
 "strconv"
 "strings"
 "time"
)

type cpuSample struct{pid int; process,total uint64}
func parseProcessTicks(data string)(uint64,error){
 end:=strings.LastIndex(data,")")
 if end<0{return 0,errors.New("invalid process stat")}
 f:=strings.Fields(data[end+1:])
 if len(f)<13{return 0,errors.New("short process stat")}
 u,e:=strconv.ParseUint(f[11],10,64);if e!=nil{return 0,e}
 s,e:=strconv.ParseUint(f[12],10,64);return u+s,e
}
func parseTotalTicks(data string)(uint64,error){
 f:=strings.Fields(strings.SplitN(data,"\n",2)[0])
 if len(f)<5 || f[0]!="cpu"{return 0,errors.New("invalid CPU stat")}
 var total uint64
 // guest and guest_nice are already included in user and nice.
 for i:=1;i<len(f)&&i<=8;i++{n,e:=strconv.ParseUint(f[i],10,64);if e!=nil{return 0,e};total+=n}
 return total,nil
}
func(s *supervisor) cpuPercent()(float64,bool){
 if !s.running(){s.cpu=cpuSample{};return 0,false}
 pid:=s.cmd.Process.Pid
 p,e:=os.ReadFile(fmt.Sprintf("/proc/%d/stat",pid));if e!=nil{return 0,false}
 h,e:=os.ReadFile("/proc/stat");if e!=nil{return 0,false}
 pt,e:=parseProcessTicks(string(p));if e!=nil{return 0,false}
 ht,e:=parseTotalTicks(string(h));if e!=nil{return 0,false}
 prev:=s.cpu;s.cpu=cpuSample{pid,pt,ht}
 if prev.pid!=pid || ht<=prev.total || pt<prev.process{return 0,false}
 return 100*float64(pt-prev.process)/float64(ht-prev.total),true
}

func validConsoleCommand(command string)bool{
 if len(command)<1 || len(command)>256{return false}
 for _,r:=range command{if r<32 || r==127{return false}}
 // Only ordinary Minecraft commands; no arbitrary plugin or shell commands.
 switch command{
 case "list","save-all","save-all flush","whitelist list","time query daytime","time query gametime":return true
 }
 return strings.HasPrefix(command,"say ") && len(strings.TrimSpace(command[4:]))>0
}
func(s *supervisor) command(command string)(bool,string){
 if !s.allowConsole{return false,"local console input opt-in required"}
 if !validConsoleCommand(command){return false,"unsupported Minecraft command"}
 if !s.running(){return false,"server process not running"}
 pipe,ok:=s.input.(*os.File)
 if !ok{return false,"console input unavailable"}
 if err:=pipe.SetWriteDeadline(time.Now().Add(2*time.Second));err!=nil{return false,"bounded console write unavailable"}
 defer pipe.SetWriteDeadline(time.Time{})
 if _,err:=io.WriteString(pipe,command+"\n");err!=nil{return false,"console write failed or timed out"}
 return true,"command written to game stdin; see console for response"
}
