package main

import (
 "bytes"
 "context"
 "encoding/json"
 "errors"
 "flag"
 "fmt"
 "io"
 "net"
 "os"
 "os/exec"
 "path/filepath"
 "regexp"
 "strings"
 "time"
)

type tunnelConfig struct {
 Gateway string `json:"gateway"`
 GatewayPort int `json:"gateway_port"`
 User string `json:"user"`
 IdentityFile string `json:"identity_file"`
 KnownHostsFile string `json:"known_hosts_file"`
 LocalPort int `json:"local_port"`
 RemotePort int `json:"remote_port"`
 PublicBind bool `json:"public_bind"`
}
var sshUsername=regexp.MustCompile("^[a-zA-Z_][a-zA-Z0-9_-]{0,31}$")
var sshHostname=regexp.MustCompile("^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$")
var sshFilePath=regexp.MustCompile("^/[a-zA-Z0-9_./+-]{1,511}$")

func(t tunnelConfig) validate()error{
 if (net.ParseIP(t.Gateway)==nil&&!sshHostname.MatchString(t.Gateway))||strings.HasPrefix(t.Gateway,"-")||
 !sshUsername.MatchString(t.User)||t.GatewayPort<1||t.GatewayPort>65535||
 t.LocalPort<1024||t.LocalPort>65535||t.RemotePort<1024||t.RemotePort>65535{
  return errors.New("invalid SSH gateway, user or port; use a dedicated unprivileged account")
 }
 for _,p:=range []string{t.IdentityFile,t.KnownHostsFile}{
  if !sshFilePath.MatchString(p)||filepath.Clean(p)!=p{return errors.New("SSH files must use absolute canonical paths without whitespace or expansion characters")}
  b,e:=readPrivateFile(p,128*1024);if e!=nil||len(b)==0{return errors.New("SSH key and pinned known_hosts must be nonempty private files owned by this user")}
 }
 return nil
}
func(t tunnelConfig) args()[]string{
 bind:="127.0.0.1";if t.PublicBind{bind="0.0.0.0"}
 return []string{
  "-F","/dev/null","-N","-T",
  "-o","BatchMode=yes","-o","StrictHostKeyChecking=yes","-o","UpdateHostKeys=no",
  "-o","UserKnownHostsFile="+t.KnownHostsFile,"-o","GlobalKnownHostsFile=/dev/null",
  "-o","IdentitiesOnly=yes","-o","IdentityAgent=none","-o","ForwardAgent=no",
  "-o","PasswordAuthentication=no","-o","KbdInteractiveAuthentication=no",
  "-o","ExitOnForwardFailure=yes","-o","ServerAliveInterval=15","-o","ServerAliveCountMax=3",
  "-o","ConnectTimeout=10","-o","ConnectionAttempts=1","-o","ControlMaster=no",
  "-o","PermitLocalCommand=no","-o","ProxyCommand=none","-o","ProxyJump=none",
  "-p",fmt.Sprint(t.GatewayPort),"-l",t.User,"-i",t.IdentityFile,
  "-R",fmt.Sprintf("%s:%d:127.0.0.1:%d",bind,t.RemotePort,t.LocalPort),
  t.Gateway,
 }
}
func readTunnelConfig(path string)(tunnelConfig,error){
 var t tunnelConfig
 b,e:=readPrivateFile(path,8192);if e!=nil{return t,e}
 d:=json.NewDecoder(bytes.NewReader(b));d.DisallowUnknownFields()
 if e=d.Decode(&t);e!=nil{return t,e}
 var extra any
 if e=d.Decode(&extra);e!=io.EOF{return t,errors.New("unexpected trailing configuration")}
 return t,t.validate()
}
type tunnelRunner func(context.Context)error
func superviseTunnel(ctx context.Context,run tunnelRunner,wait func(context.Context,time.Duration)error)error{
 delay:=time.Second
 for ctx.Err()==nil{
  start:=time.Now()
  e:=run(ctx)
  if ctx.Err()!=nil{return nil}
  if e!=nil{fmt.Fprintln(os.Stderr,"SSH tunnel disconnected; retrying:",e)}else{fmt.Fprintln(os.Stderr,"SSH tunnel exited; retrying")}
  if time.Since(start)>time.Minute{delay=time.Second}
  if e=wait(ctx,delay);e!=nil{if ctx.Err()!=nil{return nil};return e}
  if delay<30*time.Second{delay*=2;if delay>30*time.Second{delay=30*time.Second}}
 }
 return nil
}
func tunnelWait(ctx context.Context,d time.Duration)error{
 t:=time.NewTimer(d);defer t.Stop()
 select{case <-ctx.Done():return ctx.Err();case <-t.C:return nil}
}
func runTunnel(ctx context.Context,args []string)error{
 flags:=flag.NewFlagSet("tunnel",flag.ContinueOnError)
 path:=flags.String("config","","private SSH tunnel JSON configuration")
 check:=flags.Bool("check",false,"validate configuration without connecting")
 if e:=flags.Parse(args);e!=nil{return e}
 if flags.NArg()!=0||*path==""||os.Geteuid()==0{return errors.New("provide a private config and run as a dedicated non-root user")}
 t,e:=readTunnelConfig(*path);if e!=nil{return e}
 ssh,e:=exec.LookPath("ssh");if e!=nil{return errors.New("OpenSSH client required")}
 if *check{fmt.Println("Tunnel configuration valid; gateway access and public reachability are not verified.");return nil}
 if t.PublicBind{fmt.Fprintln(os.Stderr,"Public gateway binding requested; ensure gateway firewall and forwarding policy allow only the authorized game port.")}
 // Foreground SSH is the only subprocess; no shell, arbitrary remote command,
 // agent, local forwarding, or provider credentials supplied by the panel.
 return superviseTunnel(ctx,func(ctx context.Context)error{
  if e:=t.validate();e!=nil{return e}
  cmd:=exec.CommandContext(ctx,ssh,t.args()...)
  cmd.Env=[]string{"PATH=/usr/local/bin:/usr/bin:/bin","LANG=C.UTF-8"}
  cmd.Stdout=os.Stdout;cmd.Stderr=os.Stderr
  return cmd.Run()
 },tunnelWait)
}
