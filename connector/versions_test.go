package main

import (
 "bytes"
 "context"
 "crypto/sha256"
 "encoding/hex"
 "encoding/json"
 "io"
 "net/http"
 "os"
 "path/filepath"
 "strings"
 "testing"
)

func TestVersionUpgradeRetainsOriginalAndGuardsInterruptedChanges(t *testing.T){
 ctx:=context.Background()
 root:=t.TempDir()
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 dir:=filepath.Join(root,id)
 if e:=os.MkdirAll(filepath.Join(dir,"world"),0700);e!=nil{t.Fatal(e)}
 oldJar:=[]byte("old fixture JAR")
 newJar:=[]byte("new fixture JAR")
 oldHash:=sha256.Sum256(oldJar);newHash:=sha256.Sum256(newJar)
 old:=softwareReceipt{Software:"paper",Version:"1.21.4",Build:"1",SHA256:hex.EncodeToString(oldHash[:])}
 b,e:=json.Marshal(old);if e!=nil{t.Fatal(e)}
 for name,content:=range map[string][]byte{"server.jar":oldJar,"omcp-software.json":b,"world/level.dat":[]byte("preserve world")}{
  if e=os.WriteFile(filepath.Join(dir,name),content,0600);e!=nil{t.Fatal(e)}
 }
 url:="https://fill-data.papermc.io/v1/objects/fixture.jar"
 metadata:=[]byte("[{\"id\":2,\"channel\":\"STABLE\",\"downloads\":{\"server:default\":{\"url\":\""+url+"\",\"size\":15,\"checksums\":{\"sha256\":\""+hex.EncodeToString(newHash[:])+"\"}}}}]")
 oldClient:=downloadClient;defer func(){downloadClient=oldClient}()
 downloadClient=&http.Client{Transport:pluginFixtureTransport(func(r *http.Request)(*http.Response,error){
  var payload []byte
  switch r.URL.String(){case "https://fill.papermc.io/v3/projects/paper/versions/1.21.5/builds":payload=metadata;case url:payload=newJar;default:t.Fatalf("unexpected provider %s",r.URL)}
  return &http.Response{StatusCode:200,Header:make(http.Header),Body:io.NopCloser(bytes.NewReader(payload))},nil
 })}
 s:=&supervisor{dir:dir,memory:128,backend:retireTestRuntime{"docker",true}}
 f:=&fleet{root:root,backend:"docker",servers:map[string]*supervisor{id:s},ports:map[string]int{id:25565}}
 j:=job{ID:"bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb",Action:"instance.version_change",Payload:jobPayload{InstanceID:id,Memory:128,Port:25565,Runtime:"docker",Software:"paper",Version:"1.21.4",TargetVersion:"1.21.5",AcceptEULA:true}}
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("opt-in bypass")}
 f.allowVersions=true
 j.Payload.OwnerControl=true;if ok,_:=f.execute(ctx,j);ok{t.Fatal("owner upgrade accepted")};j.Payload.OwnerControl=false
 s.backend=retireTestRuntime{"docker",false};if ok,_:=f.execute(ctx,j);ok{t.Fatal("existing container accepted")};s.backend=retireTestRuntime{"docker",true}
 j.Payload.TargetVersion="1.20.4";if ok,_:=f.execute(ctx,j);ok{t.Fatal("downgrade accepted")};j.Payload.TargetVersion="1.21.5"
 if ok,msg:=f.execute(ctx,j);!ok||msg!="version:paper:1.21.5:"+hex.EncodeToString(newHash[:]){t.Fatal(msg)}
 if data,e:=os.ReadFile(filepath.Join(dir,"server.jar"));e!=nil||!bytes.Equal(data,newJar){t.Fatal("new jar missing")}
 if data,e:=os.ReadFile(filepath.Join(root,".omcp-previous-version-"+id,"server.jar"));e!=nil||!bytes.Equal(data,oldJar){t.Fatal("old jar missing")}
 if data,e:=os.ReadFile(filepath.Join(dir,"world","level.dat"));e!=nil||string(data)!="preserve world"{t.Fatal("world changed")}
 if e=os.WriteFile(f.versionMarker(id),[]byte(j.ID+"\n"),0600);e!=nil{t.Fatal(e)}
 j.Action="instance.start";if ok,_:=f.execute(ctx,j);ok{t.Fatal("pending guard bypass")}
 j.Action="instance.version_inspect"
 if e=os.WriteFile(filepath.Join(dir,"server.jar"),[]byte("inconsistent"),0600);e!=nil{t.Fatal(e)}
 if ok,_:=f.execute(ctx,j);ok||!f.versionBlocked(id){t.Fatal("inconsistent pair accepted")}
 if e=os.WriteFile(filepath.Join(dir,"server.jar"),newJar,0600);e!=nil{t.Fatal(e)}
 if ok,msg:=f.execute(ctx,j);!ok||!strings.HasPrefix(msg,"version:paper:1.21.5:")||f.versionBlocked(id){t.Fatal(msg)}
}

func TestNewerVersion(t *testing.T){
 for _,tc:=range []struct{a,b string;ok bool}{{"1.21.4","1.21.5",true},{"1.9","1.10",true},{"1.21","1.21.0",false},{"1.21.4","1.20.1",false},{"1.21","1.021.1",false},{"1.21","1.4294967296",false}}{
  if newerVersion(tc.a,tc.b)!=tc.ok{t.Errorf("unexpected version ordering %s %s",tc.a,tc.b)}
 }
}
