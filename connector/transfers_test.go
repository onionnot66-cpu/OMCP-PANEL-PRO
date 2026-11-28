package main

import (
 "bytes"
 "context"
 "crypto/sha256"
 "encoding/hex"
 "io"
 "net/http"
 "net/http/httptest"
 "os"
 "path/filepath"
 "strconv"
 "strings"
 "testing"
)

func TestWorldBinaryTransport(t *testing.T){
 root:=t.TempDir();game:=filepath.Join(root,"game")
 source:=filepath.Join(game,"world");backups:=filepath.Join(root,"backups")
 for _,dir:=range []string{source,backups}{if e:=os.MkdirAll(dir,0700);e!=nil{t.Fatal(e)}}
 if e:=os.WriteFile(filepath.Join(source,"level.dat"),[]byte("world fixture"),0600);e!=nil{t.Fatal(e)}
 ctx:=context.Background()
 id:="aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"
 receipt,e:=createWorldBackup(ctx,backups,source,"world",id);if e!=nil{t.Fatal(e)}
 archive,e:=os.ReadFile(filepath.Join(backups,id+".zip"));if e!=nil{t.Fatal(e)}
 sum:=sha256.Sum256(archive);digest:=hex.EncodeToString(sum[:])
 credential,lease:=strings.Repeat("a",64),strings.Repeat("b",64)
 srv:=httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter,r *http.Request){
  if r.Header.Get("Authorization")!="Bearer "+credential||r.Header.Get("X-OMCP-Lease")!=lease||r.Header.Get("X-OMCP-Node")!="1"||r.URL.Path!="/api/omcp/v1/transfers/"+id{w.WriteHeader(403);return}
  if r.Method==http.MethodGet{w.Header().Set("X-OMCP-SHA256",digest);w.Header().Set("Content-Length",strconv.Itoa(len(archive)));_,_=w.Write(archive);return}
  if r.Method!=http.MethodPost||r.Header.Get("X-OMCP-SHA256")!=digest||r.Header.Get("Content-Type")!="application/zip"{w.WriteHeader(400);return}
  b,err:=io.ReadAll(r.Body);if err!=nil||!bytes.Equal(b,archive){w.WriteHeader(422);return}
  _,_=w.Write([]byte("{\"ok\":true}"))
 }))
 defer srv.Close()
 old:=transferHTTP;transferHTTP=srv.Client();defer func(){transferHTTP=old}()
 c:=config{Panel:srv.URL,Node:1,Credential:credential}
 j:=job{ID:id,Lease:lease,Payload:jobPayload{World:"imported",TransferBytes:int64(len(archive)),TransferSHA256:digest,BackupID:receipt.ID,BackupSHA256:receipt.SHA256}}
 if e=importWorld(ctx,c,j,game,root);e!=nil{t.Fatal(e)}
 if b,e:=os.ReadFile(filepath.Join(game,"imported","level.dat"));e!=nil||string(b)!="world fixture"{t.Fatal("imported data mismatch")}
 if e=importWorld(ctx,c,j,game,root);e==nil{t.Fatal("import overwrite accepted")}
 j.Payload.World="wrong";j.Payload.TransferSHA256=strings.Repeat("0",64)
 if e=importWorld(ctx,c,j,game,root);e==nil{t.Fatal("bad digest accepted")}
 if e=exportWorld(ctx,c,j,backups);e!=nil{t.Fatal(e)}
 j.Lease=strings.Repeat("c",64)
 if e=exportWorld(ctx,c,j,backups);e==nil{t.Fatal("wrong lease accepted")}
}
