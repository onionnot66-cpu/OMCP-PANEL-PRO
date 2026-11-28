package main

import (
 "archive/zip"
 "bytes"
 "context"
 "encoding/json"
 "os"
 "path/filepath"
 "strings"
 "testing"
)

func TestWorldBackupRestore(t *testing.T){
 ctx:=context.Background()
 root:=t.TempDir()
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 game:=filepath.Join(root,id)
 source:=filepath.Join(game,"world")
 if e:=os.MkdirAll(filepath.Join(source,"region"),0700);e!=nil{t.Fatal(e)}
 if e:=os.WriteFile(filepath.Join(source,"level.dat"),[]byte("level"),0600);e!=nil{t.Fatal(e)}
 if e:=os.WriteFile(filepath.Join(source,"region","r.0.0.mca"),[]byte("original world"),0600);e!=nil{t.Fatal(e)}
 s:=&supervisor{dir:game,memory:128,backend:retireTestRuntime{"docker",true}}
 f:=&fleet{root:root,backend:"docker",servers:map[string]*supervisor{id:s},ports:map[string]int{id:25565}}
 j:=job{ID:"bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb",Action:"instance.world_backup",Payload:jobPayload{InstanceID:id,Runtime:"docker",Memory:128,Port:25565,World:"world",Software:"vanilla"}}
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("world access enabled by default")}
 f.allowWorlds=true
 s.backend=retireTestRuntime{"docker",false}
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("existing container allowed")}
 s.backend=retireTestRuntime{"docker",true}
 j.Payload.OwnerControl=true
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("owner backup allowed")}
 j.Payload.OwnerControl=false
 ok,result:=f.execute(ctx,j);if !ok{t.Fatal(result)}
 var b worldBackup
 if e:=json.Unmarshal([]byte(result),&b);e!=nil{t.Fatal(e)}
 j.Action="instance.world_restore"
 j.Payload.BackupID=b.ID;j.Payload.BackupSHA256=b.SHA256
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("existing world overwritten")}
 j.Payload.World="restored";j.Payload.BackupSHA256=strings.Repeat("0",64)
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("wrong checksum accepted")}
 j.Payload.BackupSHA256=b.SHA256
 if ok,result:=f.execute(ctx,j);!ok{t.Fatal(result)}
 if data,e:=os.ReadFile(filepath.Join(game,"restored","region","r.0.0.mca"));e!=nil||string(data)!="original world"{t.Fatal("restore content mismatch")}
 if data,e:=os.ReadFile(filepath.Join(source,"level.dat"));e!=nil||string(data)!="level"{t.Fatal("source changed")}
 j.Action="instance.world_list"
 if ok,result:=f.execute(ctx,j);!ok||!strings.Contains(result,"restored"){t.Fatal(result)}
 if e:=os.WriteFile(filepath.Join(game,"server.properties"),[]byte("level-name=world\nserver-port=25565\n"),0600);e!=nil{t.Fatal(e)}
 j.Action="instance.world_activate"
 if ok,result:=f.execute(ctx,j);!ok{t.Fatal(result)}
 if data,e:=os.ReadFile(filepath.Join(game,"server.properties"));e!=nil||!strings.Contains(string(data),"level-name=restored"){t.Fatal("active world not updated")}
 j.Payload.Software="paper"
 if ok,_:=f.execute(ctx,j);ok{t.Fatal("Paper activation accepted")}
}
func TestWorldArchiveUnsafePaths(t *testing.T){
 for _,name:=range []string{"../outside","/absolute","region/../../outside","a\\b"}{
  var buf bytes.Buffer
  z:=zip.NewWriter(&buf)
  w,e:=z.Create(name);if e!=nil{t.Fatal(e)}
  if _,e=w.Write([]byte("bad"));e!=nil{t.Fatal(e)}
  if e=z.Close();e!=nil{t.Fatal(e)}
  if e=extractWorldZIP(context.Background(),bytes.NewReader(buf.Bytes()),int64(buf.Len()),t.TempDir());e==nil{t.Fatalf("accepted %q",name)}
 }
 root:=t.TempDir()
 if e:=os.Symlink("/etc/passwd",filepath.Join(root,"level.dat"));e!=nil{t.Fatal(e)}
 var buf bytes.Buffer
 if e:=writeWorldZIP(context.Background(),root,&buf);e==nil{t.Fatal("symlink backup accepted")}
}
