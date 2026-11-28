package main

import "testing"

func TestTrustedOwnerActionGate(t *testing.T){
 f:=fleet{backend:"docker"}
 if f.ownerActionAllowed("instance.start"){t.Fatal("default enabled")}
 f.allowOwnerControls=true
 for _,action:=range []string{"instance.start","instance.stop","instance.restart","instance.status","instance.metrics","instance.console"}{
  if !f.ownerActionAllowed(action){t.Fatalf("denied %s",action)}
 }
 for _,action:=range []string{"instance.install","instance.kill","instance.retire","instance.file_write","instance.command"}{
  if f.ownerActionAllowed(action){t.Fatalf("allowed %s",action)}
 }
 f.allowConsole=true
 if !f.ownerActionAllowed("instance.command"){t.Fatal("console opt-in not honored")}
 f.backend="native"
 if f.ownerActionAllowed("instance.start"){t.Fatal("native owner action accepted")}
}
