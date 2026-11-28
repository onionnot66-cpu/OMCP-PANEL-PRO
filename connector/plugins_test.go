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
 "strings"
 "os"
 "path/filepath"
 "testing"
)

func TestPluginSelection(t *testing.T){
 p:=jobPayload{PluginProject:"ABCDEFGH",PluginVersion:"IJKLMNOP",Version:"1.21.4",PluginSHA512:hex.EncodeToString(make([]byte,64))}
 src:=modrinthFile{URL:"https://cdn.modrinth.com/data/ABCDEFGH/versions/IJKLMNOP/test.jar",Filename:"test.jar",Size:10,Primary:true,Hashes:map[string]string{"sha512":p.PluginSHA512}}
 v:=modrinthVersion{ID:p.PluginVersion,Project:p.PluginProject,Channel:"release",Status:"listed",Versions:[]string{p.Version},Loaders:[]string{"paper"},Files:[]modrinthFile{src}}
 if _,e:=selectPluginFile(v,p);e!=nil{t.Fatal(e)}
 v.Loaders=[]string{"fabric"}
 if _,e:=selectPluginFile(v,p);e==nil{t.Fatal("wrong loader accepted")}
 v.Loaders=[]string{"paper"};v.Files[0].URL="https://example.com/test.jar"
 if _,e:=selectPluginFile(v,p);e==nil{t.Fatal("foreign origin accepted")}
 v.Files[0]=src;p.PluginSHA512="bad"
 if _,e:=selectPluginFile(v,p);e==nil{t.Fatal("wrong checksum accepted")}
}
func TestPluginInstallNoOverwrite(t *testing.T){
 root:=t.TempDir()
 d,e:=openPluginDir(root,true);if e!=nil{t.Fatal(e)};defer d.Close()
 var b bytes.Buffer
 z:=zip.NewWriter(&b);w,e:=z.Create("plugin.yml");if e!=nil{t.Fatal(e)}
 if _,e=w.Write([]byte("name: Test\n"));e!=nil{t.Fatal(e)}
 if e=z.Close();e!=nil{t.Fatal(e)}
 h:=sha512.Sum512(b.Bytes())
 p:=jobPayload{PluginProject:"ABCDEFGH",PluginVersion:"IJKLMNOP",PluginSHA512:hex.EncodeToString(h[:])}
 src:=modrinthFile{Size:int64(b.Len())}
 if e=installPlugin(context.Background(),d,p,src,bytes.NewReader(b.Bytes()));e!=nil{t.Fatal(e)}
 if e=installPlugin(context.Background(),d,p,src,bytes.NewReader(b.Bytes()));e==nil{t.Fatal("overwrite accepted")}
 name:="omcp-ABCDEFGH-IJKLMNOP.jar"
 if e=safePluginFile(d,name);e!=nil{t.Fatal(e)}
 if e=os.Symlink(filepath.Join(root,"plugins",name),filepath.Join(root,"plugins","omcp-QRSTUVWX-ABCDEFGH.jar"));e!=nil{t.Fatal(e)}
 if e=safePluginFile(d,"omcp-QRSTUVWX-ABCDEFGH.jar");e==nil{t.Fatal("symlink followed")}
 p.PluginProject="QRSTUVWX";p.PluginVersion="IJKLMNOP";p.PluginSHA512=hex.EncodeToString(make([]byte,64))
 if e=installPlugin(context.Background(),d,p,src,bytes.NewReader(b.Bytes()));e==nil{t.Fatal("invalid install accepted")}
}

type pluginFixtureTransport func(*http.Request)(*http.Response,error)
func(f pluginFixtureTransport)RoundTrip(r *http.Request)(*http.Response,error){return f(r)}

func TestPluginFullOfflineLifecycle(t *testing.T){
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 root:=t.TempDir()
 dir:=filepath.Join(root,id)
 if err:=os.Mkdir(dir,0700);err!=nil{t.Fatal(err)}
 if err:=os.WriteFile(filepath.Join(dir,"omcp-software.json"),[]byte("{\"software\":\"paper\",\"version\":\"1.21.4\"}"),0600);err!=nil{t.Fatal(err)}
 var jar bytes.Buffer
 z:=zip.NewWriter(&jar)
 w,err:=z.Create("plugin.yml");if err!=nil{t.Fatal(err)}
 if _,err=w.Write([]byte("name: Fixture\n"));err!=nil{t.Fatal(err)}
 if err=z.Close();err!=nil{t.Fatal(err)}
 digest:=sha512.Sum512(jar.Bytes())
 payload:=jobPayload{InstanceID:id,Runtime:"docker",Memory:128,Port:25565,Software:"paper",Version:"1.21.4",PluginProject:"ABCDEFGH",PluginVersion:"IJKLMNOP",PluginSHA512:hex.EncodeToString(digest[:])}
 download:="https://cdn.modrinth.com/data/ABCDEFGH/versions/IJKLMNOP/fixture.jar"
 v:=modrinthVersion{ID:payload.PluginVersion,Project:payload.PluginProject,Channel:"release",Status:"listed",Versions:[]string{payload.Version},Loaders:[]string{"paper"},Files:[]modrinthFile{{URL:download,Filename:"fixture.jar",Size:int64(jar.Len()),Primary:true,Hashes:map[string]string{"sha512":payload.PluginSHA512}}}}
 metadata,err:=json.Marshal(v);if err!=nil{t.Fatal(err)}
 old:=pluginClient
 calls:=0
 pluginClient=&http.Client{Transport:pluginFixtureTransport(func(r *http.Request)(*http.Response,error){
  calls++
  if r.Method!="GET"||r.Header.Get("User-Agent")!=userAgent{t.Fatal("invalid provider request")}
  var b []byte
  switch r.URL.String(){case "https://api.modrinth.com/v2/version/IJKLMNOP":b=metadata;case download:b=jar.Bytes();default:t.Fatalf("unexpected provider URL %s",r.URL)}
  return &http.Response{StatusCode:200,Header:make(http.Header),Body:io.NopCloser(bytes.NewReader(b))},nil
 })}
 defer func(){pluginClient=old}()
 s:=&supervisor{dir:dir,memory:128,backend:retireTestRuntime{"docker",true}}
 f:=&fleet{root:root,backend:"docker",servers:map[string]*supervisor{id:s},ports:map[string]int{id:25565}}
 run:=func(action string)(bool,string){return f.execute(context.Background(),job{Action:"instance.plugin_"+action,Payload:payload})}
 if ok,_:=run("install");ok||calls!=0{t.Fatal("local opt-in bypassed")}
 f.allowPlugins=true
 payload.OwnerControl=true; if ok,_:=run("install");ok||calls!=0{t.Fatal("owner install accepted")}
 payload.OwnerControl=false
 s.backend=retireTestRuntime{"docker",false};if ok,_:=run("install");ok||calls!=0{t.Fatal("existing container accepted")}
 s.backend=retireTestRuntime{"docker",true}
 if ok,msg:=run("install");!ok{t.Fatal(msg)}
 if calls!=2{t.Fatalf("provider request count %d",calls)}
 if ok,msg:=run("list");!ok||!strings.Contains(msg,"omcp-ABCDEFGH-IJKLMNOP.jar"){t.Fatal(msg)}
 if ok,msg:=run("disable");!ok{t.Fatal(msg)}
 if ok,msg:=run("list");!ok||!strings.Contains(msg,".jar.disabled"){t.Fatal(msg)}
 if ok,msg:=run("enable");!ok{t.Fatal(msg)}
 data:=filepath.Join(dir,"plugins","Fixture")
 if err:=os.Mkdir(data,0700);err!=nil{t.Fatal(err)}
 if err:=os.WriteFile(filepath.Join(data,"config.yml"),[]byte("keep"),0600);err!=nil{t.Fatal(err)}
 if ok,msg:=run("delete");!ok{t.Fatal(msg)}
 if ok,msg:=run("list");!ok||msg!="[]"{t.Fatal(msg)}
 if b,err:=os.ReadFile(filepath.Join(data,"config.yml"));err!=nil||string(b)!="keep"{t.Fatal("plugin data removed")}
}
