package main

import (
 "context"
 "errors"
 "os"
 "os/exec"
 "path/filepath"
 "testing"
)

type retireTestRuntime struct{backend string; absent bool}
func(r retireTestRuntime) name()string{return r.backend}
func(r retireTestRuntime) command(string,int)(*exec.Cmd,error){return nil,errors.New("not a real runtime")}
func(r retireTestRuntime) requireAbsent()error{if !r.absent{return errors.New("container still present")};return nil}
func(r retireTestRuntime) kill()error{return errors.New("retirement must never force kill")}
func(r retireTestRuntime) reportLimits(map[string]any){}

func TestRetirementMarkerAndRetry(t *testing.T){
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 root:=t.TempDir()
 dir:=filepath.Join(root,id)
 if err:=os.Mkdir(dir,0700);err!=nil{t.Fatal(err)}
 if err:=os.WriteFile(filepath.Join(dir,"world.dat"),[]byte("keep"),0600);err!=nil{t.Fatal(err)}
 s:=&supervisor{dir:dir,memory:128,backend:retireTestRuntime{"docker",false}}
 f:=&fleet{root:root,backend:"docker",servers:map[string]*supervisor{id:s},ports:map[string]int{id:25565}}
 j:=job{Action:"instance.retire",Payload:jobPayload{InstanceID:id,Runtime:"docker",Memory:128,Port:25565}}
 if ok,_:=f.execute(context.Background(),j);ok{t.Fatal("existing container retired")}
 if retired,err:=f.retired(id);retired||err!=nil{t.Fatalf("unexpected marker: %t %v",retired,err)}
 s.backend=retireTestRuntime{"docker",true}
 ctx,cancel:=context.WithCancel(context.Background());cancel()
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("cancelled retirement succeeded")}
 for i:=0;i<2;i++{if ok,msg:=f.execute(context.Background(),j);!ok||msg!=retirementReceipt(id){t.Fatalf("retirement failed: %s",msg)}}
 j.Action="instance.start"
 if ok,_:=f.execute(context.Background(),j);ok{t.Fatal("retired UUID started")}
 if b,err:=os.ReadFile(filepath.Join(dir,"world.dat"));err!=nil||string(b)!="keep"{t.Fatal("retirement changed world")}
 path,_:=f.retirementMarker(id)
 if err:=os.WriteFile(path,[]byte("corrupt"),0600);err!=nil{t.Fatal(err)}
 if ok,_:=f.execute(context.Background(),j);ok{t.Fatal("corrupt marker allowed start")}
 if err:=os.Remove(path);err!=nil{t.Fatal(err)}
 if err:=os.Symlink(filepath.Join(dir,"world.dat"),path);err!=nil{t.Fatal(err)}
 if ok,_:=f.execute(context.Background(),j);ok{t.Fatal("symlink marker allowed start")}
}
func TestNativeRetirementRefused(t *testing.T){
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 f:=&fleet{root:t.TempDir(),backend:"native"}
 if ok,_:=f.retire(context.Background(),id,&supervisor{backend:nativeRuntime{}});ok{t.Fatal("native retirement allowed")}
}
