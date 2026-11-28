//go:build minecraft_e2e

package main

import (
	"bufio"
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

// The build tag and per-run acknowledgement keep ordinary CI offline.
func TestVanillaWorldRestart(t *testing.T) {
	if os.Getenv("OMCP_TEST_ACCEPT_MINECRAFT_EULA") != "yes" {
		t.Fatal("explicit acceptance of the Minecraft EULA is required for this run")
	}
	const version = "1.21.4" // Java 21; protocol 769
	root := t.TempDir()
	t.Setenv("HOME", root)
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	listener.Close()

	dir := filepath.Join(root, "game")
	if err := downloadMinecraft([]string{"--software", "vanilla", "--version", version, "--dir", dir, "--port", strconv.Itoa(port), "--accept-eula"}); err != nil {
		t.Fatal(err)
	}
	props := filepath.Join(dir, "server.properties")
	b, err := os.ReadFile(props)
	if err != nil {
		t.Fatal(err)
	}
	config := strings.Replace(string(b), "server-ip=\n", "server-ip=127.0.0.1\n", 1)
	if config == string(b) {
		t.Fatal("could not restrict the game listener to loopback")
	}
	if err := os.WriteFile(props, []byte(config+"view-distance=3\nsimulation-distance=3\nspawn-protection=0\n"), 0600); err != nil {
		t.Fatal(err)
	}

	var originalRegion os.FileInfo
	var regionPath string
	for cycle := 1; cycle <= 2; cycle++ {
		t.Run(fmt.Sprintf("boot-%d", cycle), func(t *testing.T) {
			s, err := newSupervisor(dir, 1024)
			if err != nil {
				t.Fatal(err)
			}
			defer s.close()
			s.allowConsole = true
			if err := s.start(); err != nil {
				t.Fatal(err)
			}
			waitForGame(t, s, 3*time.Minute, func() bool { return strings.Contains(s.console.snapshot(), "For help, type") })
			if err := checkMinecraftStatus(port, version); err != nil {
				t.Fatal(err)
			}
			if ok, result := s.command("list"); !ok {
				t.Fatal(result)
			}
			waitForGame(t, s, 10*time.Second, func() bool { return strings.Contains(s.console.snapshot(), "players online") })
			if err := s.stop(false); err != nil {
				t.Fatal(err)
			}
			if !strings.Contains(s.console.snapshot(), "All dimensions are saved") {
				t.Fatal("missing graceful world-save confirmation")
			}
			if _, err := os.Stat(filepath.Join(dir, "world", "level.dat")); err != nil {
				t.Fatal(err)
			}
			if cycle == 1 {
				regions, err := filepath.Glob(filepath.Join(dir, "world", "region", "*.mca"))
				if err != nil || len(regions) == 0 {
					t.Fatal("no world regions saved", err)
				}
				regionPath = regions[0]
				originalRegion, err = os.Stat(regionPath)
				if err != nil || originalRegion.Size() <= 8192 {
					t.Fatal("no saved chunks", err)
				}
			} else {
				region, err := os.Stat(regionPath)
				if err != nil || !os.SameFile(originalRegion, region) || region.Size() <= 8192 {
					t.Fatal("original world region was lost", err)
				}
			}
		})
		if t.Failed() {
			return
		}
	}
}

func waitForGame(t *testing.T, s *supervisor, timeout time.Duration, ready func() bool) {
	t.Helper()
	for end := time.Now().Add(timeout); time.Now().Before(end); time.Sleep(200 * time.Millisecond) {
		if ready() {
			return
		}
		if !s.running() {
			t.Fatal("game exited:", s.console.snapshot())
		}
	}
	t.Fatal("Minecraft timed out:", s.console.snapshot())
}

// Java server-list handshake followed by a status request. Limit both lengths
// before allocating, and check the version carried by the actual response.
func checkMinecraftStatus(port int, version string) error {
	c, err := net.DialTimeout("tcp4", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)), 3*time.Second)
	if err != nil {
		return err
	}
	defer c.Close()
	if err := c.SetDeadline(time.Now().Add(5 * time.Second)); err != nil {
		return err
	}
	host := []byte("127.0.0.1")
	packet := binary.AppendUvarint([]byte{0}, 769)
	packet = append(packet, byte(len(host)))
	packet = append(packet, host...)
	packet = binary.BigEndian.AppendUint16(packet, uint16(port))
	packet = append(packet, 1)
	frame := append([]byte{byte(len(packet))}, packet...)
	if _, err := c.Write(append(frame, 1, 0)); err != nil {
		return err
	}
	r := bufio.NewReader(c)
	size, err := binary.ReadUvarint(r)
	if err != nil || size < 3 || size > 65536 {
		return errors.New("invalid status frame length")
	}
	body := make([]byte, int(size))
	if _, err := io.ReadFull(r, body); err != nil {
		return err
	}
	payload := bytes.NewReader(body[1:])
	length, err := binary.ReadUvarint(payload)
	if body[0] != 0 || err != nil || length != uint64(payload.Len()) {
		return errors.New("malformed status response")
	}
	var status struct {
		Version struct {
			Name     string
			Protocol int
		}
	}
	if err := json.NewDecoder(payload).Decode(&status); err != nil {
		return err
	}
	if status.Version.Name != version || status.Version.Protocol != 769 {
		return fmt.Errorf("unexpected Minecraft version %+v", status.Version)
	}
	return nil
}
