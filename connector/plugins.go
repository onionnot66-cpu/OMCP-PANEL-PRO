package main

import (
 "archive/zip"
 "context"
 "crypto/sha512"
 "encoding/hex"
 "encoding/json"
 "errors"
 "fmt"
 "io"
 "net/http"
 "net/url"
 "os"
 "path/filepath"
 "regexp"
 "strings"
 "syscall"
 "time"
)

const pluginLimit=64*1024*1024
var pluginID=regexp.MustCompile("^[A-Za-z0-9]{3,32}$")
var managedPlugin=regexp.MustCompile("^omcp-([A-Za-z0-9]{3,32})-([A-Za-z0-9]{3,32})\\.jar(\\.disabled)?$")
var pluginHash=regexp.MustCompile("^[0-9a-f]{128}$")

type modrinthFile struct{
 URL string
 Filename string
 Primary bool
 Size int64
 Hashes map[string]string
}
type modrinthVersion struct{
 ID string
 Project string `json:"project_id"`
 Channel string `json:"version_type"`
 Status string
 Versions []string `json:"game_versions"`
 Loaders []string
 Files []modrinthFile
 Dependencies []struct{Kind string `json:"dependency_type"`}
}
func pluginURL(raw,host string)bool{
 u,e:=url.Parse(raw)
 return e==nil && u.Scheme=="https" && u.Hostname()==host && (u.Port()==""||u.Port()=="443") && u.User==nil && u.Fragment=="" && u.RawQuery=="" && u.Opaque==""
}
func hasString(v []string,w string)bool{for _,s:=range v{if s==w{return true}};return false}
func selectPluginFile(v modrinthVersion,p jobPayload)(modrinthFile,error){
 if v.ID!=p.PluginVersion||v.Project!=p.PluginProject||v.Channel!="release"||(v.Status!=""&&v.Status!="listed")||!hasString(v.Versions,p.Version) || !(hasString(v.Loaders,"paper")||hasString(v.Loaders,"spigot")||hasString(v.Loaders,"bukkit")){
  return modrinthFile{},errors.New("version is not a listed compatible Paper-family release")
 }
 for _,d:=range v.Dependencies{if d.Kind=="required"||d.Kind=="incompatible"{return modrinthFile{},errors.New("required or incompatible dependency needs manual review")}}
 var selected *modrinthFile
 for i:=range v.Files{if v.Files[i].Primary{if selected!=nil{return modrinthFile{},errors.New("ambiguous primary file")};selected=&v.Files[i]}}
 if selected==nil&&len(v.Files)==1{selected=&v.Files[0]}
 if selected==nil||!strings.HasSuffix(strings.ToLower(selected.Filename),".jar")||selected.Size<1||selected.Size>pluginLimit||!pluginURL(selected.URL,"cdn.modrinth.com")||!pluginHash.MatchString(p.PluginSHA512)||selected.Hashes["sha512"]!=p.PluginSHA512{
  return modrinthFile{},errors.New("selected JAR, size, CDN origin or confirmed SHA-512 is invalid")
 }
 return *selected,nil
}
var pluginClient=&http.Client{Timeout:70*time.Second,CheckRedirect:func(*http.Request,[]*http.Request)error{return errors.New("plugin redirects disabled")}}
func pluginGet(ctx context.Context,raw,host string)(io.ReadCloser,error){
 if !pluginURL(raw,host){return nil,errors.New("unapproved plugin origin")}
 req,e:=http.NewRequestWithContext(ctx,http.MethodGet,raw,nil);if e!=nil{return nil,e}
 req.Header.Set("User-Agent",userAgent)
 resp,e:=pluginClient.Do(req);if e!=nil{return nil,e}
 if resp.StatusCode!=200{resp.Body.Close();return nil,fmt.Errorf("plugin provider HTTP %d",resp.StatusCode)}
 return resp.Body,nil
}
func readPluginVersion(ctx context.Context,p jobPayload)(modrinthVersion,error){
 var v modrinthVersion
 if !pluginID.MatchString(p.PluginProject)||!pluginID.MatchString(p.PluginVersion){return v,errors.New("invalid project or version ID")}
 r,e:=pluginGet(ctx,"https://api.modrinth.com/v2/version/"+p.PluginVersion,"api.modrinth.com");if e!=nil{return v,e};defer r.Close()
 b,e:=io.ReadAll(io.LimitReader(r,2*1024*1024+1));if e!=nil{return v,e}
 if len(b)>2*1024*1024{return v,errors.New("plugin metadata too large")}
 e=json.Unmarshal(b,&v);return v,e
}
func openPluginDir(dir string,create bool)(*os.File,error){
 root,e:=openGameRoot(dir);if e!=nil{return nil,e};defer root.Close()
 if create{if e=syscall.Mkdirat(int(root.Fd()),"plugins",0700);e!=nil&&!errors.Is(e,syscall.EEXIST){return nil,e}}
 fd,e:=syscall.Openat(int(root.Fd()),"plugins",syscall.O_RDONLY|syscall.O_DIRECTORY|syscall.O_NOFOLLOW|syscall.O_CLOEXEC,0)
 if e!=nil{return nil,e}
 return os.NewFile(uintptr(fd),"plugins"),nil
}
func pluginNames(dir *os.File)([]string,error){
 // Reopen the pinned directory to avoid sharing its directory offset.
 d,e:=os.Open(fmt.Sprintf("/proc/self/fd/%d",dir.Fd()));if e!=nil{return nil,e};defer d.Close()
 names,e:=d.Readdirnames(4097);if e!=nil&&e!=io.EOF{return nil,e}
 if len(names)>4096{return nil,errors.New("plugin directory exceeds inspection limit")}
 var out []string
 for _,n:=range names{if managedPlugin.MatchString(n){out=append(out,n)}}
 return out,nil
}
func safePluginFile(dir *os.File,name string)error{
 if !managedPlugin.MatchString(name){return errors.New("not an OMCP-managed plugin")}
 fd,e:=syscall.Openat(int(dir.Fd()),name,syscall.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK|syscall.O_CLOEXEC,0)
 if e!=nil{return e}
 defer syscall.Close(fd)
 var st syscall.Stat_t
 if e=syscall.Fstat(fd,&st);e!=nil{return e}
 if st.Mode&syscall.S_IFMT!=syscall.S_IFREG||st.Nlink!=1||st.Size<1||st.Size>pluginLimit{return errors.New("invalid managed plugin file")}
 return nil
}
func validatePluginJar(path string)error{
 z,e:=zip.OpenReader(path);if e!=nil{return errors.New("download is not a valid JAR")};defer z.Close()
 if len(z.File)>100000{return errors.New("JAR has too many entries")}
 for _,f:=range z.File{if (f.Name=="plugin.yml"||f.Name=="paper-plugin.yml")&&!f.FileInfo().IsDir(){return nil}}
 return errors.New("JAR has no Paper/Bukkit plugin descriptor")
}
func installPlugin(ctx context.Context,dir *os.File,p jobPayload,src modrinthFile,body io.Reader)error{
 if !pluginID.MatchString(p.PluginProject)||!pluginID.MatchString(p.PluginVersion)||!pluginHash.MatchString(p.PluginSHA512){return errors.New("invalid plugin selection")}
 names,e:=pluginNames(dir);if e!=nil{return e}
 if len(names)>=40{return errors.New("managed plugin limit reached")}
 prefix:="omcp-"+p.PluginProject+"-"
 for _,n:=range names{if strings.HasPrefix(n,prefix){return errors.New("managed project already installed; no automatic overwrite")}}
 tmp,e:=os.CreateTemp(fmt.Sprintf("/proc/self/fd/%d",dir.Fd()),".omcp-plugin-");if e!=nil{return e}
 defer os.Remove(tmp.Name())
 h:=sha512.New()
 n,e:=io.Copy(io.MultiWriter(tmp,h),io.LimitReader(body,pluginLimit+1))
 if e==nil{e=tmp.Sync()}
 ce:=tmp.Close();if e!=nil{return e};if ce!=nil{return ce}
 if n!=src.Size||n>pluginLimit||hex.EncodeToString(h.Sum(nil))!=p.PluginSHA512{return errors.New("plugin size or SHA-512 mismatch")}
 if e=validatePluginJar(tmp.Name());e!=nil{return e}
 if e=ctx.Err();e!=nil{return e}
 dest:=filepath.Join(fmt.Sprintf("/proc/self/fd/%d",dir.Fd()),prefix+p.PluginVersion+".jar")
 // Link publishes without overwriting even if the destination appeared since inspection.
 if e=os.Link(tmp.Name(),dest);e!=nil{return e}
 if e=os.Remove(tmp.Name());e!=nil{return e}
 return dir.Sync()
}
func (f *fleet) pluginJob(ctx context.Context,s *supervisor,j job)(bool,string){
 if !f.allowPlugins||(f.backend!="docker"&&f.backend!="podman"){return false,"local container plugin manager opt-in required"}
 if s.running(){return false,"stop the server before managing plugins"}
 if e:=s.backend.requireAbsent();e!=nil{return false,e.Error()}
 p:=j.Payload
 if p.Software!="paper"&&p.Software!="purpur"{return false,"plugins require Paper or Purpur"}
 // Match the installed software provenance, not only the panel reservation.
 provenance,e:=readPrivateFile(filepath.Join(s.dir,"omcp-software.json"),8192);if e!=nil{return false,"installed software provenance unavailable"}
 var installed struct{Software,Version string}
 if json.Unmarshal(provenance,&installed)!=nil||installed.Software!=p.Software||installed.Version!=p.Version{return false,"installed software differs from reservation"}
 dir,e:=openPluginDir(s.dir,j.Action=="instance.plugin_install")
 if os.IsNotExist(e)&&j.Action=="instance.plugin_list"{return true,"[]"}
 if e!=nil{return false,e.Error()};defer dir.Close()
 if j.Action=="instance.plugin_list"{
  names,e:=pluginNames(dir);if e!=nil{return false,e.Error()}
  if len(names)>40{return false,"managed plugin inventory exceeds 40 entries"}
  out:=[]string{}
  for _,n:=range names{if e=safePluginFile(dir,n);e!=nil{return false,"unsafe managed plugin entry"};out=append(out,n)}
  b,e:=json.Marshal(out);if e!=nil||len(b)>4096{return false,"plugin inventory too large"};return true,string(b)
 }
 if !pluginID.MatchString(p.PluginProject)||!pluginID.MatchString(p.PluginVersion){return false,"invalid plugin IDs"}
 name:="omcp-"+p.PluginProject+"-"+p.PluginVersion+".jar"
 switch j.Action{
 case "instance.plugin_install":
  ctx,cancel:=context.WithTimeout(ctx,90*time.Second);defer cancel()
  v,e:=readPluginVersion(ctx,p);if e!=nil{return false,e.Error()}
  src,e:=selectPluginFile(v,p);if e!=nil{return false,e.Error()}
  body,e:=pluginGet(ctx,src.URL,"cdn.modrinth.com");if e!=nil{return false,e.Error()};defer body.Close()
  if e=installPlugin(ctx,dir,p,src,body);e!=nil{return false,e.Error()}
  return true,"verified plugin installed; restart to load"
 case "instance.plugin_enable","instance.plugin_disable","instance.plugin_delete":
  source,target:=name,name+".disabled"
  if j.Action=="instance.plugin_enable"{source,target=target,source}
  if j.Action=="instance.plugin_delete"&&p.PluginDisabled{source=target}
  if e=safePluginFile(dir,source);e!=nil{return false,e.Error()}
  if e=ctx.Err();e!=nil{return false,e.Error()}
  if j.Action=="instance.plugin_delete"{e=syscall.Unlinkat(int(dir.Fd()),source)}else{
   // Trusted offline operator only; no concurrent modification of game files.
   if e=safePluginFile(dir,target);!os.IsNotExist(e){return false,"destination already exists or is unsafe"}
   e=syscall.Renameat(int(dir.Fd()),source,int(dir.Fd()),target)
  }
  if e!=nil{return false,e.Error()}
  if e=dir.Sync();e!=nil{return false,e.Error()}
  return true,"plugin change saved; plugin data folders retained"
 default:return false,"unsupported plugin action"
 }
}
