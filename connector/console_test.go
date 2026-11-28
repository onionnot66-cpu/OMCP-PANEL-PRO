package main

import (
 "strings"
 "testing"
 "unicode/utf8"
)

func TestConsoleBufferBounded(t *testing.T){
 var b consoleBuffer
 _,_=b.Write([]byte(strings.Repeat("a",consoleLimit+5)))
 _,_=b.Write([]byte("last"))
 got:=b.snapshot()
 if len(got)!=consoleLimit || !strings.HasSuffix(got,"last"){t.Fatal("buffer must retain bounded recent output")}
 _,_=b.Write([]byte{0xff,0xfe})
 if !utf8.ValidString(b.snapshot()){t.Fatal("console must be valid UTF-8")}
 b.reset()
 if b.snapshot()!="(no console output yet)"{t.Fatal("reset failed")}
}

func TestLiveGrantCursorExcludesPreviousOutput(t *testing.T){
 var b consoleBuffer
 _,_=b.Write([]byte("old owner secret"))
 mark:=b.cursor()
 if got:=b.snapshotSince(mark);got!="(no output since live console grant)"{t.Fatal(got)}
 _,_=b.Write([]byte("new owner log"))
 if got:=b.snapshotSince(mark);got!="new owner log"{t.Fatal(got)}
 next:=b.cursor()
 b.reset()
 _,_=b.Write([]byte("after restart"))
 if got:=b.snapshotSince(next);got!="after restart"{t.Fatal(got)}
}
