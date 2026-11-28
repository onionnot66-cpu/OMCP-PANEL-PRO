package main

import (
	"context"
	"encoding/json"
	"os/exec"
	"strings"
	"time"
)

type job struct {
	ID           string     `json:"id"`
	Action       string     `json:"action"`
	Lease        string     `json:"lease"`
	LeaseSeconds int        `json:"lease_seconds"`
	Payload      jobPayload `json:"payload"`
}

func executeJob(ctx context.Context, j job) (bool, string) {
	switch j.Action {
	case "diagnostic":
		report := capabilities()
		if java, err := exec.LookPath("java"); err == nil {
			ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
			defer cancel()
			cmd := exec.CommandContext(ctx, java, "-version")
			var output consoleBuffer
			cmd.Stdout = &output
			cmd.Stderr = &output
			if cmd.Run() == nil {
				report["java_version"] = strings.ToValidUTF8(output.snapshot(), "")
			} else {
				report["java_version"] = "unavailable"
			}
		}
		result, err := json.Marshal(report)
		if err != nil {
			return false, "diagnostic serialization failed"
		}
		return true, string(result)
	default:
		return false, "unsupported job action"
	}
}

type jobExecutor func(context.Context, job) (bool, string)

func dispatcher(native *supervisor, multi *fleet) jobExecutor {
	return func(ctx context.Context, j job) (bool, string) {
		if err := ctx.Err(); err != nil {
			return false, err.Error()
		}
		switch {
		case native != nil && strings.HasPrefix(j.Action, "native."):
			native.mu.Lock()
			defer native.mu.Unlock()
			return native.executeJob(ctx, j)
		case multi != nil && strings.HasPrefix(j.Action, "instance."):
			return multi.execute(ctx, j)
		default:
			return executeJob(ctx, j)
		}
	}
}
