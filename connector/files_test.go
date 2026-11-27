package main

import (
 "os"
 "path/filepath"
 "strings"
 "testing"
)

func TestManagedFileSafety(t *testing.T){
 dir:=t.TempDir()
 root,err:=openGameRoot(dir);if err!=nil{t.Fatal(err)};defer root.Close()
 name:="notes-test.txt"
 if err=writeGameFile(root,name,[]byte("hello"),"new");err!=nil{t.Fatal(err)}
 b,err:=readGameFile(root,name);if err!=nil||string(b)!="hello"{t.Fatalf("read: %q %v",b,err)}
 if err=writeGameFile(root,name,[]byte("again"),"new");err==nil{t.Fatal("overwrote existing file")}
 if err=writeGameFile(root,name,[]byte("again"),strings.Repeat("0",64));err==nil{t.Fatal("accepted stale digest")}
 if err=writeGameFile(root,name,[]byte("again"),digestFile(b));err!=nil{t.Fatal(err)}
 if _,err=readGameFile(root,"../secret");err==nil{t.Fatal("accepted traversal")}
 if err=writeGameFile(root,"server.properties",[]byte("online-mode=false"),"new");err==nil{t.Fatal("accepted raw properties write")}
 outside:=filepath.Join(t.TempDir(),"secret")
 if err=os.WriteFile(outside,[]byte("secret"),0600);err!=nil{t.Fatal(err)}
 if err=os.Symlink(outside,filepath.Join(dir,"notes-link.txt"));err!=nil{t.Fatal(err)}
 if _,err=readGameFile(root,"notes-link.txt");err==nil{t.Fatal("followed symlink")}
 if err=os.Link(outside,filepath.Join(dir,"notes-hard.txt"));err!=nil{t.Fatal(err)}
 if _,err=readGameFile(root,"notes-hard.txt");err==nil{t.Fatal("accepted hardlink")}
 if err=writeGameFile(root,"notes-big.txt",[]byte(strings.Repeat("a",fileLimit+1)),"new");err==nil{t.Fatal("accepted oversized file")}
}
func TestManagedFileNames(t *testing.T){
 for _,name:=range []string{"../../config.json","server.jar","eula.txt","omcp-software.json","plugins/x.yml","notes-a/../x.txt"}{
  if managedName(name){t.Errorf("accepted %q",name)}
 }
 if !managedName("notes-example.txt")||!managedName("bukkit.yml"){t.Fatal("valid names rejected")}
}
