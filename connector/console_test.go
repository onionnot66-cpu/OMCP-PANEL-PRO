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
