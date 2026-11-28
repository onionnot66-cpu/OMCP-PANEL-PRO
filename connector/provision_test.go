package main

import (
 "context"
 "os"
 "path/filepath"
 "strings"
 "testing"
)

func TestProvisionRequiresOptInAndNewDirectory(t *testing.T){
 dir:=filepath.Join(t.TempDir(),"server")
 s:=supervisor{dir:dir,memory:1024}
 j:=job{Action:"native.install",Payload:jobPayload{Software:"paper",Version:"1.21.4",Port:25565,AcceptEULA:true}}
 if ok,_:=s.executeJob(context.Background(),j);ok{t.Fatal("provisioning must be opt-in")}
 s.provision=true
 if err:=os.Mkdir(dir,0700);err!=nil{t.Fatal(err)}
 if ok,msg:=s.executeJob(context.Background(),j);ok || !strings.Contains(msg,"already exists"){t.Fatal("existing directory must be preserved")}
 s.dir=filepath.Join(t.TempDir(),"missing")
 j.Payload.AcceptEULA=false
 if ok,msg:=s.executeJob(context.Background(),j);ok || !strings.Contains(msg,"EULA"){t.Fatal("EULA must be explicit")}
}
