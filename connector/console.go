package main

import (
 "strings"
 "sync"
)

// Fixed-size recent output buffer. Only accessible through authenticated admin jobs.
type consoleBuffer struct {
 mu sync.Mutex
 data []byte
 written uint64
}

const consoleLimit=3500

func(b *consoleBuffer) Write(p []byte)(int,error){
 b.mu.Lock()
 defer b.mu.Unlock()
 n:=len(p)
 b.written+=uint64(n)
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

func(b *consoleBuffer) cursor()uint64{
 b.mu.Lock();defer b.mu.Unlock()
 return b.written
}

// Return only the retained suffix written since the current owner's grant.
// Reset drops retained bytes but never resets the absolute cursor.
func(b *consoleBuffer) snapshotSince(mark uint64)string{
 b.mu.Lock();defer b.mu.Unlock()
 if mark>=b.written||len(b.data)==0{return "(no output since live console grant)"}
 n:=b.written-mark
 if n>uint64(len(b.data)){n=uint64(len(b.data))}
 result:=strings.ToValidUTF8(string(b.data[len(b.data)-int(n):]),"")
 if strings.TrimSpace(result)==""{return "(no output since live console grant)"}
 return result
}
