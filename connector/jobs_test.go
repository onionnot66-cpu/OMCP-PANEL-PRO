package main

import (
 "encoding/json"
 "testing"
)

func TestExecuteJob(t *testing.T) {
 ok,result:=executeJob(job{Action:"diagnostic"})
 if !ok || !json.Valid([]byte(result)) {t.Fatal("diagnostic must return valid JSON")}
 ok,_=executeJob(job{Action:"shell"})
 if ok {t.Fatal("unrecognized actions must be rejected")}
}
