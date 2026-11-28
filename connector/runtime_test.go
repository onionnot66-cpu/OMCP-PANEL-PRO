package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRuntimeSelectionAndPersistentBinding(t *testing.T) {
	image := "sha256:" + strings.Repeat("b", 64)
	for _, tc := range []struct {
		requested           string
		single, fleet       bool
		legacy, image, want string
	}{
		{"", false, false, "", "", "none"}, {"", true, false, "", "", "native"},
		{"", false, true, image, "", "podman"}, {"docker", false, true, "", image, "docker"},
		{"native", false, true, "", image, ""}, {"docker", false, true, "", "", ""},
		{"portable", true, false, "", "", ""}, {"podman", true, false, "", image, ""},
	} {
		got, err := selectRuntime(tc.requested, tc.single, tc.fleet, tc.legacy, tc.image)
		if got != tc.want || (err != nil) != (tc.want == "") {
			t.Fatalf("%+v: %s %v", tc, got, err)
		}
	}
	root := t.TempDir()
	if err := os.Mkdir(filepath.Join(root, workerJobID), 0700); err != nil {
		t.Fatal(err)
	}
	if bindFleetRuntime(root, "native", false) == nil {
		t.Fatal("unmarked existing fleet silently adopted as native")
	}
	if err := bindFleetRuntime(root, "podman", true); err != nil {
		t.Fatal(err)
	}
	if err := bindFleetRuntime(root, "podman", false); err != nil {
		t.Fatal(err)
	}
	if bindFleetRuntime(root, "native", true) == nil {
		t.Fatal("fleet runtime changed")
	}
	f := fleet{backend: "native"}
	if ok, reason := f.execute(context.Background(), job{Payload: jobPayload{Runtime: "podman"}}); ok || !strings.Contains(reason, "runtime") {
		t.Fatal("mismatched reservation dispatched", reason)
	}
}

func TestPrivateConfigAndMountBoundaries(t *testing.T) {
	root := t.TempDir()
	dir := filepath.Join(root, "game")
	if err := os.Mkdir(dir, 0700); err != nil {
		t.Fatal(err)
	}
	config := filepath.Join(root, "config")
	if err := os.WriteFile(config, []byte("fixture"), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := readPrivateFile(config, 2); err == nil {
		t.Fatal("oversized config")
	}
	if err := outsideGameDir(dir, config); err != nil {
		t.Fatal(err)
	}
	if err := outsideGameDir(root, config); err == nil {
		t.Fatal("game contains credentials")
	}
	link := filepath.Join(dir, "config-link")
	if err := os.Symlink(config, link); err != nil {
		t.Fatal(err)
	}
	if _, err := readPrivateFile(link, 64); err == nil {
		t.Fatal("symlinked private file accepted")
	}
	if err := os.Link(config, filepath.Join(dir, "copy")); err != nil {
		t.Fatal(err)
	}
	if _, err := readPrivateFile(config, 64); err == nil {
		t.Fatal("multiply linked private file accepted")
	}
}

func TestDockerCapabilitiesAndCommand(t *testing.T) {
	info := `{"SecurityOptions":["name=rootless"],"CgroupVersion":"2","CgroupDriver":"systemd","MemoryLimit":true,"SwapLimit":true,"PidsLimit":true,"CpuCfsQuota":true}`
	if !supportsRootlessDocker([]byte(info)) {
		t.Fatal("valid rootless engine rejected")
	}
	for _, bad := range []string{strings.Replace(info, "name=rootless", "name=seccomp", 1), strings.Replace(info, "true", "false", 1), strings.Replace(info, "systemd", "none", 1)} {
		if supportsRootlessDocker([]byte(bad)) {
			t.Fatal("unsupported engine accepted")
		}
	}
	r := &containerRuntime{engineCLI: engineCLI{engine: "docker", binary: "/usr/bin/docker", dockerConfig: "/private/cli"}, containerName: "omcp-test", image: "sha256:" + strings.Repeat("a", 64), memory: 768, cpus: 1, port: 25565}
	cmd, err := r.command("/srv/game", 256)
	if err != nil {
		t.Fatal(err)
	}
	args := strings.Join(cmd.Args, " ")
	for _, s := range []string{"--host unix:///run/user/", "--config /private/cli", "--pull=never", "--cap-drop=ALL", "--read-only", "--memory 768m", "--cpus 1", "--pids-limit=512", "--volume /srv/game:/server:rw,Z", "--label org.omcp.launch="} {
		if !strings.Contains(args, s) {
			t.Errorf("missing argument %s", s)
		}
	}
}
