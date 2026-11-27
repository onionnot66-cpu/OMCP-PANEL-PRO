package main

import (
 "strings"
 "sync"
)

// Fixed-size recent output buffer. Only accessible through authenticated admin jobs.
type consoleBuffer struct {
 mu sync.Mutex
 data []byte
}

const consoleLimit=3500

func(b *consoleBuffer) Write(p []byte)(int,error){
 b.mu.Lock()
 defer b.mu.Unlock()
 n:=len(p)
 if n>=consoleLimit {
  b.data=append(b.data[:0],p[n-consoleLimit:]...)
 }else{
  if excess:=len(b.data)+n-consoleLimit;excess>0{
   copy(b.data,b.data[excess:])
   b.data=b.data[:len(b.data)-excess]
  }
  b.data=append(b.data,p...)
 }
 return n,nil
}

func(b *consoleBuffer) snapshot()string{
 b.mu.Lock()
 defer b.mu.Unlock()
 result:=strings.ToValidUTF8(string(b.data),"")
 if strings.TrimSpace(result)==""{return "(no console output yet)"}
 return result
}
func(b *consoleBuffer) reset(){b.mu.Lock();defer b.mu.Unlock();b.data=b.data[:0]}
