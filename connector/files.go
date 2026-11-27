package main

import (
 "crypto/sha256"
 "encoding/base64"
 "encoding/hex"
 "encoding/json"
 "errors"
 "fmt"
 "io"
 "os"
 "regexp"
 "syscall"
 "unicode/utf8"
)

const fileLimit=2048
var noteName=regexp.MustCompile("^notes-[A-Za-z0-9_-]{1,48}\\.txt$")
func managedName(name string)bool{
 switch name{
 case "bukkit.yml","spigot.yml","commands.yml","paper.yml","server.properties":return true
 }
 return noteName.MatchString(name)
}
func openGameRoot(dir string)(*os.File,error){
 fd,err:=syscall.Open(dir,syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC,0)
 if err!=nil{return nil,err}
 return os.NewFile(uintptr(fd),dir),nil
}
func readGameFile(root *os.File,name string)([]byte,error){
 if !managedName(name){return nil,errors.New("unsupported file name")}
 fd,err:=syscall.Openat(int(root.Fd()),name,syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK|syscall.O_CLOEXEC,0)
 if err!=nil{return nil,err}
 file:=os.NewFile(uintptr(fd),name);defer file.Close()
 var st syscall.Stat_t
 if err=syscall.Fstat(fd,&st);err!=nil{return nil,err}
 if st.Mode&syscall.S_IFMT!=syscall.S_IFREG||st.Nlink!=1||st.Size>fileLimit{return nil,errors.New("only small regular unlinked text files supported")}
 b,err:=io.ReadAll(io.LimitReader(file,fileLimit+1))
 if err!=nil{return nil,err}
 if len(b)>fileLimit||!utf8.Valid(b){return nil,errors.New("file exceeds limit or is not UTF-8")}
 return b,nil
}
func digestFile(b []byte)string{h:=sha256.Sum256(b);return hex.EncodeToString(h[:])}
func writeGameFile(root *os.File,name string,b []byte,expected string)error{
 if !managedName(name)||name=="server.properties"{return errors.New("raw write is not permitted for this file")}
 if len(b)>fileLimit||!utf8.Valid(b){return errors.New("invalid UTF-8 or text exceeds limit")}
 old,err:=readGameFile(root,name)
 if expected=="new"{
  if !noteName.MatchString(name)||!os.IsNotExist(err){return errors.New("only new notes files may be created")}
  fd,err:=syscall.Openat(int(root.Fd()),name,syscall.O_WRONLY|syscall.O_CREAT|syscall.O_EXCL|syscall.O_NOFOLLOW|syscall.O_CLOEXEC,0600)
  if err!=nil{return err}
  f:=os.NewFile(uintptr(fd),name)
  _,werr:=f.Write(b);cerr:=f.Close()
  if werr!=nil||cerr!=nil{_ = syscall.Unlinkat(int(root.Fd()),name);return errors.New("file creation failed")}
  return nil
 }
 if err!=nil{return err}
 if digestFile(old)!=expected{return errors.New("file changed; read it again")}
 return replaceGameFile(root,name,b)
}
func replaceGameFile(root *os.File,name string,b []byte)error{
 // The descriptor pins the offline directory. Atomic rename replaces rather than follows a symlink.
 // Trusted operators must not concurrently edit these files.
 tmp,err:=os.CreateTemp(fmt.Sprintf("/proc/self/fd/%d",root.Fd()),".omcp-edit-")
 if err!=nil{return err}
 path:=tmp.Name()
 defer os.Remove(path)
 if _,err=tmp.Write(b);err!=nil{tmp.Close();return err}
 if err=tmp.Sync();err!=nil{tmp.Close();return err}
 if err=tmp.Close();err!=nil{return err}
 base:=path[len(fmt.Sprintf("/proc/self/fd/%d/",root.Fd())):]
 return syscall.Renameat(int(root.Fd()),base,int(root.Fd()),name)
}
func(s *supervisor) fileJob(j job)(bool,string){
 if !s.allowFiles{return false,"local file manager opt-in required"}
 if s.running(){return false,"stop the instance before file operations"}
 if s.container!=nil{if err:=s.container.requireAbsent();err!=nil{return false,err.Error()}}
 root,err:=openGameRoot(s.dir)
 if err!=nil{return false,err.Error()}
 defer root.Close()
 p:=j.Payload
 var result any
 switch j.Action{
 case "instance.files":
  entries,e:=root.Readdirnames(256)
  if e!=nil&&e!=io.EOF{return false,e.Error()}
  names:=[]string{}
  for _,name:=range entries{
   if managedName(name){if _,e:=readGameFile(root,name);e==nil{names=append(names,name)}}
   if len(names)>=32{break}
  }
  result=map[string]any{"files":names,"limited":len(entries)>=256||len(names)>=32}
 case "instance.file_read":
  if p.File=="server.properties"{return false,"use structured properties editor; raw file may contain secrets"}
  b,e:=readGameFile(root,p.File);if e!=nil{return false,e.Error()}
  result=map[string]any{"file":p.File,"content_base64":base64.StdEncoding.EncodeToString(b),"sha256":digestFile(b)}
 case "instance.file_write":
  b,e:=base64.StdEncoding.DecodeString(p.Content)
  if e!=nil{return false,"invalid text encoding"}
  if e=writeGameFile(root,p.File,b,p.Expected);e!=nil{return false,e.Error()}
  result=map[string]any{"saved":p.File,"sha256":digestFile(b)}
 case "instance.file_delete":
  if !noteName.MatchString(p.File){return false,"only notes files may be deleted"}
  b,e:=readGameFile(root,p.File);if e!=nil{return false,e.Error()}
  if digestFile(b)!=p.Expected{return false,"file changed; read it again"}
  if e=syscall.Unlinkat(int(root.Fd()),p.File);e!=nil{return false,e.Error()}
  result=map[string]any{"deleted":p.File}
 case "instance.property":
  b,e:=readGameFile(root,"server.properties");if e!=nil{return false,e.Error()}
  updated,e:=replaceProperty(string(b),p.Key,p.Value);if e!=nil{return false,e.Error()}
  if len(updated)>fileLimit{return false,"properties exceeds limit"}
  if e=replaceGameFile(root,"server.properties",[]byte(updated));e!=nil{return false,e.Error()}
  result=map[string]any{"updated":p.Key}
 default:return false,"unsupported file action"
 }
 b,err:=json.Marshal(result)
 if err!=nil||len(b)>4096{return false,"file response exceeds limit"}
 return true,string(b)
}
