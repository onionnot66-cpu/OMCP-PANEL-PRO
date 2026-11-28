// A synthetic container workload for CI. Observes its own cgroup and mount.
package main

import (
	"bufio"
	"fmt"
	"os"
	"strings"
	"syscall"
)

func read(path string) string {
	b, err := os.ReadFile(path)
	if err != nil {
		panic(err)
	}
	return strings.TrimSpace(string(b))
}

func main() {
	var fs syscall.Statfs_t
	if err := syscall.Statfs("/", &fs); err != nil {
		panic(err)
	}
	status := read("/proc/self/status")
	if fs.Flags&1 == 0 || !strings.Contains(status, "NoNewPrivs:\t1") || !strings.Contains(status, "CapEff:\t0000000000000000") {
		panic("missing requested process confinement")
	}
	fmt.Printf("instance=%s memory=%s pids=%s cpu=%s\n", read("/server/fixture-id"), read("/sys/fs/cgroup/memory.max"), read("/sys/fs/cgroup/pids.max"), read("/sys/fs/cgroup/cpu.max"))
	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		if scanner.Text() == "list" {
			fmt.Printf("list response for %s\n", read("/server/fixture-id"))
		}
		if scanner.Text() == "stop" {
			return
		}
	}
}
