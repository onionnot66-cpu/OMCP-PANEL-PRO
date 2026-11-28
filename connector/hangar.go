package main

import (
 "context"
 "encoding/json"
 "errors"
 "io"
 "regexp"
 "strconv"
 "strings"
)

var hangarID=regexp.MustCompile("^[1-9][0-9]{0,18}$")
var managedHangar=regexp.MustCompile("^omcp-hangar-([1-9][0-9]{0,18})-([1-9][0-9]{0,18})\\.jar(\\.disabled)?$")

type hangarDownload struct{
 ExternalURL string `json:"externalUrl"`
 DownloadURL string `json:"downloadUrl"`
 FileInfo *struct{
  Name string
  Size int64 `json:"sizeBytes"`
  SHA256 string `json:"sha256Hash"`
 } `json:"fileInfo"`
}
type hangarVersion struct{
 ID int64
 ProjectID int64 `json:"projectId"`
 Visibility string
 ReviewState string `json:"reviewState"`
 Channel struct{Name string;Flags []string}
 Downloads map[string]hangarDownload
 PlatformDependencies map[string][]string `json:"platformDependencies"`
 PluginDependencies map[string][]struct{Required bool} `json:"pluginDependencies"`
}
func selectHangarFile(v hangarVersion,p jobPayload)(hangarDownload,error){
 d,ok:=v.Downloads["PAPER"]
 if !hangarID.MatchString(p.PluginProject)||!hangarID.MatchString(p.PluginVersion)||strconv.FormatInt(v.ID,10)!=p.PluginVersion||strconv.FormatInt(v.ProjectID,10)!=p.PluginProject||
 v.Visibility!="public"||v.ReviewState!="reviewed"||!strings.EqualFold(v.Channel.Name,"release")||hasString(v.Channel.Flags,"UNSTABLE")||hasString(v.Channel.Flags,"HIDE_BY_DEFAULT")||
 !hasString(v.PlatformDependencies["PAPER"],p.Version)||!ok||d.FileInfo==nil||d.ExternalURL!=""{
  return hangarDownload{},errors.New("Hangar release is not eligible for automatic installation")
 }
 for _,dep:=range v.PluginDependencies["PAPER"]{if dep.Required{return hangarDownload{},errors.New("required dependency needs manual review")}}
 fi:=d.FileInfo
 if !strings.HasSuffix(strings.ToLower(fi.Name),".jar")||fi.Size<1||fi.Size>pluginLimit||
 !pluginURL(d.DownloadURL,"hangarcdn.papermc.io")||!worldDigest.MatchString(p.PluginSHA256)||fi.SHA256!=p.PluginSHA256{
  return hangarDownload{},errors.New("Hangar artifact metadata or SHA-256 mismatch")
 }
 return d,nil
}
func readHangarVersion(ctx context.Context,p jobPayload)(hangarVersion,error){
 var v hangarVersion
 if !hangarID.MatchString(p.PluginProject)||!hangarID.MatchString(p.PluginVersion){return v,errors.New("invalid Hangar IDs")}
 body,e:=pluginGet(ctx,"https://hangar.papermc.io/api/v1/versions/"+p.PluginVersion,"hangar.papermc.io");if e!=nil{return v,e};defer body.Close()
 b,e:=io.ReadAll(io.LimitReader(body,2*1024*1024+1));if e!=nil{return v,e}
 if len(b)>2*1024*1024{return v,errors.New("Hangar metadata too large")}
 e=json.Unmarshal(b,&v);return v,e
}
