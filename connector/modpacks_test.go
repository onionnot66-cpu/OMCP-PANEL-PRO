package main

import (
 "archive/zip"
 "bytes"
 "context"
 "crypto/sha512"
 "encoding/hex"
 "encoding/json"
 "io"
 "net/http"
 "os"
 "path/filepath"
 "strings"
 "testing"
)

func packFixture(t *testing.T,path string)[]byte{
 t.Helper()
 var b bytes.Buffer
 z:=zip.NewWriter(&b)
 w,e:=z.Create("modrinth.index.json");if e!=nil{t.Fatal(e)}
 raw:=map[string]any{"formatVersion":1,"game":"minecraft","name":"Fixture","versionId":"1","dependencies":map[string]string{"minecraft":"1.21.4","fabric-loader":"0.16.10"},"files":[]any{
  map[string]any{"path":path,"hashes":map[string]string{"sha512":strings.Repeat("a",128)},"env":map[string]string{"server":"required"},"downloads":[]string{"https://cdn.modrinth.com/mod.jar"},"fileSize":100},
  map[string]any{"path":"mods/client.jar","hashes":map[string]string{"sha512":strings.Repeat("b",128)},"env":map[string]string{"server":"unsupported"},"downloads":[]string{"https://cdn.modrinth.com/client.jar"},"fileSize":50},
 }}
 data,e:=json.Marshal(raw);if e!=nil{t.Fatal(e)}
 if _,e=w.Write(data);e!=nil{t.Fatal(e)}
 if e=z.Close();e!=nil{t.Fatal(e)}
 return b.Bytes()
}
func TestModpackStageWithoutInstallation(t *testing.T){
 root:=t.TempDir();id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa";game:=filepath.Join(root,id)
 if e:=os.Mkdir(game,0700);e!=nil{t.Fatal(e)}
 archive:=packFixture(t,"mods/server.jar")
 hash:=sha512.Sum512(archive);digest:=hex.EncodeToString(hash[:])
 url:="https://cdn.modrinth.com/data/ABCDEFGH/versions/IJKLMNOP/pack.mrpack"
 v:=modrinthVersion{ID:"IJKLMNOP",Project:"ABCDEFGH",Channel:"release",Status:"listed",Versions:[]string{"1.21.4"},Loaders:[]string{"fabric"},Files:[]modrinthFile{{URL:url,Filename:"pack.mrpack",Primary:true,Size:int64(len(archive)),Hashes:map[string]string{"sha512":digest}}}}
 meta,e:=json.Marshal(v);if e!=nil{t.Fatal(e)}
 old:=pluginClient;defer func(){pluginClient=old}()
 pluginClient=&http.Client{Transport:pluginFixtureTransport(func(r *http.Request)(*http.Response,error){
  var body []byte
  switch r.URL.String(){case "https://api.modrinth.com/v2/version/IJKLMNOP":body=meta;case url:body=archive;default:t.Fatalf("unexpected request %s",r.URL)}
  return &http.Response{StatusCode:200,Header:make(http.Header),Body:io.NopCloser(bytes.NewReader(body))},nil
 })}
 f:=&fleet{root:root,backend:"docker",servers:map[string]*supervisor{id:{dir:game,memory:128,backend:retireTestRuntime{"docker",true}}},ports:map[string]int{id:25565}}
 j:=job{Action:"instance.modpack_stage",Payload:jobPayload{InstanceID:id,Runtime:"docker",Memory:128,Port:25565,Version:"1.21.4",ModpackLoader:"fabric",PluginProject:"ABCDEFGH",PluginVersion:"IJKLMNOP",PluginSHA512:digest}}
 ctx:=context.Background()
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("opt-in bypass")}
 f.allowModpacks=true
 j.Payload.OwnerControl=true;if ok,_:=f.execute(ctx,j);ok{t.Fatal("owner stage allowed")};j.Payload.OwnerControl=false
 ok,msg:=f.execute(ctx,j);if !ok{t.Fatal(msg)}
 var report packReport;if e=json.Unmarshal([]byte(msg),&report);e!=nil{t.Fatal(e)}
 if report.State!="staged_not_installed"||report.Required!=1||report.ClientOnly!=1||report.ServerBytes!=100{t.Fatal(msg)}
 if b,e:=os.ReadFile(filepath.Join(root,".omcp-staged-pack-"+id+".mrpack"));e!=nil||!bytes.Equal(b,archive){t.Fatal("archive missing")}
 if files,e:=os.ReadDir(game);e!=nil||len(files)!=0{t.Fatal("game directory modified")}
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("stage overwrite allowed")}
 unsafe:=filepath.Join(root,"unsafe.mrpack")
 if e=os.WriteFile(unsafe,packFixture(t,"../escape"),0600);e!=nil{t.Fatal(e)}
 if _,e=inspectModpack(unsafe,"1.21.4","fabric",digest);e==nil{t.Fatal("unsafe index accepted")}
}
