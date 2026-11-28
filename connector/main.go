package main

import (
 "context"
 "bufio"
 "bytes"
 "encoding/json"
 "errors"
 "flag"
 "fmt"
 "io"
 "net/http"
 "net/url"
 "os"
 "os/exec"
 "os/signal"
 "path/filepath"
 "runtime"
 "strings"
 "syscall"
 "time"
)

type config struct {
 Panel string `json:"panel"`
 Node int `json:"node_id"`
 Credential string `json:"credential"`
}

var client = &http.Client{
 Timeout: 15 * time.Second,
 CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
}

func call(c config, endpoint, token string, payload any, result any) error {
 return callContext(context.Background(),c,endpoint,token,payload,result)
}

type panelHTTPError struct { endpoint string; status int }
func (e panelHTTPError) Error() string {return fmt.Sprintf("%s: HTTP %d", e.endpoint, e.status)}

func callContext(ctx context.Context,c config, endpoint, token string, payload any, result any) error {
 body, err := json.Marshal(payload)
 if err != nil { return err }
 req, err := http.NewRequestWithContext(ctx,http.MethodPost, c.Panel+"/api/omcp/v1/"+endpoint, bytes.NewReader(body))
 if err != nil { return err }
 req.Header.Set("Authorization", "Bearer "+token)
 req.Header.Set("Content-Type", "application/json")
 req.Header.Set("Accept", "application/json")
 resp, err := client.Do(req)
 if err != nil { return err }
 defer resp.Body.Close()
 if resp.StatusCode != 200 { return panelHTTPError{endpoint,resp.StatusCode} }
 return json.NewDecoder(io.LimitReader(resp.Body, 65536)).Decode(result)
}

func validate(c config) error {
 u, err := url.Parse(c.Panel)
 if err != nil || u.Scheme != "https" || u.Hostname() == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Path != "" && u.Path != "/") || c.Node < 1 {
  return errors.New("HTTPS panel origin and positive node ID required")
 }
 return nil
}

func available(name string) bool { _, err := exec.LookPath(name); return err == nil }

func capabilities() map[string]any {
 return map[string]any{"os":runtime.GOOS,"arch":runtime.GOARCH,"cpus":runtime.NumCPU(),"root":os.Geteuid()==0,"docker_cli":available("docker"),"systemd_cli":available("systemctl"),"java_cli":available("java")}
}

func save(path string, c config) error {
 if err := os.MkdirAll(filepath.Dir(path),0700); err != nil { return err }
 b, err := json.Marshal(c)
 if err != nil { return err }
 f, err := os.OpenFile(path,os.O_WRONLY|os.O_CREATE|os.O_EXCL,0600)
 if err != nil { return err }
 if _, err = f.Write(b); err != nil { f.Close(); os.Remove(path); return err }
 if err = f.Close(); err != nil { os.Remove(path) }
 return err
}

func run() error {
 home, err := os.UserHomeDir()
 if err != nil { return err }
 path := flag.String("config",filepath.Join(home,".omcp-connect","config.json"),"private credential file")
 panel := flag.String("panel","","HTTPS panel origin")
 node := flag.Int("node",0,"node ID")
 enroll := flag.Bool("enroll",false,"enroll a new connector; read token from stdin")
 trustedDir := flag.String("trusted-native-dir", "", "opt-in local trusted Minecraft directory; never use for untrusted tenants")
 trustedMemory := flag.Int("trusted-native-memory", 1024, "Java heap MiB, not a hard limit")
 provision := flag.Bool("allow-native-provision", false, "allow admin to create only the configured new trusted directory")
 allowConsole := flag.Bool("allow-native-console",false,"enable restricted Minecraft console commands from admin")
 fleetRoot:=flag.String("trusted-fleet-root","","private directory for locally authorized instances")
 fleetManifest:=flag.String("trusted-fleet-manifest","","private JSON allowlist of instance IDs, ports and heap budgets")
 fleetBudget:=flag.Int("trusted-fleet-memory",0,"total permitted Java heap MiB")
 selectedRuntime:=flag.String("runtime","","none, native, podman, or docker (rootless engines only)")
 imageID:=flag.String("fleet-image","","approved local image ID for the selected container runtime")
 fleetImage:=flag.String("fleet-podman-image","","optional approved local rootless Podman image ID (sha256:...)")
 fleetCPUs:=flag.Int("fleet-podman-cpus",2,"container CPU limit per instance")
 allowFiles:=flag.Bool("allow-fleet-files",false,"enable bounded offline admin text file operations")
 allowOwnerControls:=flag.Bool("allow-trusted-owner-controls",false,"allow restricted power and console for explicitly trusted owners of container fleets")
 allowPlugins:=flag.Bool("allow-fleet-plugins",false,"enable offline trusted admin Modrinth plugin management on container fleets")
 allowWorlds:=flag.Bool("allow-fleet-worlds",false,"enable offline trusted admin world backups and restore on container fleets")
 allowVersions:=flag.Bool("allow-fleet-versions",false,"enable offline trusted admin same-software version upgrades")
 flag.Parse()
 backend,err:=selectRuntime(*selectedRuntime,*trustedDir!="",*fleetRoot!="",*fleetImage,*imageID);if err!=nil{return err}
 if *allowVersions && (backend!="docker" && backend!="podman"){return errors.New("version management requires a container fleet")}
 if *allowWorlds && (backend!="docker" && backend!="podman"){return errors.New("world management requires a container fleet")}
 if *allowPlugins && (backend!="docker" && backend!="podman"){return errors.New("plugin management requires a container fleet")}
 if *allowOwnerControls && (backend!="docker" && backend!="podman"){return errors.New("trusted owner controls require a container fleet")}
 if *allowFiles && *fleetRoot==""{return errors.New("file manager requires trusted fleet mode")}
 if *fleetImage!="" && *fleetRoot==""{return errors.New("Podman requires trusted fleet mode")}
 if (*fleetRoot=="")!=(*fleetManifest=="") || (*fleetRoot!="" && *trustedDir!=""){return errors.New("choose single native mode or a complete fleet configuration")}
 if *allowConsole && *trustedDir=="" && *fleetRoot==""{return errors.New("console requires --trusted-native-dir")}
 if *provision && *trustedDir=="" && *fleetRoot=="" {return errors.New("provision requires --trusted-native-dir")}
 var c config
 if *enroll {
  c = config{Panel:strings.TrimRight(*panel,"/"),Node:*node}
  if err := validate(c); err != nil { return err }
  if _, err := os.Lstat(*path); !os.IsNotExist(err) { return errors.New("config already exists or cannot be inspected") }
  fmt.Fprintln(os.Stderr,"Enter one-time token via private stdin (terminal input may echo):")
  scanner := bufio.NewScanner(os.Stdin)
  if !scanner.Scan() { return errors.New("missing enrollment token") }
  token := strings.TrimSpace(scanner.Text())
  if len(token)!=64 { return errors.New("invalid enrollment token length") }
  var out struct { Credential string `json:"credential"` }
  if err := call(c,"enroll",token,map[string]any{"node_id":c.Node},&out); err != nil { return err }
  if len(out.Credential)!=64 { return errors.New("invalid credential response") }
  c.Credential=out.Credential
  if err := save(*path,c); err != nil { return fmt.Errorf("enrollment consumed; rotate token before retry: %w",err) }
  fmt.Println("Enrollment saved.")
 } else {
  b, err := readPrivateFile(*path,65536)
  if err != nil { return err }
  if err := json.Unmarshal(b,&c); err != nil { return err }
 }
 if err := validate(c); err != nil { return err }
 if len(c.Credential)!=64 { return errors.New("invalid stored credential") }
 var native *supervisor
 if *trustedDir!="" {
  native,err=openSupervisor(*trustedDir,*trustedMemory,*provision)
  if err!=nil{return err}
  native.allowConsole=*allowConsole
  defer native.close()
  if err=outsideGameDir(native.dir,*path);err!=nil{return err}
 }
 var multi *fleet
 if *fleetRoot!=""{
  multi,err=openFleet(*fleetRoot,*fleetManifest,*fleetBudget,*provision,*allowConsole)
  if err!=nil{return err}
  defer multi.close()
  multi.allowFiles=*allowFiles
  multi.allowOwnerControls=*allowOwnerControls
  multi.allowPlugins=*allowPlugins
  multi.allowWorlds=*allowWorlds
  multi.allowVersions=*allowVersions
  multi.transferConfig=c
  for _,s:=range multi.servers{s.allowFiles=*allowFiles}
  for _,s:=range multi.servers{
   if err=outsideGameDir(s.dir,*path);err!=nil{return err}
   if err=outsideGameDir(s.dir,*fleetManifest);err!=nil{return err}
  }
  if backend=="podman" || backend=="docker"{
   image:=*imageID;if image==""{image=*fleetImage}
   if err=multi.enableContainers(backend,image,*fleetCPUs,*fleetBudget);err!=nil{return err}
  }
  if err=bindFleetRuntime(multi.root,backend,*selectedRuntime!="" || *fleetImage!="");err!=nil{return err}
 }
 ctx,cancel:=signal.NotifyContext(context.Background(),os.Interrupt,syscall.SIGTERM)
 defer cancel()
 return serveConnector(ctx,c,native,multi,5*time.Second,dispatcher(native,multi))
}

func main() {
 if len(os.Args)>1 && os.Args[1]=="properties" {
  if err:=editProperties(os.Args[2:]);err!=nil{fmt.Fprintln(os.Stderr,err);os.Exit(1)}
  return
 }
 if len(os.Args)>1 && os.Args[1]=="download" {
  if err:=downloadMinecraft(os.Args[2:]);err!=nil{fmt.Fprintln(os.Stderr,err);os.Exit(1)}
  return
 }
 if len(os.Args)>1 && os.Args[1]=="setup" {
  if err:=setupMinecraft(os.Args[2:]);err!=nil{fmt.Fprintln(os.Stderr,err);os.Exit(1)}
  return
 }
 if len(os.Args)>1 && os.Args[1]=="native" {
  if err:=nativeRun(os.Args[2:]); err!=nil { fmt.Fprintln(os.Stderr,err); os.Exit(1) }
  return
 }
 if err := run(); err != nil { fmt.Fprintln(os.Stderr,err); os.Exit(1) }
}
