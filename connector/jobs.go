package main

import (
 "context"
 "encoding/json"
 "os/exec"
 "time"
 "strings"
 "fmt"
)

type job struct {
 ID string `json:"id"`
 Action string `json:"action"`
 Lease string `json:"lease"`
 Payload jobPayload `json:"payload"`
}

func executeJob(j job) (bool,string) {
 switch j.Action {
 case "diagnostic":
  report:=capabilities()
  if java,err:=exec.LookPath("java");err==nil {
   ctx,cancel:=context.WithTimeout(context.Background(),5*time.Second)
   defer cancel()
   cmd:=exec.CommandContext(ctx,java,"-version")
   var output consoleBuffer
   cmd.Stdout=&output;cmd.Stderr=&output
   if cmd.Run()==nil {report["java_version"]=strings.ToValidUTF8(output.snapshot(),"")} else {report["java_version"]="unavailable"}
  }
  result,err:=json.Marshal(report)
  if err!=nil { return false,"diagnostic serialization failed" }
  return true,string(result)
 default:
  return false,"unsupported job action"
 }
}

func pollJob(c config, native *supervisor, multi *fleet) error {
 var out struct { Job *job `json:"job"` }
 if err:=call(c,"jobs/claim",c.Credential,map[string]any{"node_id":c.Node},&out); err!=nil {return err}
 if out.Job==nil {return nil}
 j:=*out.Job
 if j.ID=="" || len(j.Lease)!=64 {return fmt.Errorf("invalid job lease")}
 ok,result:=executeJob(j)
 if native!=nil && strings.HasPrefix(j.Action,"native.") {ok,result=native.executeJob(j)}
 if multi!=nil && strings.HasPrefix(j.Action,"instance.") {ok,result=multi.execute(j)}
 var ack struct {OK bool `json:"ok"`}
 if err:=call(c,"jobs/complete",c.Credential,map[string]any{"node_id":c.Node,"id":j.ID,"lease":j.Lease,"success":ok,"result":result},&ack);err!=nil{return err}
 if !ack.OK {return fmt.Errorf("job result not acknowledged")}
 return nil
}
