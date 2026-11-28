package main

import (
 "context"
 "errors"
 "fmt"
 "os"
 "path/filepath"
)

func retirementReceipt(id string) string { return "retired:"+id }

func (f *fleet) retirementMarker(id string) (string,error) {
 if !instanceIDPattern.MatchString(id) || f.root=="" {return "",errors.New("invalid retirement target")}
 return filepath.Join(f.root,".omcp-retired-"+id),nil
}

// A durable tombstone lives outside the mounted game directory. Invalid or
// unreadable tombstones fail closed. It must never be removed to reuse a UUID.
func (f *fleet) retired(id string) (bool,error) {
 path,err:=f.retirementMarker(id);if err!=nil{return false,err}
 b,err:=readPrivateFile(path,128)
 if os.IsNotExist(err){return false,nil}
 if err!=nil{return false,err}
 if string(b)!=retirementReceipt(id)+"\n"{return false,errors.New("invalid retirement marker; reconcile locally")}
 return true,nil
}

func (f *fleet) retire(ctx context.Context,id string,s *supervisor)(bool,string){
 if f.backend!="podman" && f.backend!="docker"{return false,"retirement requires an isolated container backend"}
 if s.backend==nil || s.backend.name()!=f.backend{return false,"runtime mismatch"}
 if s.running(){return false,"stop the instance before retirement"}
 if err:=s.backend.requireAbsent();err!=nil{return false,err.Error()}
 if err:=ctx.Err();err!=nil{return false,err.Error()}
 path,err:=f.retirementMarker(id);if err!=nil{return false,err.Error()}
 retired,err:=f.retired(id);if err!=nil{return false,err.Error()}
 if !retired {
  // Create and sync privately before atomic publication under the fleet lock.
  tmp,err:=os.CreateTemp(f.root,".omcp-retire-tmp-");if err!=nil{return false,err.Error()}
  defer os.Remove(tmp.Name())
  if _,err=fmt.Fprintln(tmp,retirementReceipt(id));err==nil{err=tmp.Sync()}
  closeErr:=tmp.Close()
  if err!=nil{return false,err.Error()};if closeErr!=nil{return false,closeErr.Error()}
  if err=ctx.Err();err!=nil{return false,err.Error()}
  if _,err=os.Lstat(path);!os.IsNotExist(err){return false,"retirement marker changed; retry"}
  if err=os.Rename(tmp.Name(),path);err!=nil{return false,err.Error()}
 }
 root,err:=os.Open(f.root);if err!=nil{return false,err.Error()}
 defer root.Close()
 if err=root.Sync();err!=nil{return false,err.Error()}
 // A retry is successful only after absence and persistence are rechecked.
 return true,retirementReceipt(id)
}
