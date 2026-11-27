package main

import (
 "errors"
 "fmt"
 "io"
 "os"
 "os/exec"
 "path/filepath"
 "syscall"
 "time"
)

// Opt-in single trusted server. This is NOT a tenant isolation boundary.
type supervisor struct {
 dir string
 memory int
 lock *os.File
 cmd *exec.Cmd
 input io.WriteCloser
 done chan error
 console consoleBuffer
 provision bool
 allowConsole bool
 cpu cpuSample
 container *containerRuntime
}

func newSupervisor(dir string,memory int)(*supervisor,error){return openSupervisor(dir,memory,false)}

func openSupervisor(dir string,memory int,provision bool)(*supervisor,error){
 if os.Geteuid()==0 {return nil,errors.New("trusted runtime requires a non-root account")}
 if memory<128 || memory>1048576 {return nil,errors.New("invalid Java heap")}
 resolved,err:=filepath.EvalSymlinks(dir)
 if err!=nil && provision && os.IsNotExist(err){
  abs,e:=filepath.Abs(dir);if e!=nil{return nil,e}
  parent,e:=filepath.EvalSymlinks(filepath.Dir(abs));if e!=nil{return nil,e}
  resolved=filepath.Join(parent,filepath.Base(abs));err=nil
  if _,e=os.Lstat(resolved);!os.IsNotExist(e){return nil,errors.New("provision target must not be a symlink or existing object")}
 }
 if err!=nil{return nil,err}
 resolved,err=filepath.Abs(resolved)
 if err!=nil{return nil,err}
 info,err:=os.Stat(resolved)
 if err!=nil && !(provision && os.IsNotExist(err)){return nil,err}
 if err==nil && !info.IsDir(){return nil,errors.New("trusted server directory missing")}
 home,err:=os.UserHomeDir()
 if err!=nil{return nil,err}
 lockdir:=filepath.Join(home,".omcp-connect")
 if err=os.MkdirAll(lockdir,0700);err!=nil{return nil,err}
 lock,err:=os.OpenFile(filepath.Join(lockdir,"native.lock"),os.O_CREATE|os.O_RDWR,0600)
 if err!=nil{return nil,err}
 if err=syscall.Flock(int(lock.Fd()),syscall.LOCK_EX|syscall.LOCK_NB);err!=nil{lock.Close();return nil,errors.New("another native supervisor is active")}
 return &supervisor{dir:resolved,memory:memory,lock:lock,provision:provision},nil
}

func(s *supervisor) running()bool{
 if s.cmd==nil{return false}
 select{
 case <-s.done:s.cmd=nil;s.input=nil;return false
 default:return true
 }
}

func(s *supervisor) start()error{
 if s.running(){return nil}
 jar:=filepath.Join(s.dir,"server.jar")
 info,err:=os.Lstat(jar)
 if err!=nil || !info.Mode().IsRegular(){return errors.New("trusted server.jar missing")}
 var cmd *exec.Cmd
 if s.container!=nil{
  if err:=s.container.requireAbsent();err!=nil{return err}
  cmd=exec.Command(s.container.binary,s.container.args(s.dir,s.memory)...)
  // Podman needs the local rootless runtime environment; only HOME is passed into the container explicitly.
 }else{
  java,err:=exec.LookPath("java")
  if err!=nil{return err}
  cmd=exec.Command(java,fmt.Sprintf("-Xmx%dM",s.memory),"-jar","server.jar","nogui")
  cmd.Dir=s.dir
  cmd.Env=[]string{"PATH=/usr/local/bin:/usr/bin:/bin","HOME="+s.dir,"LANG=C.UTF-8"}
 }
 cmd.SysProcAttr=&syscall.SysProcAttr{Pdeathsig:syscall.SIGKILL}
 s.console.reset()
 s.cpu=cpuSample{}
 cmd.Stdout=io.MultiWriter(os.Stdout,&s.console)
 cmd.Stderr=io.MultiWriter(os.Stderr,&s.console)
 input,err:=cmd.StdinPipe()
 if err!=nil{return err}
 if err=cmd.Start();err!=nil{input.Close();return err}
 done:=make(chan error,1)
 s.cmd=cmd;s.input=input;s.done=done
 go func(){done<-cmd.Wait()}()
 return nil
}

func(s *supervisor) stop(force bool)error{
 if !s.running(){if s.container!=nil{return s.container.requireAbsent()};return nil}
 if !force{
  // Do not block indefinitely if the Java process stops consuming stdin.
  input:=s.input
  go func(){_,_=io.WriteString(input,"stop\n");_=input.Close()}()
  select{
  case <-s.done:s.cmd=nil;s.input=nil;if s.container!=nil{return s.container.requireAbsent()};return nil
  case <-time.After(25*time.Second):
  }
 }
 if s.container!=nil{if err:=s.container.kill();err!=nil{return err}}
 if err:=s.cmd.Process.Kill();err!=nil && !errors.Is(err,os.ErrProcessDone){return err}
 select{
 case <-s.done:s.cmd=nil;s.input=nil;return nil
 case <-time.After(5*time.Second):return errors.New("process exit not confirmed")
 }
}

func(s *supervisor) close(){_ = s.stop(false);_ = s.lock.Close()}

func(s *supervisor) execute(action string)(bool,string){
 var err error
 switch action{
 case "native.start":err=s.start()
 case "native.stop":err=s.stop(false)
 case "native.kill":err=s.stop(true)
 case "native.restart":if err=s.stop(false);err==nil{err=s.start()}
 case "native.console":return true,s.console.snapshot()
 case "native.status":
 default:return false,"unsupported native action"
 }
 if err!=nil{return false,err.Error()}
 if s.running(){if s.container!=nil{return true,"container attachment running (game readiness not verified)"};return true,"process running (game readiness not verified)"}
 if s.container!=nil{if err:=s.container.requireAbsent();err!=nil{return false,err.Error()}}
 return true,"process stopped"
}
