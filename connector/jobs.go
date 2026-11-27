package main

import (
 "encoding/json"
 "fmt"
)

type job struct {
 ID string `json:"id"`
 Action string `json:"action"`
 Lease string `json:"lease"`
}

func executeJob(j job) (bool,string) {
 switch j.Action {
 case "diagnostic":
  result,err:=json.Marshal(capabilities())
  if err!=nil { return false,"diagnostic serialization failed" }
  return true,string(result)
 default:
  return false,"unsupported job action"
 }
}

func pollJob(c config) error {
 var out struct { Job *job `json:"job"` }
 if err:=call(c,"jobs/claim",c.Credential,map[string]any{"node_id":c.Node},&out); err!=nil {return err}
 if out.Job==nil {return nil}
 j:=*out.Job
 if j.ID=="" || len(j.Lease)!=64 {return fmt.Errorf("invalid job lease")}
 ok,result:=executeJob(j)
 var ack struct {OK bool `json:"ok"`}
 if err:=call(c,"jobs/complete",c.Credential,map[string]any{"node_id":c.Node,"id":j.ID,"lease":j.Lease,"success":ok,"result":result},&ack);err!=nil{return err}
 if !ack.OK {return fmt.Errorf("job result not acknowledged")}
 return nil
}
