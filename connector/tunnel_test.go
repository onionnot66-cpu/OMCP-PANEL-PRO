package main

import (
 "context"
 "errors"
 "os"
 "path/filepath"
 "strings"
 "testing"
 "time"
)

func TestReverseTunnelArguments(t *testing.T){
 root:=t.TempDir()
 key,hosts:=filepath.Join(root,"key"),filepath.Join(root,"known_hosts")
 for _,p:=range []string{key,hosts}{if e:=os.WriteFile(p,[]byte("fixture"),0600);e!=nil{t.Fatal(e)}}
 cfg:=tunnelConfig{Gateway:"gateway.example.com",GatewayPort:22,User:"omcp_forward",IdentityFile:key,KnownHostsFile:hosts,LocalPort:25565,RemotePort:25570}
 if e:=cfg.validate();e!=nil{t.Fatal(e)}
 a:=strings.Join(cfg.args()," ")
 for _,expected:=range []string{"-F /dev/null","-N -T","StrictHostKeyChecking=yes","IdentityAgent=none","ExitOnForwardFailure=yes","-R 127.0.0.1:25570:127.0.0.1:25565"}{
  if !strings.Contains(a,expected){t.Fatalf("missing %s",expected)}
 }
 cfg.PublicBind=true
 if !strings.Contains(strings.Join(cfg.args()," "),"-R 0.0.0.0:25570:127.0.0.1:25565"){t.Fatal("public bind not explicit")}
 for _,host:=range []string{"-oProxyCommand=x","host;id","https://host","user@host"}{cfg.Gateway=host;if cfg.validate()==nil{t.Fatalf("accepted %s",host)}}
 cfg.Gateway="gateway.example.com";cfg.LocalPort=22
 if cfg.validate()==nil{t.Fatal("privileged local port allowed")}
 cfg.LocalPort=25565;cfg.IdentityFile=key+"%h"
 if cfg.validate()==nil{t.Fatal("SSH token expansion allowed")}
}
func TestTunnelReconnectAndCancellation(t *testing.T){
 ctx,cancel:=context.WithCancel(context.Background());defer cancel()
 attempts:=0;var delays []time.Duration
 e:=superviseTunnel(ctx,func(context.Context)error{attempts++;if attempts==4{cancel()};return errors.New("fixture disconnect")},func(_ context.Context,d time.Duration)error{delays=append(delays,d);return nil})
 if e!=nil||attempts!=4||len(delays)!=3||delays[0]!=time.Second||delays[1]!=2*time.Second||delays[2]!=4*time.Second{t.Fatalf("attempts=%d delays=%v err=%v",attempts,delays,e)}
}
