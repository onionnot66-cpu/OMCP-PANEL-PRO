package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"time"
)

// One worker owns mutations. Heartbeats continue even when the worker is
// downloading or waiting for a process to exit.
func serveConnector(ctx context.Context, c config, native *supervisor, multi *fleet, interval time.Duration, execute jobExecutor) error {
	ticks := time.NewTicker(interval)
	defer ticks.Stop()
	var active chan error
	defer func() {
		if active != nil {
			<-active // worker drains before its supervisor or lock is closed
		}
	}()
	for ctx.Err() == nil {
		select {
		case err := <-active:
			if err != nil {
				fmt.Fprintln(os.Stderr, "Job:", err)
			}
			active = nil
		default:
		}
		caps := capabilities()
		caps["fleet_files"] = multi != nil && multi.allowFiles
		caps["fleet_live_console"] = multi != nil && multi.allowLiveConsole && multi.allowOwnerControls && (multi.backend == "docker" || multi.backend == "podman")
		caps["fleet_modpacks"] = multi != nil && multi.allowModpacks && (multi.backend == "docker" || multi.backend == "podman")
		caps["fleet_versions"] = multi != nil && multi.allowVersions && (multi.backend == "docker" || multi.backend == "podman")
		caps["fleet_worlds"] = multi != nil && multi.allowWorlds && (multi.backend == "docker" || multi.backend == "podman")
		caps["fleet_plugins"] = multi != nil && multi.allowPlugins && (multi.backend == "docker" || multi.backend == "podman")
		caps["fleet_console_input"] = multi != nil && multi.allowConsole
		caps["fleet_retire"] = multi != nil && (multi.backend == "podman" || multi.backend == "docker")
		caps["fleet_owner_controls"] = multi != nil && multi.allowOwnerControls && (multi.backend == "docker" || multi.backend == "podman")
		caps["fleet_enabled"] = multi != nil
		caps["fleet_provision"] = multi != nil && multi.provision
		caps["native_enabled"] = native != nil
		caps["native_provision"] = native != nil && native.provision
		caps["native_console_input"] = native != nil && native.allowConsole
		payload := map[string]any{"node_id": c.Node, "capabilities": caps}
		runtimeName := "none"
		if native != nil {
			runtimeName = "native"
		} else if multi != nil {
			runtimeName = multi.backend
		}
		payload["runtime"] = runtimeName
		if multi != nil && multi.allowLiveConsole && multi.allowOwnerControls && (multi.backend == "docker" || multi.backend == "podman") {
			snapshots := make([]map[string]string, 0, len(multi.servers))
			for id, server := range multi.servers {
				snapshots = append(snapshots, map[string]string{"instance_id": id, "output": server.console.snapshot()})
			}
			payload["fleet_console"] = snapshots
		}
		if native != nil && native.mu.TryLock() {
			payload["telemetry"] = map[string]any{"console": native.console.snapshot(), "metrics": native.metricReport()}
			native.mu.Unlock()
		}
		var ack struct{ OK bool }
		err := callContext(ctx, c, "heartbeat", c.Credential, payload, &ack)
		if err != nil && ctx.Err() == nil {
			fmt.Fprintln(os.Stderr, "Heartbeat:", err)
		}
		if err == nil && ack.OK && active == nil {
			active = make(chan error, 1)
			go func(done chan<- error) { done <- pollJob(ctx, c, execute) }(active)
		}
		select {
		case <-ctx.Done():
		case <-ticks.C:
		}
	}
	return nil
}

type jobResult struct {
	ok   bool
	text string
}

func pollJob(ctx context.Context, c config, execute jobExecutor) error {
	var out struct{ Job *job }
	start := time.Now()
	if err := callContext(ctx, c, "jobs/claim", c.Credential, map[string]any{"node_id": c.Node, "renewable": true}, &out); err != nil {
		return err
	}
	j := out.Job
	if j == nil {
		return nil
	}
	if !instanceIDPattern.MatchString(j.ID) || len(j.Lease) != 64 || j.LeaseSeconds < 0 || j.LeaseSeconds > 60 {
		return errors.New("invalid claim response")
	}
	// A panel predating renewal returns no TTL. Keep its existing hard limit.
	expires := start.Add(2 * time.Minute)
	if j.Action == "native.install" || j.Action == "instance.install" || j.Action == "instance.world_backup" || j.Action == "instance.world_restore" || j.Action == "instance.world_import" || j.Action == "instance.world_export" || j.Action == "instance.version_change" || j.Action == "instance.version_inspect" || j.Action == "instance.modpack_stage" {
		expires = start.Add(20 * time.Minute)
	}
	if j.LeaseSeconds > 0 {
		expires = leaseExpiry(start, j.LeaseSeconds)
	}
	if !time.Now().Before(expires) {
		return errors.New("lease already elapsed")
	}

	jobCtx, cancel := context.WithCancel(ctx)
	done := make(chan jobResult, 1)
	go func() { ok, msg := execute(jobCtx, *j); done <- jobResult{ok, msg}; close(done) }()
	defer func() { cancel(); <-done }()
	lease := map[string]any{"node_id": c.Node, "id": j.ID, "lease": j.Lease}
	for {
		remaining := time.Until(expires)
		if remaining <= 0 {
			return errors.New("job lease elapsed")
		}
		delay := remaining
		if j.LeaseSeconds > 0 {
			delay = remaining / 3
			if delay < time.Second {
				delay = remaining
			}
		}
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case r := <-done:
			timer.Stop()
			lease["success"], lease["result"] = r.ok, r.text
			return acknowledge(ctx, c, lease, expires, j.LeaseSeconds > 0)
		case <-timer.C:
		}
		if time.Until(expires) <= 0 {
			return errors.New("job lease elapsed")
		}
		// Bound even an unresponsive renewal by the last confirmed lease.
		renewCtx, stop := context.WithDeadline(ctx, expires)
		var reply struct {
			Seconds int `json:"lease_seconds"`
		}
		sent := time.Now()
		err := callContext(renewCtx, c, "jobs/renew", c.Credential, lease, &reply)
		stop()
		if err != nil {
			if !temporaryPanelError(err) {
				return err
			}
			continue
		}
		if reply.Seconds < 1 || reply.Seconds > 60 {
			return errors.New("invalid lease renewal")
		}
		expires = leaseExpiry(sent, reply.Seconds)
	}
}

func leaseExpiry(sent time.Time, seconds int) time.Time {
	// SQL timestamps have one-second resolution. Measure from request start.
	return sent.Add(time.Duration(seconds-1) * time.Second)
}

func temporaryPanelError(err error) bool {
	var status panelHTTPError
	return !errors.As(err, &status) || status.status >= 500 || status.status == 429 || status.status == 408
}

func acknowledge(parent context.Context, c config, body map[string]any, expires time.Time, retry bool) error {
	ctx, cancel := context.WithDeadline(parent, expires)
	defer cancel()
	for {
		var ack struct{ OK bool }
		err := callContext(ctx, c, "jobs/complete", c.Credential, body, &ack)
		if err == nil && ack.OK {
			return nil
		}
		if err == nil {
			err = errors.New("job result not acknowledged")
		}
		if !retry || !temporaryPanelError(err) {
			return err
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(time.Second):
		}
	}
}
