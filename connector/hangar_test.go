package main

import (
 "archive/zip"
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

func TestHangarReviewedArtifactLifecycle(t *testing.T){
 var jar bytes.Buffer
 z:=zip.NewWriter(&jar)
 w,e:=z.Create("plugin.yml");if e!=nil{t.Fatal(e)}
 if _,e=w.Write([]byte("name: HangarFixture\n"));e!=nil{t.Fatal(e)}
 if e=z.Close();e!=nil{t.Fatal(e)}
 hash:=sha256.Sum256(jar.Bytes());digest:=hex.EncodeToString(hash[:])
 raw:=map[string]any{"id":2,"projectId":1,"visibility":"public","reviewState":"reviewed","channel":map[string]any{"name":"Release","flags":[]string{}},"platformDependencies":map[string][]string{"PAPER":{"1.21.4"}},"pluginDependencies":map[string]any{"PAPER":[]any{}},"downloads":map[string]any{"PAPER":map[string]any{"externalUrl":nil,"downloadUrl":"https://hangarcdn.papermc.io/plugins/test/Test/versions/1/PAPER/Test.jar","fileInfo":map[string]any{"name":"Test.jar","sizeBytes":jar.Len(),"sha256Hash":digest}}}}
 b,e:=json.Marshal(raw);if e!=nil{t.Fatal(e)}
 var v hangarVersion
 if e=json.Unmarshal(b,&v);e!=nil{t.Fatal(e)}
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 p:=jobPayload{PluginProvider:"hangar",PluginProject:"1",PluginVersion:"2",PluginSHA256:digest,Version:"1.21.4",Software:"paper",InstanceID:id,Runtime:"docker",Memory:128,Port:25565}
 if _,e=selectHangarFile(v,p);e!=nil{t.Fatal(e)}
 v.ReviewState="unreviewed"
 if _,e=selectHangarFile(v,p);e==nil{t.Fatal("unreviewed release accepted")}
 v.ReviewState="reviewed"
 d:=v.Downloads["PAPER"];d.ExternalURL="https://example.com/test.jar";v.Downloads["PAPER"]=d
 if _,e=selectHangarFile(v,p);e==nil{t.Fatal("external download accepted")}
 root:=t.TempDir();dir:=filepath.Join(root,id)
 if e=os.Mkdir(dir,0700);e!=nil{t.Fatal(e)}
 if e=os.WriteFile(filepath.Join(dir,"omcp-software.json"),[]byte("{\"software\":\"paper\",\"version\":\"1.21.4\"}"),0600);e!=nil{t.Fatal(e)}
 old:=pluginClient;defer func(){pluginClient=old}()
 pluginClient=&http.Client{Transport:pluginFixtureTransport(func(r *http.Request)(*http.Response,error){
  var contents []byte
  switch r.URL.String(){case "https://hangar.papermc.io/api/v1/versions/2":contents=b;case "https://hangarcdn.papermc.io/plugins/test/Test/versions/1/PAPER/Test.jar":contents=jar.Bytes();default:t.Fatalf("unexpected URL %s",r.URL)}
  return &http.Response{StatusCode:200,Header:make(http.Header),Body:io.NopCloser(bytes.NewReader(contents))},nil
 })}
 s:=&supervisor{dir:dir,memory:128,backend:retireTestRuntime{"docker",true}}
 f:=&fleet{root:root,backend:"docker",allowPlugins:true,servers:map[string]*supervisor{id:s},ports:map[string]int{id:25565}}
 run:=func(action string)(bool,string){return f.execute(context.Background(),job{Action:"instance.plugin_"+action,Payload:p})}
 if ok,msg:=run("install");!ok{t.Fatal(msg)}
 if ok,msg:=run("list");!ok||!strings.Contains(msg,"omcp-hangar-1-2.jar"){t.Fatal(msg)}
 if ok,msg:=run("disable");!ok{t.Fatal(msg)}
 if ok,msg:=run("enable");!ok{t.Fatal(msg)}
 if ok,msg:=run("delete");!ok{t.Fatal(msg)}
}
