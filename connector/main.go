package main

import (
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
 body, err := json.Marshal(payload)
 if err != nil { return err }
 req, err := http.NewRequest(http.MethodPost, c.Panel+"/api/omcp/v1/"+endpoint, bytes.NewReader(body))
 if err != nil { return err }
 req.Header.Set("Authorization", "Bearer "+token)
 req.Header.Set("Content-Type", "application/json")
 req.Header.Set("Accept", "application/json")
 resp, err := client.Do(req)
 if err != nil { return err }
 defer resp.Body.Close()
 if resp.StatusCode != 200 { return fmt.Errorf("%s: HTTP %d", endpoint, resp.StatusCode) }
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
 flag.Parse()
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
  info, err := os.Lstat(*path)
  if err != nil { return err }
  if !info.Mode().IsRegular() || info.Mode().Perm()&0077!=0 { return errors.New("config must be a private regular file") }
  b, err := os.ReadFile(*path)
  if err != nil { return err }
  if err := json.Unmarshal(b,&c); err != nil { return err }
 }
 if err := validate(c); err != nil { return err }
 if len(c.Credential)!=64 { return errors.New("invalid stored credential") }
 stop := make(chan os.Signal,1)
 signal.Notify(stop,os.Interrupt,syscall.SIGTERM)
 defer signal.Stop(stop)
 for {
  var out struct { OK bool `json:"ok"` }
  err := call(c,"heartbeat",c.Credential,map[string]any{"node_id":c.Node,"capabilities":capabilities()},&out)
  if err != nil { fmt.Fprintln(os.Stderr,err) } else if out.OK { fmt.Println("Heartbeat accepted") }
  select { case <-stop: return nil; case <-time.After(30*time.Second): }
 }
}

func main() {
 if err := run(); err != nil { fmt.Fprintln(os.Stderr,err); os.Exit(1) }
}
