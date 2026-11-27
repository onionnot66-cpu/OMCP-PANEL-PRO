package main

import "testing"

func TestSafeDownloadURL(t *testing.T){
 for _,tc:=range []struct{url string;valid bool}{
  {"https://fill-data.papermc.io/v1/objects/test.jar",true},
  {"https://piston-data.mojang.com/v1/objects/test.jar",true},
  {"https://api.purpurmc.org/v2/purpur/1.21.4/123/download",true},
  {"http://api.purpurmc.org/a",false},
  {"https://api.purpurmc.org.evil.example/a",false},
  {"https://api.purpurmc.org:8443/a",false},
  {"https://user@api.purpurmc.org/a",false},
  {"https://127.0.0.1/a",false},
 }{
  if safeDownloadURL(tc.url)!=tc.valid{t.Errorf("unexpected URL validation: %s",tc.url)}
 }
}

func TestReleaseVersion(t *testing.T){
 for _,v:=range []string{"1.21.4","26.2","1.8.8"}{if !releaseVersion.MatchString(v){t.Errorf("rejected %s",v)}}
 for _,v:=range []string{"latest","../1.21","1.21-snapshot","1.21?x=1"}{if releaseVersion.MatchString(v){t.Errorf("accepted %s",v)}}
}
