package main

import (
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
)

// A supervisor owns stdin, logs and the process wait. Each backend owns its
// launch command and the checks required before touching offline server data.
type processRuntime interface {
	name() string
	command(dir string, heap int) (*exec.Cmd, error)
	requireAbsent() error
	kill() error
	reportLimits(map[string]any)
}

type nativeRuntime struct{}

func (nativeRuntime) name() string                { return "native" }
func (nativeRuntime) requireAbsent() error        { return nil }
func (nativeRuntime) kill() error                 { return nil } // Supervisor signals the owned JVM.
func (nativeRuntime) reportLimits(map[string]any) {}
func (nativeRuntime) command(dir string, heap int) (*exec.Cmd, error) {
	java, err := exec.LookPath("java")
	if err != nil {
		return nil, err
	}
	cmd := exec.Command(java, fmt.Sprintf("-Xmx%dM", heap), "-jar", "server.jar", "nogui")
	cmd.Dir = dir
	cmd.Env = []string{"PATH=/usr/local/bin:/usr/bin:/bin", "HOME=" + dir, "LANG=C.UTF-8"}
	return cmd, nil
}

func selectRuntime(requested string, single, fleet bool, podmanImage, image string) (string, error) {
	if requested == "" {
		switch {
		case podmanImage != "":
			requested = "podman"
		case single || fleet:
			requested = "native"
		default:
			requested = "none"
		}
	}
	switch requested {
	case "none":
		if single || fleet || image != "" || podmanImage != "" {
			return "", errors.New("control-only mode cannot configure a game runtime")
		}
	case "native":
		if single == fleet || podmanImage != "" || image != "" {
			return "", errors.New("native requires exactly one trusted directory or fleet and no container image")
		}
	case "podman", "docker":
		if !fleet || single || (image == "") == (podmanImage == "") || (requested == "docker" && podmanImage != "") {
			return "", errors.New("container runtime requires a fleet and one approved image")
		}
	default:
		return "", fmt.Errorf("unsupported runtime %q", requested)
	}
	return requested, nil
}

// The backend choice survives flag changes and daemon restarts. The fleet root
// is private operator storage. Only individual UUID subdirectories are mounted.
func bindFleetRuntime(root, backend string, explicit bool) error {
	path := filepath.Join(root, ".omcp-runtime")
	stored, err := readPrivateFile(path, 64)
	if err == nil {
		if string(stored) != backend+"\n" {
			return errors.New("fleet backend differs from its recorded runtime; reconcile locally before migration")
		}
		return nil
	}
	if !os.IsNotExist(err) {
		return err
	}
	if !explicit && backend == "native" {
		entries, err := os.ReadDir(root)
		if err != nil {
			return err
		}
		for _, entry := range entries {
			if instanceIDPattern.MatchString(entry.Name()) {
				return errors.New("existing fleet requires explicit --runtime for first adoption")
			}
		}
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0600)
	if err != nil {
		return err
	}
	if _, err = f.WriteString(backend + "\n"); err == nil {
		err = f.Sync()
	}
	closeErr := f.Close()
	if err != nil {
		return err
	}
	if closeErr != nil {
		return closeErr
	}
	d, err := os.Open(root)
	if err != nil {
		return err
	}
	defer d.Close()
	return d.Sync()
}

func readPrivateFile(path string, limit int64) ([]byte, error) {
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return nil, err
	}
	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok || !info.Mode().IsRegular() || info.Mode().Perm()&0077 != 0 || stat.Uid != uint32(os.Geteuid()) || stat.Nlink != 1 || info.Size() > limit {
		return nil, errors.New("expected a bounded private file owned by the connector account")
	}
	b, err := io.ReadAll(io.LimitReader(f, limit+1))
	if int64(len(b)) > limit {
		return nil, errors.New("private file exceeds limit")
	}
	return b, err
}

// Credentials and fleet authorization files must never reside inside a game mount.
func outsideGameDir(dir, path string) error {
	real, err := filepath.EvalSymlinks(path)
	if err != nil {
		return err
	}
	real, err = filepath.Abs(real)
	if err != nil {
		return err
	}
	rel, err := filepath.Rel(dir, real)
	if err != nil {
		return err
	}
	if rel != ".." && !strings.HasPrefix(rel, ".."+string(os.PathSeparator)) {
		return errors.New("private connector file is inside a game directory")
	}
	return nil
}
