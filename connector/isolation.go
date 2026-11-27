package main

import (
 "context"
 "errors"
 "fmt"
 "os"
 "os/exec"
 "regexp"
 "strconv"
 "strings"
 "time"
)

// Optional rootless Podman backend. No automatic fallback to native execution.
type containerRuntime struct {
 binary string
 image string
 name string
 port int
 memory int
 cpus int
}

var localImageID=regexp.MustCompile("^sha256:[0-9a-f]{64}$")

func checkContainerRuntime(image string,cpus int)(string,error){
 if os.Geteuid()==0 || !localImageID.MatchString(image) || cpus<1 || cpus>64 {
  return "",errors.New("rootless Podman requires a local sha256 image ID and 1-64 CPUs")
 }
 binary,err:=exec.LookPath("podman")
 if err!=nil{return "",err}
 ctx,cancel:=context.WithTimeout(context.Background(),15*time.Second);defer cancel()
 output,err:=exec.CommandContext(ctx,binary,"info","--format","{{.Host.Security.Rootless}} {{.Host.CgroupVersion}}").Output()
 if err!=nil || strings.TrimSpace(string(output))!="true v2"{return "",errors.New("rootless Podman and cgroup v2 required")}
 output,err=exec.CommandContext(ctx,binary,"image","inspect","--format","{{.Id}}",image).Output()
 if err!=nil || strings.TrimSpace(string(output))!=image{return "",errors.New("approved local image not found; no automatic pull")}
 return binary,nil
}

func(r *containerRuntime) args(dir string,heap int)[]string{
 return []string{"run","--rm","--interactive","--pull=never","--name",r.name,
  "--userns=keep-id","--user",fmt.Sprintf("%d:%d",os.Getuid(),os.Getgid()),
  "--cap-drop=ALL","--security-opt=no-new-privileges","--read-only",
  "--tmpfs","/tmp:rw,nosuid,nodev,size=256m","--pids-limit=512",
  "--memory",strconv.Itoa(r.memory)+"m","--memory-swap",strconv.Itoa(r.memory)+"m",
  "--cpus",strconv.Itoa(r.cpus),"--network=bridge",
  "--publish",fmt.Sprintf("%d:%d/tcp",r.port,r.port),
  "--volume",dir+":/server:rw,Z","--workdir","/server",
  "--env","HOME=/server","--entrypoint","java",r.image,
  fmt.Sprintf("-Xmx%dM",heap),"-jar","server.jar","nogui"}
}

func(r *containerRuntime) kill()error{
 ctx,cancel:=context.WithTimeout(context.Background(),10*time.Second);defer cancel()
 if err:=exec.CommandContext(ctx,r.binary,"kill",r.name).Run();err==nil{return nil}
 // Podman --rm may have already removed a naturally exited container.
 err:=exec.CommandContext(ctx,r.binary,"container","exists",r.name).Run()
 var status *exec.ExitError
 if errors.As(err,&status)&&status.ExitCode()==1{return nil}
 return errors.New("container termination not confirmed; inspect locally")
}

func(r *containerRuntime) requireAbsent()error{
 ctx,cancel:=context.WithTimeout(context.Background(),10*time.Second);defer cancel()
 err:=exec.CommandContext(ctx,r.binary,"container","exists",r.name).Run()
 var status *exec.ExitError
 if errors.As(err,&status)&&status.ExitCode()==1{return nil}
 if err==nil{return errors.New("container name already exists; reconcile locally before retry")}
 return errors.New("container state could not be verified")
}
