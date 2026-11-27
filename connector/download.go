package main

import (
 "crypto/md5"
 "crypto/sha1"
 "crypto/sha256"
 "encoding/hex"
 "encoding/json"
 "errors"
 "flag"
 "fmt"
 "hash"
 "io"
 "net/http"
 "net/url"
 "os"
 "path/filepath"
 "regexp"
 "strings"
 "time"
)

const userAgent="OMCP-Connect/0.1 (https://github.com/onionnot66-cpu/OMCP-PANEL-PRO)"
const maxJar=512*1024*1024
var releaseVersion=regexp.MustCompile("^[0-9]+(\\.[0-9]+){1,3}$")
var allowedDownloadHosts=map[string]bool{
 "piston-meta.mojang.com":true,"piston-data.mojang.com":true,
 "launcher.mojang.com":true,"launchermeta.mojang.com":true,
 "fill.papermc.io":true,"fill-data.papermc.io":true,"api.purpurmc.org":true,
}
func safeDownloadURL(raw string)bool{
 u,err:=url.Parse(raw)
 return err==nil && u.Scheme=="https" && allowedDownloadHosts[u.Hostname()] && u.User==nil && (u.Port()=="" || u.Port()=="443") && u.Fragment==""
}
var downloadClient=&http.Client{
 Timeout:3*time.Minute,
 CheckRedirect:func(req *http.Request,via []*http.Request)error{
  if len(via)>=4 || !safeDownloadURL(req.URL.String()){return errors.New("unapproved download redirect")}
  return nil
 },
}
func fetchDownload(raw string)(io.ReadCloser,error){
 if !safeDownloadURL(raw){return nil,errors.New("unapproved download URL")}
 req,err:=http.NewRequest(http.MethodGet,raw,nil)
 if err!=nil{return nil,err}
 req.Header.Set("User-Agent",userAgent)
 resp,err:=downloadClient.Do(req)
 if err!=nil{return nil,err}
 if resp.StatusCode!=200{resp.Body.Close();return nil,fmt.Errorf("download provider returned HTTP %d",resp.StatusCode)}
 return resp.Body,nil
}
func metadata(raw string,expectedSHA1 string,out any)error{
 body,err:=fetchDownload(raw)
 if err!=nil{return err}
 defer body.Close()
 const limit=16*1024*1024
 data,err:=io.ReadAll(io.LimitReader(body,limit+1))
 if err!=nil{return err}
 if len(data)>limit{return errors.New("metadata too large")}
 if expectedSHA1!="" {
  h:=sha1.Sum(data)
  if !strings.EqualFold(hex.EncodeToString(h[:]),expectedSHA1){return errors.New("metadata checksum mismatch")}
 }
 return json.Unmarshal(data,out)
}
type jarSource struct{URL,Checksum,Algorithm,Build string;Size int64}
func resolveJar(software,version string)(jarSource,error){
 if len(version)>32 || !releaseVersion.MatchString(version){return jarSource{},errors.New("provide a numeric release version, such as 1.21.4")}
 switch software{
 case "vanilla":
  var manifest struct{Versions []struct{ID,Type,URL,SHA1 string}}
  if err:=metadata("https://piston-meta.mojang.com/mc/game/version_manifest_v2.json","",&manifest);err!=nil{return jarSource{},err}
  for _,v:=range manifest.Versions{
   if v.ID!=version || v.Type!="release"{continue}
   if len(v.SHA1)!=40{return jarSource{},errors.New("missing version checksum")}
   var m struct{Downloads struct{Server struct{URL,SHA1 string;Size int64}};JavaVersion struct{MajorVersion int}}
   if err:=metadata(v.URL,v.SHA1,&m);err!=nil{return jarSource{},err}
   d:=m.Downloads.Server
   if m.JavaVersion.MajorVersion>0{fmt.Printf("Required Java major version: %d\n",m.JavaVersion.MajorVersion)}
   return jarSource{d.URL,d.SHA1,"sha1",version,d.Size},nil
  }
 case "paper":
  var builds []struct{ID int;Channel string;Downloads map[string]struct{URL string;Size int64;Checksums struct{SHA256 string}}}
  if err:=metadata("https://fill.papermc.io/v3/projects/paper/versions/"+version+"/builds","",&builds);err!=nil{return jarSource{},err}
  var selected jarSource
  highest:=-1
  for _,b:=range builds{
   d,ok:=b.Downloads["server:default"]
   if ok && b.Channel=="STABLE" && b.ID>highest && d.Checksums.SHA256!=""{
    highest=b.ID;selected=jarSource{d.URL,d.Checksums.SHA256,"sha256",fmt.Sprint(b.ID),d.Size}
   }
  }
  if highest>=0{return selected,nil}
 case "purpur":
  base:="https://api.purpurmc.org/v2/purpur/"+version+"/"
  var b struct{Build,Result,MD5 string}
  if err:=metadata(base+"latest","",&b);err!=nil{return jarSource{},err}
  if b.Result=="SUCCESS" && regexp.MustCompile("^[0-9]{1,12}$").MatchString(b.Build){
   return jarSource{base+b.Build+"/download",b.MD5,"md5",b.Build,0},nil
  }
 default:return jarSource{},errors.New("supported software: vanilla, paper, purpur")
 }
 return jarSource{},errors.New("requested release or successful stable build unavailable")
}

func downloadMinecraft(args []string)error{
 flags:=flag.NewFlagSet("download",flag.ContinueOnError)
 software:=flags.String("software","paper","vanilla, paper or purpur")
 version:=flags.String("version","","exact Minecraft release")
 dir:=flags.String("dir","","new server directory")
 port:=flags.Int("port",25565,"Minecraft TCP port")
 eula:=flags.Bool("accept-eula",false,"confirm acceptance of Minecraft EULA")
 if err:=flags.Parse(args);err!=nil{return err}
 if flags.NArg()!=0 || *dir=="" || *port<1024 || *port>65535 || !*eula || os.Geteuid()==0{return errors.New("non-root user, new directory, valid port and explicit EULA acceptance required")}
 if !available("java"){return errors.New("install compatible Java first")}
 if _,err:=os.Lstat(*dir);!os.IsNotExist(err){return errors.New("target directory already exists or cannot be checked")}
 src,err:=resolveJar(*software,*version)
 if err!=nil{return err}
 if src.Size<0 || src.Size>maxJar{return errors.New("invalid provider jar size")}
 var h hash.Hash
 switch src.Algorithm{case "sha256":h=sha256.New();case "sha1":h=sha1.New();case "md5":h=md5.New();default:return errors.New("unsupported checksum")}
 expected,err:=hex.DecodeString(src.Checksum)
 if err!=nil || len(expected)!=h.Size(){return errors.New("missing or invalid upstream checksum")}
 body,err:=fetchDownload(src.URL)
 if err!=nil{return err}
 defer body.Close()
 tmp,err:=os.CreateTemp("","omcp-jar-*.jar")
 if err!=nil{return err}
 defer os.Remove(tmp.Name())
 strong:=sha256.New()
 n,err:=io.Copy(io.MultiWriter(tmp,h,strong),io.LimitReader(body,maxJar+1))
 closeErr:=tmp.Close()
 if err!=nil{return err};if closeErr!=nil{return closeErr}
 if n<1 || n>maxJar || (src.Size>0 && n!=src.Size) || !strings.EqualFold(hex.EncodeToString(h.Sum(nil)),src.Checksum){return errors.New("provider jar checksum or size mismatch")}
 digest:=hex.EncodeToString(strong.Sum(nil))
 if err:=setupMinecraft([]string{"--dir",*dir,"--jar",tmp.Name(),"--sha256",digest,"--port",fmt.Sprint(*port),"--accept-eula"});err!=nil{return err}
 provenance,err:=json.MarshalIndent(map[string]any{"software":*software,"version":*version,"build":src.Build,"upstream_checksum":src.Checksum,"algorithm":src.Algorithm,"sha256":digest},"","  ")
 if err!=nil{return err}
 if err=os.WriteFile(filepath.Join(*dir,"omcp-software.json"),provenance,0600);err!=nil{return fmt.Errorf("server created, provenance write failed: %w",err)}
 fmt.Printf("Installed %s %s build %s (not started)\n",*software,*version,src.Build)
 return nil
}
