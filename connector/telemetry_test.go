package main

import "testing"

func TestParseProcessTicks(t *testing.T){
 v,err:=parseProcessTicks("42 (Java worker) S 1 2 3 4 5 6 7 8 9 10 123 456 0")
 if err!=nil || v!=579{t.Fatalf("ticks=%d err=%v",v,err)}
 if _,err:=parseProcessTicks("bad");err==nil{t.Fatal("invalid stat accepted")}
}
func TestParseTotalTicks(t *testing.T){
 v,err:=parseTotalTicks("cpu 1 2 3 4 5 6 7 8 9 10\ncpu0 1 2 3")
 if err!=nil||v!=36{t.Fatalf("ticks=%d err=%v",v,err)}
}
func TestConsoleCommandAllowlist(t *testing.T){
 for _,v:=range []string{"list","save-all flush","say Hello","whitelist list"}{if !validConsoleCommand(v){t.Errorf("rejected %q",v)}}
 for _,v:=range []string{"op user","say ","say hi\nstop","say hi\r","/list","stop","shell ls"}{if validConsoleCommand(v){t.Errorf("accepted %q",v)}}
}
