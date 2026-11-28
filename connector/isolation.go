package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"regexp"
	"slices"
	"strconv"
	"strings"
	"syscall"
	"time"
)

var localImageID = regexp.MustCompile("^sha256:[0-9a-f]{64}$")
var containerID = regexp.MustCompile("^[0-9a-f]{64}$")

type engineCLI struct {
	engine, binary, dockerConfig string
}

func (e engineCLI) cmd(ctx context.Context, args ...string) *exec.Cmd {
	prefix := []string{"--remote=false"}
	if e.engine == "docker" {
		prefix = []string{"--config", e.dockerConfig, "--host", fmt.Sprintf("unix:///run/user/%d/docker.sock", os.Getuid())}
	}
	cmd := exec.CommandContext(ctx, e.binary, append(prefix, args...)...)
	if e.engine == "docker" {
		// A fresh CLI config prevents Docker from injecting user-level proxy
		// credentials into game containers. Always use the local user's socket.
		cmd.Env = []string{"PATH=" + os.Getenv("PATH"), "HOME=" + os.Getenv("HOME")}
	}
	return cmd
}

func (e engineCLI) close() {
	if e.dockerConfig != "" {
		_ = os.RemoveAll(e.dockerConfig)
	}
}

func checkContainerRuntime(engine, image string, cpus int) (engineCLI, error) {
	var e engineCLI
	if os.Geteuid() == 0 || (engine != "podman" && engine != "docker") || !localImageID.MatchString(image) || cpus < 1 || cpus > 64 {
		return e, errors.New("rootless engine, approved image digest and 1-64 CPUs required")
	}
	binary, err := exec.LookPath(engine)
	if err != nil {
		return e, err
	}
	e = engineCLI{engine: engine, binary: binary}
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	if engine == "podman" {
		out, err := e.cmd(ctx, "info", "--format", "json").Output()
		if err != nil || !supportsRootlessPodman(out) {
			return e, errors.New("local rootless Podman with delegated cgroup v2 CPU, memory and PID controllers required")
		}
	} else {
		info, err := os.Lstat(fmt.Sprintf("/run/user/%d/docker.sock", os.Getuid()))
		if err != nil || info.Mode()&os.ModeSocket == 0 || info.Sys().(*syscall.Stat_t).Uid != uint32(os.Geteuid()) {
			return e, errors.New("expected a local Docker socket owned by the connector user")
		}
		e.dockerConfig, err = os.MkdirTemp("", "omcp-docker-config-")
		if err != nil {
			return e, err
		}
		out, err := e.cmd(ctx, "info", "--format", "{{json .}}").Output()
		if err != nil || !supportsRootlessDocker(out) {
			e.close()
			return engineCLI{}, errors.New("rootless Docker with delegated cgroup v2 CPU, memory, swap and PID limits required")
		}
	}
	out, err := e.cmd(ctx, "image", "inspect", "--format", "{{.Id}}", image).Output()
	if err != nil || strings.TrimPrefix(strings.TrimSpace(string(out)), "sha256:") != strings.TrimPrefix(image, "sha256:") {
		e.close()
		return engineCLI{}, errors.New("approved local image unavailable")
	}
	return e, nil
}

func supportsRootlessPodman(data []byte) bool {
	var info struct {
		Host struct {
			Security          struct{ Rootless bool }
			CgroupVersion     string
			CgroupControllers []string
		}
	}
	if json.Unmarshal(data, &info) != nil || !info.Host.Security.Rootless || info.Host.CgroupVersion != "v2" {
		return false
	}
	controllers := info.Host.CgroupControllers
	return slices.Contains(controllers, "cpu") && slices.Contains(controllers, "memory") && slices.Contains(controllers, "pids")
}

func supportsRootlessDocker(data []byte) bool {
	var info struct {
		SecurityOptions                                []string
		CgroupVersion, CgroupDriver                    string
		MemoryLimit, SwapLimit, PidsLimit, CPUCfsQuota bool
	}
	if json.Unmarshal(data, &info) != nil || info.CgroupVersion != "2" || info.CgroupDriver != "systemd" || !info.MemoryLimit || !info.SwapLimit || !info.PidsLimit || !info.CPUCfsQuota {
		return false
	}
	for _, option := range info.SecurityOptions {
		if option == "name=rootless" {
			return true
		}
	}
	return false
}

type containerRuntime struct {
	engineCLI
	image, containerName, launchID string
	port, memory, cpus             int
}

func (r *containerRuntime) name() string { return r.engine }

func (r *containerRuntime) args(dir string, heap int) []string {
	args := []string{"run", "--rm", "--interactive", "--pull=never", "--name", r.containerName, "--label", "org.omcp.launch=" + r.launchID}
	if r.engine == "podman" {
		args = append(args, "--userns=keep-id", "--user", fmt.Sprintf("%d:%d", os.Getuid(), os.Getgid()), "--http-proxy=false")
	} else {
		// Inside rootless Docker, container UID 0 maps to the unprivileged
		// daemon owner, who owns the private bind-mounted instance directory.
		args = append(args, "--user", "0:0")
	}
	return append(args, "--cap-drop=ALL", "--security-opt=no-new-privileges", "--read-only",
		"--tmpfs", "/tmp:rw,nosuid,nodev,size=256m", "--pids-limit=512",
		"--memory", strconv.Itoa(r.memory)+"m", "--memory-swap", strconv.Itoa(r.memory+128)+"m",
		"--cpus", strconv.Itoa(r.cpus), "--network=bridge", "--publish", fmt.Sprintf("%d:%d/tcp", r.port, r.port),
		"--volume", dir+":/server:rw,Z", "--workdir", "/server", "--env", "HOME=/server", "--entrypoint", "java", r.image,
		fmt.Sprintf("-Xmx%dM", heap), "-jar", "server.jar", "nogui")
}

func (r *containerRuntime) command(dir string, heap int) (*exec.Cmd, error) {
	var nonce [16]byte
	if _, err := rand.Read(nonce[:]); err != nil {
		return nil, err
	}
	r.launchID = hex.EncodeToString(nonce[:])
	return r.cmd(context.Background(), r.args(dir, heap)...), nil
}

func (r *containerRuntime) reportLimits(report map[string]any) {
	report["runtime"] = r.engine
	report["container_memory_limit_mib"] = r.memory
	report["container_cpu_limit"] = r.cpus
}

func (r *containerRuntime) requireAbsent() error {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	// Engine name filters differ in their treatment of a leading slash. Check
	// exact returned names, including stopped containers, on both engines.
	out, err := r.cmd(ctx, "ps", "--all", "--filter", "name="+r.containerName, "--format", "{{.Names}}").Output()
	if err != nil {
		return errors.New("container state could not be verified")
	}
	for _, name := range strings.Fields(string(out)) {
		if name == r.containerName {
			return errors.New("container already exists; reconcile locally before retry")
		}
	}
	return nil
}

func (r *containerRuntime) kill() error {
	if r.launchID == "" {
		return errors.New("missing active launch identity")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	out, err := r.cmd(ctx, "ps", "--all", "--no-trunc", "--filter", "label=org.omcp.launch="+r.launchID, "--format", "{{.ID}}").Output()
	if err != nil {
		return errors.New("unable to identify attached container")
	}
	id := strings.TrimSpace(string(out))
	if id == "" {
		return r.requireAbsent()
	}
	if !containerID.MatchString(id) {
		return errors.New("ambiguous container identity")
	}
	if err := r.cmd(ctx, "kill", id).Run(); err != nil {
		return r.requireAbsent()
	}
	return nil
}
