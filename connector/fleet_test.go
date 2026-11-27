package main

import "testing"

func TestFleetManifest(t *testing.T){
 a:=fleetEntry{"aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa",1024,25565}
 b:=fleetEntry{"bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb",1024,25566}
 if err:=validateFleet([]fleetEntry{a,b},2048);err!=nil{t.Fatal(err)}
 if validateFleet([]fleetEntry{a,b},1024)==nil{t.Fatal("overcommit accepted")}
 if validateFleet([]fleetEntry{a,a},4096)==nil{t.Fatal("duplicate ID accepted")}
 b.Port=a.Port
 if validateFleet([]fleetEntry{a,b},4096)==nil{t.Fatal("duplicate port accepted")}
 a.ID="../secret"
 if validateFleet([]fleetEntry{a},4096)==nil{t.Fatal("invalid directory ID accepted")}
}
func TestFleetPort(t *testing.T){
 if !matchesFleetPort("# config\nserver-port=25565\nonline-mode=true\n",25565){t.Fatal("valid port refused")}
 for _,data:=range []string{"server-port=25566","server-port=25565\nserver-port=25566","server\\-port=25565","server-port:25565"}{
  if matchesFleetPort(data,25565){t.Errorf("invalid port config accepted: %q",data)}
 }
}
func TestFleetRejectsUnknownTarget(t *testing.T){
 f:=fleet{servers:map[string]*supervisor{},ports:map[string]int{}}
 if ok,_:=f.execute(job{Action:"instance.start",Payload:jobPayload{InstanceID:"unknown"}});ok{t.Fatal("unknown target accepted")}
}
