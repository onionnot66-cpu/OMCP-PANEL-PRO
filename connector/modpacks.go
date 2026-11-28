package main

import (
 "archive/zip"
 "context"
 "crypto/sha512"
 "encoding/hex"
 "encoding/json"
 "errors"
 "io"
 "os"
 "path/filepath"
 "regexp"
 "strings"
 "time"
)

const modpackLimit=64*1024*1024
var loaderRelease=regexp.MustCompile("^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$")
var modpackLoaders=[]string{"fabric","forge","neoforge","quilt"}

type packIndex struct{
 FormatVersion int `json:"formatVersion"`
 Game string
 VersionID string
 Name string
 Dependencies map[string]string
 Files []struct{
  Path string
  Hashes map[string]string
  Env map[string]string
  Downloads []string
  FileSize int64 `json:"fileSize"`
 }
}
type packReport struct{
 Name string `json:"name"`
 Version string `json:"minecraft_version"`
 Loader string `json:"loader"`
 LoaderVersion string `json:"loader_version"`
 Required int `json:"required_server_files"`
 Optional int `json:"optional_server_files"`
 ClientOnly int `json:"client_only_files"`
 ServerBytes int64 `json:"server_download_bytes"`
 OverrideEntries int `json:"override_entries"`
 SHA512 string `json:"sha512"`
 State string `json:"state"`
}

func inspectModpack(path,minecraft,loader,digest string)(packReport,error){
 var out packReport
 z,e:=zip.OpenReader(path);if e!=nil{return out,e};defer z.Close()
 if len(z.File)>10000{return out,errors.New("too many pack archive entries")}
 seen:=map[string]bool{};var index *zip.File;var expanded uint64
 for _,f:=range z.File{
  name:=strings.TrimSuffix(f.Name,"/")
  if !validWorldPath(name)||seen[name]||(!f.Mode().IsRegular()&&!f.FileInfo().IsDir()){return out,errors.New("unsafe pack archive entry")}
  seen[name]=true
  if f.UncompressedSize64>uint64(worldBytes)-expanded{return out,errors.New("pack archive expansion limit exceeded")}
  expanded+=f.UncompressedSize64
  if name=="modrinth.index.json"&&!f.FileInfo().IsDir(){index=f}
  if strings.HasPrefix(name,"overrides/")||strings.HasPrefix(name,"server-overrides/"){out.OverrideEntries++}
 }
 if index==nil||index.UncompressedSize64>2*1024*1024{return out,errors.New("missing or oversized modrinth.index.json")}
 r,e:=index.Open();if e!=nil{return out,e};defer r.Close()
 raw,e:=io.ReadAll(io.LimitReader(r,2*1024*1024+1));if e!=nil{return out,e}
 if len(raw)>2*1024*1024{return out,errors.New("pack index too large")}
 var idx packIndex
 if e=json.Unmarshal(raw,&idx);e!=nil{return out,e}
 if idx.FormatVersion!=1||idx.Game!="minecraft"||idx.Dependencies["minecraft"]!=minecraft||len(idx.Files)>5000||len(idx.Name)>200||idx.Name==""{return out,errors.New("unsupported pack format or Minecraft version")}
 names:=map[string]string{"fabric":"fabric-loader","forge":"forge","neoforge":"neoforge","quilt":"quilt-loader"}
 dep,ok:=names[loader]
 if !ok||len(idx.Dependencies)!=2||!loaderRelease.MatchString(idx.Dependencies[dep]){return out,errors.New("pack must declare exactly one selected loader and Minecraft release")}
 paths:=map[string]bool{}
 for _,f:=range idx.Files{
  if !validWorldPath(f.Path)||paths[f.Path]||f.FileSize<1||f.FileSize>maxJar||!pluginHash.MatchString(f.Hashes["sha512"])||len(f.Downloads)<1||len(f.Downloads)>16{return out,errors.New("invalid pack file metadata")}
  paths[f.Path]=true
  // No individual URLs are fetched at this stage. Actual install needs its
  // own independently validated download-origin and extraction policy.
  switch f.Env["server"]{
  case "unsupported":out.ClientOnly++
  case "", "required":out.Required++;out.ServerBytes+=f.FileSize
  case "optional":out.Optional++;out.ServerBytes+=f.FileSize
  default:return out,errors.New("unknown server environment declaration")
  }
 }
 out.Name=idx.Name;out.Version=minecraft;out.Loader=loader;out.LoaderVersion=idx.Dependencies[dep];out.SHA512=digest;out.State="staged_not_installed"
 return out,nil
}

func selectModpackFile(v modrinthVersion,p jobPayload)(modrinthFile,error){
 if v.ID!=p.PluginVersion||v.Project!=p.PluginProject||v.Channel!="release"||(v.Status!=""&&v.Status!="listed")||!hasString(v.Versions,p.Version)||!hasString(modpackLoaders,p.ModpackLoader)||!hasString(v.Loaders,p.ModpackLoader){return modrinthFile{},errors.New("pack release does not match selected Minecraft version and loader")}
 var file *modrinthFile
 for i:=range v.Files{if v.Files[i].Primary{if file!=nil{return modrinthFile{},errors.New("ambiguous primary pack")};file=&v.Files[i]}}
 if file==nil&&len(v.Files)==1{file=&v.Files[0]}
 if file==nil||!strings.HasSuffix(strings.ToLower(file.Filename),".mrpack")||file.Size<1||file.Size>modpackLimit||!pluginURL(file.URL,"cdn.modrinth.com")||!pluginHash.MatchString(p.PluginSHA512)||file.Hashes["sha512"]!=p.PluginSHA512{return modrinthFile{},errors.New("invalid pack artifact selection")}
 return *file,nil
}
func(f *fleet) modpackJob(ctx context.Context,j job)(bool,string){
 if !f.allowModpacks||(f.backend!="docker"&&f.backend!="podman"){return false,"local modpack staging opt-in required"}
 p:=j.Payload
 if !instanceIDPattern.MatchString(p.InstanceID)||!hasString(modpackLoaders,p.ModpackLoader){return false,"invalid pack target"}
 ctx,cancel:=context.WithTimeout(ctx,3*time.Minute);defer cancel()
 dest:=filepath.Join(f.root,".omcp-staged-pack-"+p.InstanceID+".mrpack")
 if _,e:=os.Lstat(dest);!os.IsNotExist(e){return false,"staged package exists; archive or remove it locally before selecting another"}
 v,e:=readPluginVersion(ctx,p);if e!=nil{return false,e.Error()}
 src,e:=selectModpackFile(v,p);if e!=nil{return false,e.Error()}
 body,e:=pluginGet(ctx,src.URL,"cdn.modrinth.com");if e!=nil{return false,e.Error()};defer body.Close()
 tmp,e:=os.CreateTemp(f.root,".omcp-pack-");if e!=nil{return false,e.Error()};defer os.Remove(tmp.Name())
 h:=sha512.New()
 n,e:=io.Copy(io.MultiWriter(tmp,h),io.LimitReader(worldContextReader{ctx,body},modpackLimit+1))
 if e==nil{e=tmp.Sync()};ce:=tmp.Close()
 if e!=nil{return false,e.Error()};if ce!=nil{return false,ce.Error()}
 if n!=src.Size||hex.EncodeToString(h.Sum(nil))!=p.PluginSHA512{return false,"pack checksum or size mismatch"}
 report,e:=inspectModpack(tmp.Name(),p.Version,p.ModpackLoader,p.PluginSHA512);if e!=nil{return false,e.Error()}
 if e=ctx.Err();e!=nil{return false,e.Error()}
 if e=os.Link(tmp.Name(),dest);e!=nil{return false,e.Error()}
 if e=os.Remove(tmp.Name());e!=nil{return false,e.Error()}
 if e=syncDirectory(f.root);e!=nil{return false,e.Error()}
 b,e:=json.Marshal(report);if e!=nil{return false,e.Error()}
 return true,string(b)
}
