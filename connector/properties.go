package main

import (
 "errors"
 "flag"
 "fmt"
 "os"
 "path/filepath"
 "strconv"
 "strings"
)

func validProperty(key,value string)bool{
 switch key{
 case "difficulty":return value=="peaceful"||value=="easy"||value=="normal"||value=="hard"
 case "gamemode":return value=="survival"||value=="creative"||value=="adventure"||value=="spectator"
 case "pvp","white-list","allow-flight","spawn-monsters","hardcore":return value=="true"||value=="false"
 case "max-players","view-distance","simulation-distance":
  n,err:=strconv.Atoi(value)
  if err!=nil{return false}
  if key=="max-players"{return n>=1 && n<=1000}
  return n>=2 && n<=32
 default:return false
 }
}

// Offline editor for a restricted set of ordinary game settings.
func editProperties(args []string)error{
 flags:=flag.NewFlagSet("properties",flag.ContinueOnError)
 dir:=flags.String("dir","","trusted Minecraft server directory")
 key:=flags.String("key","","supported game property")
 value:=flags.String("value","","new property value")
 if err:=flags.Parse(args);err!=nil{return err}
 if flags.NArg()!=0 || *dir=="" || !validProperty(*key,*value){return errors.New("invalid or unsupported game property")}
 s,err:=newSupervisor(*dir,1024)
 if err!=nil{return fmt.Errorf("stop the connector and game first: %w",err)}
 defer s.close()
 path:=filepath.Join(s.dir,"server.properties")
 info,err:=os.Lstat(path)
 if err!=nil || !info.Mode().IsRegular() || info.Size()>256*1024{return errors.New("missing or invalid server.properties")}
 data,err:=os.ReadFile(path)
 if err!=nil{return err}
 updated,err:=replaceProperty(string(data),*key,*value)
 if err!=nil{return err}
 tmp,err:=os.CreateTemp(s.dir,".omcp-properties-*")
 if err!=nil{return err}
 defer os.Remove(tmp.Name())
 if _,err=tmp.WriteString(updated);err!=nil{tmp.Close();return err}
 if err=tmp.Sync();err!=nil{tmp.Close();return err}
 if err=tmp.Close();err!=nil{return err}
 if err=os.Rename(tmp.Name(),path);err!=nil{return err}
 fmt.Printf("Updated %s. Changes apply on next Minecraft start.\n",*key)
 return nil
}

func replaceProperty(data,key,value string)(string,error){
 if !validProperty(key,value){return "",errors.New("invalid property")}
 lines:=strings.Split(strings.ReplaceAll(data,"\r\n","\n"),"\n")
 found:=false
 for i,line:=range lines{
  t:=strings.TrimSpace(line)
  if t==""||strings.HasPrefix(t,"#")||strings.HasPrefix(t,"!"){continue}
  // Refuse unusual Java Properties syntax rather than silently changing its meaning.
  trailing:=len(line)-len(strings.TrimRight(line,"\\"))
  if trailing%2!=0{return "",errors.New("continued properties require manual editing")}
  k,_,ok:=strings.Cut(t,"=")
  if !ok || k=="" || strings.ContainsAny(k,"\\: \t\r\n"){return "",errors.New("noncanonical property syntax; use manual editing")}
  if k==key{
   if found{return "",errors.New("duplicate property; use manual editing")}
   lines[i]=key+"="+value
   found=true
  }
 }
 if !found{lines=append(lines,key+"="+value)}
 return strings.TrimRight(strings.Join(lines,"\n"),"\n")+"\n",nil
}
