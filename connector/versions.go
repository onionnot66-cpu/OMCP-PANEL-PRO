package main

import (
 "context"
 "crypto/md5"
 "crypto/sha1"
 "crypto/sha256"
 "encoding/hex"
 "encoding/json"
 "errors"
 "hash"
 "io"
 "os"
 "path/filepath"
 "strconv"
 "strings"
 "syscall"
)

type softwareReceipt struct {
 Software string `json:"software"`
 Version string `json:"version"`
 Build string `json:"build"`
 SHA256 string `json:"sha256"`
 UpstreamChecksum string `json:"upstream_checksum"`
 Algorithm string `json:"algorithm"`
}

func newerVersion(old,target string)bool{
 if !releaseVersion.MatchString(old)||!releaseVersion.MatchString(target)||len(old)>32||len(target)>32{return false}
 parse:=func(s string)([4]uint64,bool){
  var out [4]uint64
  for i,p:=range strings.Split(s,"."){
   n,e:=strconv.ParseUint(p,10,32);if e!=nil||strconv.FormatUint(n,10)!=p{return out,false};out[i]=n
  }
  return out,true
 }
 a,ok:=parse(old);if !ok{return false};b,ok:=parse(target);if !ok{return false}
 for i:=range a{if a[i]!=b[i]{return b[i]>a[i]}}
 return false
}

func readSoftware(ctx context.Context,dir string)(softwareReceipt,error){
 var r softwareReceipt
 b,e:=readPrivateFile(filepath.Join(dir,"omcp-software.json"),8192);if e!=nil{return r,e}
 if json.Unmarshal(b,&r)!=nil||!worldDigest.MatchString(r.SHA256)||!releaseVersion.MatchString(r.Version){return r,errors.New("invalid software receipt")}
 f,st,e:=openWorldRegular(filepath.Join(dir,"server.jar"),maxJar);if e!=nil{return r,e};defer f.Close()
 h:=sha256.New()
 n,e:=io.Copy(h,io.LimitReader(worldContextReader{ctx,f},maxJar+1))
 if e!=nil{return r,e}
 if n<1||n!=st.Size()||hex.EncodeToString(h.Sum(nil))!=r.SHA256{return r,errors.New("server JAR differs from software receipt")}
 return r,nil
}

func verifiedVersionJar(ctx context.Context,src jarSource,dest *os.File)(string,error){
 var h hash.Hash
 switch src.Algorithm{case "sha256":h=sha256.New();case "sha1":h=sha1.New();case "md5":h=md5.New();default:return "",errors.New("unsupported checksum")}
 b,e:=hex.DecodeString(src.Checksum)
 if e!=nil||len(b)!=h.Size()||src.Size<0||src.Size>maxJar{return "",errors.New("invalid upstream artifact metadata")}
 body,e:=fetchDownload(ctx,src.URL);if e!=nil{return "",e};defer body.Close()
 strong:=sha256.New()
 n,e:=io.Copy(io.MultiWriter(dest,h,strong),io.LimitReader(worldContextReader{ctx,body},maxJar+1))
 if e!=nil{return "",e}
 if n<1||n>maxJar||(src.Size>0&&n!=src.Size)||!strings.EqualFold(hex.EncodeToString(h.Sum(nil)),src.Checksum){return "",errors.New("artifact checksum or size mismatch")}
 if e=dest.Sync();e!=nil{return "",e}
 return hex.EncodeToString(strong.Sum(nil)),nil
}

func syncDirectory(path string)error{d,e:=os.Open(path);if e!=nil{return e};defer d.Close();return d.Sync()}

func copyVersionFile(ctx context.Context,source,dest string,limit int64)error{
 f,st,e:=openWorldRegular(source,limit);if e!=nil{return e};defer f.Close()
 out,e:=os.OpenFile(dest,os.O_CREATE|os.O_EXCL|os.O_WRONLY,0600);if e!=nil{return e}
 n,e:=io.Copy(out,io.LimitReader(worldContextReader{ctx,f},limit+1))
 if e==nil&&n!=st.Size(){e=errors.New("source changed")}
 if e==nil{e=out.Sync()};ce:=out.Close()
 if e!=nil{return e};return ce
}

func(f *fleet) versionMarker(id string)string{return filepath.Join(f.root,".omcp-version-pending-"+id)}
func(f *fleet) versionBlocked(id string)bool{_,e:=os.Lstat(f.versionMarker(id));return !os.IsNotExist(e)}
func(f *fleet) versionArchive(id string)(string,error){
 p:=filepath.Join(f.root,".omcp-previous-version-"+id)
 if e:=os.Mkdir(p,0700);e!=nil{return "",errors.New("previous version archive exists or cannot be created; reconcile locally before another upgrade")}
 return p,nil
}
func(f *fleet) changeVersion(ctx context.Context,s *supervisor,j job)(softwareReceipt,error){
 p:=j.Payload
 var empty softwareReceipt
 if !p.AcceptEULA||!newerVersion(p.Version,p.TargetVersion){return empty,errors.New("explicit EULA acceptance and a newer exact release required")}
 old,e:=readSoftware(ctx,s.dir);if e!=nil{return empty,e}
 if old.Software!=p.Software||old.Version!=p.Version{return empty,errors.New("installed software differs from reservation")}
 if f.versionBlocked(p.InstanceID){return empty,errors.New("unfinished version change; inspect before retry")}
 src,e:=resolveJar(ctx,p.Software,p.TargetVersion);if e!=nil{return empty,e}
 // Download in the stopped game filesystem to allow an atomic rename.
 jar,e:=os.CreateTemp(s.dir,".omcp-version-jar-");if e!=nil{return empty,e};defer os.Remove(jar.Name())
 digest,e:=verifiedVersionJar(ctx,src,jar);ce:=jar.Close();if e!=nil{return empty,e};if ce!=nil{return empty,ce}
 next:=softwareReceipt{Software:p.Software,Version:p.TargetVersion,Build:src.Build,SHA256:digest,UpstreamChecksum:src.Checksum,Algorithm:src.Algorithm}
 data,e:=json.Marshal(next);if e!=nil{return empty,e}
 meta,e:=os.CreateTemp(s.dir,".omcp-version-meta-");if e!=nil{return empty,e};defer os.Remove(meta.Name())
 if _,e=meta.Write(data);e==nil{e=meta.Sync()};ce=meta.Close();if e!=nil{return empty,e};if ce!=nil{return empty,ce}
 archive,e:=f.versionArchive(p.InstanceID);if e!=nil{return empty,e}
 prepared:=false
 defer func(){if !prepared{_ = os.RemoveAll(archive)}}()
 if e=copyVersionFile(ctx,filepath.Join(s.dir,"server.jar"),filepath.Join(archive,"server.jar"),maxJar);e!=nil{return empty,e}
 if e=copyVersionFile(ctx,filepath.Join(s.dir,"omcp-software.json"),filepath.Join(archive,"omcp-software.json"),8192);e!=nil{return empty,e}
 if _,e=readSoftware(ctx,archive);e!=nil{return empty,e}
 if e=syncDirectory(archive);e!=nil{return empty,e}
 if e=ctx.Err();e!=nil{return empty,e}
 // Write the durable guard before modifying either live file. If the process
 // dies between the two atomic replacements, start remains blocked.
 marker,e:=os.OpenFile(f.versionMarker(p.InstanceID),os.O_WRONLY|os.O_CREATE|os.O_EXCL,0600)
 if e!=nil{return empty,e}
 prepared=true
 _,e=marker.WriteString(j.ID+"\n");if e==nil{e=marker.Sync()};ce=marker.Close();if e!=nil{return empty,e};if ce!=nil{return empty,ce}
 if e=syncDirectory(f.root);e!=nil{return empty,e}
 if e=ctx.Err();e!=nil{return empty,e}
 if e=os.Rename(jar.Name(),filepath.Join(s.dir,"server.jar"));e!=nil{return empty,e}
 if e=os.Rename(meta.Name(),filepath.Join(s.dir,"omcp-software.json"));e!=nil{return empty,e}
 if e=syncDirectory(s.dir);e!=nil{return empty,e}
 actual,e:=readSoftware(ctx,s.dir);if e!=nil{return empty,e}
 if actual!=next{return empty,errors.New("new software receipt mismatch")}
 if e=os.Remove(f.versionMarker(p.InstanceID));e!=nil{return empty,e}
 if e=syncDirectory(f.root);e!=nil{return empty,e}
 return actual,nil
}
func(f *fleet) versionJob(ctx context.Context,s *supervisor,j job)(bool,string){
 p:=j.Payload
 if !f.allowVersions||(f.backend!="docker"&&f.backend!="podman"){return false,"local container version manager opt-in required"}
 if s.running(){return false,"stop the server before changing or inspecting versions"}
 if s.backend==nil||s.backend.name()!=f.backend{return false,"runtime mismatch"}
 if e:=s.backend.requireAbsent();e!=nil{return false,e.Error()}
 var r softwareReceipt
 var e error
 switch j.Action{
 case "instance.version_change":
  r,e=f.changeVersion(ctx,s,j)
 case "instance.version_inspect":
  r,e=readSoftware(ctx,s.dir)
  if e==nil&&(r.Software!=p.Software||(r.Version!=p.Version&&r.Version!=p.TargetVersion)){e=errors.New("installed version is outside pending transition")}
  if e==nil&&f.versionBlocked(p.InstanceID){
   // Only a consistent old or target pair can clear the local crash guard.
   marker,er:=os.Lstat(f.versionMarker(p.InstanceID))
   if er!=nil||!marker.Mode().IsRegular(){return false,"invalid version guard; reconcile locally"}
   stat,ok:=marker.Sys().(*syscall.Stat_t)
   if !ok||stat.Uid!=uint32(os.Geteuid())||stat.Nlink!=1{return false,"unsafe version guard"}
   if e=os.Remove(f.versionMarker(p.InstanceID));e==nil{e=syncDirectory(f.root)}
  }
 default:return false,"unsupported version action"
 }
 if e!=nil{return false,e.Error()}
 return true,"version:"+r.Software+":"+r.Version+":"+r.SHA256
}
