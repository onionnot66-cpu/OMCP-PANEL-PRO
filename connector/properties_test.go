package main

import (
 "strings"
 "testing"
)

func TestReplaceProperty(t *testing.T){
 input:="# settings\nonline-mode=true\nmax-players=20\n"
 got,err:=replaceProperty(input,"max-players","40")
 if err!=nil || !strings.Contains(got,"max-players=40\n") || !strings.Contains(got,"online-mode=true\n"){t.Fatalf("unexpected edit: %q %v",got,err)}
 if _,err=replaceProperty(input,"online-mode","false");err==nil{t.Fatal("security-sensitive setting must not be changed")}
 if _,err=replaceProperty(input,"max-players","20\nonline-mode=false");err==nil{t.Fatal("newline injection accepted")}
 if _,err=replaceProperty("max-players=1\nmax-players=2\n","max-players","3");err==nil{t.Fatal("duplicate accepted")}
}
