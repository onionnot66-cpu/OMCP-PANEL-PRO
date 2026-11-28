package main

import (
 "archive/zip"
 "bytes"
 "context"
 "crypto/sha512"
 "encoding/hex"
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
