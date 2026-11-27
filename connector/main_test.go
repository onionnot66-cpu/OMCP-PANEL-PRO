package main

import "testing"

func TestValidate(t *testing.T) {
 for _, tt := range []struct{ panel string; node int; valid bool }{
  {"https://panel.example.com",1,true},
  {"http://panel.example.com",1,false},
  {"https://user:pass@panel.example.com",1,false},
  {"https://panel.example.com/path",1,false},
  {"https://panel.example.com",0,false},
 } {
  if (validate(config{Panel:tt.panel,Node:tt.node})==nil)!=tt.valid { t.Errorf("unexpected validation result for %s",tt.panel) }
 }
}
