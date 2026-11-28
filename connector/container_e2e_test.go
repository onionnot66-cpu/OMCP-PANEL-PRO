//go:build container_e2e

package main

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestRootlessFleet(t *testing.T) {
	engine, image := os.Getenv("OMCP_TEST_ENGINE"), os.Getenv("OMCP_TEST_IMAGE")
	root := t.TempDir()
	if err := os.Chmod(root, 0700); err != nil {
		t.Fatal(err)
	}
	entries := []fleetEntry{{ID: workerJobID, Memory: 128}, {ID: "bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb", Memory: 128}}
	var sockets []net.Listener
	for i := range entries {
		l, err := net.Listen("tcp4", "127.0.0.1:0")
		if err != nil {
			t.Fatal(err)
		}
		sockets = append(sockets, l)
		entries[i].Port = l.Addr().(*net.TCPAddr).Port
		dir := filepath.Join(root, entries[i].ID)
		if err := os.Mkdir(dir, 0700); err != nil {
			t.Fatal(err)
		}
		for name, contents := range map[string]string{"server.jar": "fixture", "fixture-id": entries[i].ID, "server.properties": fmt.Sprintf("server-port=%d\n", entries[i].Port)} {
			if err := os.WriteFile(filepath.Join(dir, name), []byte(contents), 0600); err != nil {
				t.Fatal(err)
			}
		}
	}
	for _, l := range sockets {
		l.Close()
	}
	manifest := filepath.Join(t.TempDir(), "fleet.json")
	body, err := json.Marshal(entries)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(manifest, body, 0600); err != nil {
		t.Fatal(err)
	}
	f, err := openFleet(root, manifest, 1280, false, true)
	if err != nil {
		t.Fatal(err)
	}
	defer f.close()
	if err := f.enableContainers(engine, image, 1, 1280); err != nil {
		t.Fatal(err)
	}
	run := func(e fleetEntry, action string) string {
		t.Helper()
		ok, result := f.execute(context.Background(), job{Action: "instance." + action, Payload: jobPayload{InstanceID: e.ID, Memory: e.Memory, Port: e.Port, Runtime: engine}})
		if !ok {
			t.Fatalf("%s: %s", action, result)
		}
		return result
	}
	for _, e := range entries {
		run(e, "start")
	}
	for _, e := range entries {
		want := fmt.Sprintf("instance=%s memory=671088640 pids=512 cpu=100000 100000", e.ID)
		until := time.Now().Add(25 * time.Second)
		for !strings.Contains(run(e, "console"), want) {
			if !time.Now().Before(until) || !f.servers[e.ID].running() {
				t.Fatalf("missing enforced limits for %s: %s", e.ID, run(e, "console"))
			}
			time.Sleep(100 * time.Millisecond)
		}
	}
	ok, result := f.execute(context.Background(), job{Action: "instance.command", Payload: jobPayload{InstanceID: entries[0].ID, Memory: entries[0].Memory, Port: entries[0].Port, Runtime: engine, Command: "list"}})
	if !ok {
		t.Fatal(result)
	}
	until := time.Now().Add(10 * time.Second)
	for !strings.Contains(run(entries[0], "console"), "list response for "+entries[0].ID) {
		if time.Now().After(until) { t.Fatal("missing console command response") }
		time.Sleep(100 * time.Millisecond)
	}
	if strings.Contains(run(entries[1], "console"), "list response for") { t.Fatal("console command crossed instance boundary") }
	run(entries[0], "kill")
	if !f.servers[entries[1].ID].running() {
		t.Fatal("second instance stopped")
	}
	run(entries[1], "stop")
	for _, e := range entries {
		if err := f.servers[e.ID].backend.requireAbsent(); err != nil {
			t.Fatal(err)
		}
	}
}
