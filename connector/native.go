package main

import (
 "errors"
 "flag"
 "fmt"
 "os"
 "os/exec"
 "os/signal"
 "path/filepath"
 "syscall"
 "time"
)

// Local, single-tenant foreground runtime. Never execute shell strings from a panel.
func nativeRun(args []string) error {
 flags := flag.NewFlagSet("native", flag.ContinueOnError)
 dir := flags.String("dir", "", "existing Minecraft server directory")
 memory := flags.Int("memory", 1024, "Java heap limit in MiB (not a hard OS limit)")
 if err := flags.Parse(args); err != nil { return err }
 if flags.NArg()!=0 || *dir=="" || *memory<128 || *memory>1048576 { return errors.New("provide --dir and valid --memory") }
 if os.Geteuid()==0 { return errors.New("native mode must run as a non-root, dedicated game user") }
 resolved, err := filepath.EvalSymlinks(*dir)
 if err != nil { return err }
 resolved, err = filepath.Abs(resolved)
 if err != nil { return err }
 jar := filepath.Join(resolved,"server.jar")
 info, err := os.Lstat(jar)
 if err!=nil || !info.Mode().IsRegular() { return errors.New("server.jar must be an existing regular file") }
 java, err := exec.LookPath("java")
 if err != nil { return err }
 cmd := exec.Command(java,fmt.Sprintf("-Xmx%dM",*memory),"-jar","server.jar","nogui")
 cmd.Dir=resolved
 // Avoid inheriting panel or connector secrets in the game process.
 cmd.Env=[]string{"PATH=/usr/local/bin:/usr/bin:/bin","HOME="+resolved,"LANG=C.UTF-8"}
 cmd.SysProcAttr=&syscall.SysProcAttr{Setpgid:true}
 cmd.Stdout=os.Stdout
 cmd.Stderr=os.Stderr
 input, err := cmd.StdinPipe()
 if err != nil { return err }
 signals := make(chan os.Signal,1)
 signal.Notify(signals,os.Interrupt,syscall.SIGTERM)
 defer signal.Stop(signals)
 if err := cmd.Start(); err != nil { return err }
 done := make(chan error,1)
 go func(){done<-cmd.Wait()}()
 select {
 case err:=<-done: return err
 case <-signals:
  _,_ = input.Write([]byte("stop\n"))
  _ = input.Close()
 }
 select {
 case err:=<-done: return err
 case <-time.After(30*time.Second):
  _=syscall.Kill(-cmd.Process.Pid,syscall.SIGTERM)
 }
 select {
 case err:=<-done: return err
 case <-time.After(5*time.Second):
  _=syscall.Kill(-cmd.Process.Pid,syscall.SIGKILL)
  return <-done
 }
}
