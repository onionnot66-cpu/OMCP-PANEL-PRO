package main

import (
 "strings"
 "testing"
)

func TestContainerArguments(t *testing.T){
 image:="sha256:"+strings.Repeat("a",64)
 r:=containerRuntime{image:image,name:"omcp-test",port:25566,memory:1536,cpus:2}
 args:=strings.Join(r.args("/private/server",1024)," ")
 for _,required:=range []string{"--pull=never","--userns=keep-id","--cap-drop=ALL","--security-opt=no-new-privileges","--read-only","--memory 1536m","--memory-swap 1536m","--cpus 2","--pids-limit=512","--publish 25566:25566/tcp","--volume /private/server:/server:rw,Z",image,"-Xmx1024M"}{
  if !strings.Contains(args,required){t.Errorf("missing %s",required)}
 }
 for _,forbidden:=range []string{"--privileged","--network=host","/var/run/docker.sock","/run/user/"}{
  if strings.Contains(args,forbidden){t.Errorf("unsafe argument %s",forbidden)}
 }
}
func TestLocalImageID(t *testing.T){
 if !localImageID.MatchString("sha256:"+strings.Repeat("a",64)){t.Fatal("valid image ID refused")}
 for _,s:=range []string{"latest","docker.io/library/eclipse-temurin:21","sha256:abc","--privileged"}{
  if localImageID.MatchString(s){t.Errorf("unapproved image reference %q accepted",s)}
 }
}
