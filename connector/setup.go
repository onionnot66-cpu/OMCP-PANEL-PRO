package main

import (
 "crypto/sha256"
 "encoding/hex"
 "errors"
 "flag"
 "fmt"
 "io"
 "os"
 "path/filepath"
 "strings"
)

// Import an explicitly trusted local jar into a NEW directory. No downloads or arbitrary scripts.
func setupMinecraft(args []string) error {
 flags:=flag.NewFlagSet("setup",flag.ContinueOnError)
 dir:=flags.String("dir","","new Minecraft directory")
 jar:=flags.String("jar","","trusted local Minecraft server jar")
 checksum:=flags.String("sha256","","expected SHA-256 from a trusted source")
 port:=flags.Int("port",25565,"Minecraft TCP port")
 eula:=flags.Bool("accept-eula",false,"confirm you have read and accept the Minecraft EULA")
 if err:=flags.Parse(args);err!=nil{return err}
 if os.Geteuid()==0{return errors.New("run as a dedicated non-root user")}
 expected,err:=hex.DecodeString(*checksum)
 if err!=nil || len(expected)!=32 || *dir=="" || *jar=="" || *port<1024 || *port>65535 || flags.NArg()!=0{return errors.New("provide --dir, --jar, --sha256 and a valid unprivileged port")}
 if !*eula{return errors.New("read https://www.minecraft.net/eula and explicitly accept with --accept-eula")}
 if !available("java"){return errors.New("install the Java version required by your Minecraft jar first")}
 input,err:=os.Open(*jar)
 if err!=nil{return err}
 defer input.Close()
 info,err:=input.Stat()
 if err!=nil || !info.Mode().IsRegular() || info.Size()<1 || info.Size()>512*1024*1024{return errors.New("invalid jar file or size")}
 target,err:=filepath.Abs(*dir)
 if err!=nil{return err}
 if err=os.Mkdir(target,0700);err!=nil{return fmt.Errorf("setup requires a new directory: %w",err)}
 ok:=false
 defer func(){if !ok{_ = os.Remove(filepath.Join(target,"server.jar"));_ = os.Remove(filepath.Join(target,"eula.txt"));_ = os.Remove(filepath.Join(target,"server.properties"));_ = os.Remove(target)}}()
 output,err:=os.OpenFile(filepath.Join(target,"server.jar"),os.O_WRONLY|os.O_CREATE|os.O_EXCL,0600)
 if err!=nil{return err}
 h:=sha256.New()
 n,copyErr:=io.Copy(io.MultiWriter(output,h),io.LimitReader(input,512*1024*1024+1))
 closeErr:=output.Close()
 if copyErr!=nil{return copyErr}
 if closeErr!=nil{return closeErr}
 if n!=info.Size() || !strings.EqualFold(hex.EncodeToString(h.Sum(nil)),hex.EncodeToString(expected)){return errors.New("jar checksum or size mismatch")}
 if err=os.WriteFile(filepath.Join(target,"eula.txt"),[]byte("eula=true\n"),0600);err!=nil{return err}
 properties:=fmt.Sprintf("server-port=%d\nserver-ip=\nonline-mode=true\nenable-rcon=false\n",*port)
 if err=os.WriteFile(filepath.Join(target,"server.properties"),[]byte(properties),0600);err!=nil{return err}
 ok=true
 fmt.Println("Trusted Minecraft directory created:",target)
 return nil
}
