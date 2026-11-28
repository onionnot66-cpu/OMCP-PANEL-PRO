package main

import (
 "context"
 "crypto/sha256"
 "encoding/hex"
 "encoding/json"
 "errors"
 "fmt"
 "io"
 "net/http"
 "os"
 "path/filepath"
 "strconv"
 "time"
)

const transferLimit int64=32*1024*1024
var transferHTTP=&http.Client{Timeout:3*time.Minute,CheckRedirect:func(*http.Request,[]*http.Request)error{return errors.New("transfer redirects disabled")}}

func transferRequest(ctx context.Context,c config,j job,method string,body io.Reader,size int64,digest string)(*http.Response,error){
 if err:=validate(c);err!=nil{return nil,err}
 if !worldDigest.MatchString(c.Credential)||!worldDigest.MatchString(j.Lease)||!instanceIDPattern.MatchString(j.ID){return nil,errors.New("invalid transfer authorization")}
 req,err:=http.NewRequestWithContext(ctx,method,c.Panel+"/api/omcp/v1/transfers/"+j.ID,body)
 if err!=nil{return nil,err}
 req.Header.Set("Authorization","Bearer "+c.Credential)
 req.Header.Set("X-OMCP-Lease",j.Lease)
 req.Header.Set("X-OMCP-Node",strconv.Itoa(c.Node))
 if method==http.MethodPost{
  req.ContentLength=size
  req.Header.Set("Content-Type","application/zip")
  req.Header.Set("X-OMCP-SHA256",digest)
 }
 resp,err:=transferHTTP.Do(req);if err!=nil{return nil,err}
 if resp.StatusCode!=http.StatusOK{resp.Body.Close();return nil,fmt.Errorf("binary transfer HTTP %d",resp.StatusCode)}
 return resp,nil
}
func importWorld(ctx context.Context,c config,j job,game,spool string)error{
 p:=j.Payload
 if !validWorldName(p.World)||!worldDigest.MatchString(p.TransferSHA256)||p.TransferBytes<1||p.TransferBytes>transferLimit{return errors.New("invalid import metadata")}
 dest:=filepath.Join(game,p.World)
 if _,e:=os.Lstat(dest);!os.IsNotExist(e){return errors.New("destination exists; import never overwrites")}
 resp,e:=transferRequest(ctx,c,j,http.MethodGet,nil,0,"");if e!=nil{return e}
 defer resp.Body.Close()
 if resp.Header.Get("X-OMCP-SHA256")!=p.TransferSHA256||resp.ContentLength!=p.TransferBytes{return errors.New("transfer response metadata mismatch")}
 tmp,e:=os.CreateTemp(spool,".omcp-import-");if e!=nil{return e}
 defer os.Remove(tmp.Name());defer tmp.Close()
 h:=sha256.New()
 n,e:=io.Copy(io.MultiWriter(tmp,h),io.LimitReader(worldContextReader{ctx,resp.Body},p.TransferBytes+1))
 if e!=nil{return e}
 if n!=p.TransferBytes||hex.EncodeToString(h.Sum(nil))!=p.TransferSHA256{return errors.New("import checksum or size mismatch")}
 stage,e:=os.MkdirTemp(game,".omcp-world-");if e!=nil{return e};defer os.RemoveAll(stage)
 if e=extractWorldZIP(ctx,tmp,n,stage);e!=nil{return e}
 if e=ctx.Err();e!=nil{return e}
 if _,e=os.Lstat(dest);!os.IsNotExist(e){return errors.New("destination appeared; import refused")}
 if e=os.Rename(stage,dest);e!=nil{return e}
 d,e:=os.Open(game);if e!=nil{return e};defer d.Close()
 return d.Sync()
}
func exportWorld(ctx context.Context,c config,j job,dir string)error{
 p:=j.Payload
 if !instanceIDPattern.MatchString(p.BackupID)||!worldDigest.MatchString(p.BackupSHA256){return errors.New("invalid backup selection")}
 list,e:=listWorldBackups(dir);if e!=nil{return e}
 var selected *worldBackup
 for i:=range list{if list[i].ID==p.BackupID&&list[i].SHA256==p.BackupSHA256{selected=&list[i];break}}
 if selected==nil||selected.Bytes>transferLimit{return errors.New("backup unavailable or exceeds 32 MiB transport limit")}
 f,st,e:=openWorldRegular(filepath.Join(dir,p.BackupID+".zip"),transferLimit);if e!=nil{return e};defer f.Close()
 h:=sha256.New()
 if _,e=io.Copy(h,worldContextReader{ctx,f});e!=nil{return e}
 if st.Size()!=selected.Bytes||hex.EncodeToString(h.Sum(nil))!=p.BackupSHA256{return errors.New("backup checksum mismatch")}
 resp,e:=transferRequest(ctx,c,j,http.MethodPost,io.NewSectionReader(f,0,st.Size()),st.Size(),p.BackupSHA256)
 if e!=nil{return e};defer resp.Body.Close()
 var ack struct{OK bool}
 if e=json.NewDecoder(io.LimitReader(resp.Body,4096)).Decode(&ack);e!=nil||!ack.OK{return errors.New("export acknowledgement failed")}
 return nil
}
func(f *fleet) transferWorld(ctx context.Context,s *supervisor,j job)(bool,string){
 switch j.Action{
 case "instance.world_import":
  if e:=importWorld(ctx,f.transferConfig,j,s.dir,f.root);e!=nil{return false,e.Error()}
  return true,"verified world imported into a new folder"
 case "instance.world_export":
  d,e:=f.backupDirectory(j.Payload.InstanceID);if e!=nil{return false,e.Error()}
  if e=exportWorld(ctx,f.transferConfig,j,d);e!=nil{return false,e.Error()}
  return true,"verified backup sent to temporary panel spool"
 default:return false,"unsupported transfer"
 }
}
