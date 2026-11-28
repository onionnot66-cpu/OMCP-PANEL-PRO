package main

import (
 "context"
 "encoding/json"
 "testing"
)

func TestExecuteJob(t *testing.T) {
 ok,result:=executeJob(context.Background(),job{Action:"diagnostic"})
 if !ok || !json.Valid([]byte(result)) {t.Fatal("diagnostic must return valid JSON")}
 ok,_=executeJob(context.Background(),job{Action:"shell"})
 if ok {t.Fatal("unrecognized actions must be rejected")}
}
