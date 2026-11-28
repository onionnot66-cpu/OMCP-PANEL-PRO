package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

const workerJobID = "aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa"

func TestHeartbeatAndRenewalDuringWorkWithLostAcknowledgement(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	var claims, executions, heartbeats, renewals, completions atomic.Int32
	started := make(chan struct{})
	release := make(chan struct{})
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer private" {
			t.Error("missing credential")
		}
		switch strings.TrimPrefix(r.URL.Path, "/api/omcp/v1/") {
		case "heartbeat":
			select {
			case <-started:
				heartbeats.Add(1)
			default:
			}
			json.NewEncoder(w).Encode(map[string]bool{"ok": true})
		case "jobs/claim":
			if claims.Add(1) > 1 {
				if completions.Load() < 2 {
					t.Error("concurrent job claim before completion acknowledgement")
				}
				json.NewEncoder(w).Encode(map[string]any{"job": nil})
				return
			}
			json.NewEncoder(w).Encode(map[string]any{"job": job{ID: workerJobID, Lease: strings.Repeat("1", 64), LeaseSeconds: 5, Action: "diagnostic"}})
		case "jobs/renew":
			if renewals.Add(1) == 1 {
				close(release)
			}
			json.NewEncoder(w).Encode(map[string]int{"lease_seconds": 5})
		case "jobs/complete":
			if completions.Add(1) == 1 {
				http.Error(w, "lost acknowledgement", 503)
				return
			}
			json.NewEncoder(w).Encode(map[string]bool{"ok": true})
		default:
			t.Error(r.URL.Path)
			w.WriteHeader(404)
		}
	}))
	defer s.Close()
	old := client
	client = s.Client()
	defer func() { client = old }()
	stopCtx, stop := context.WithCancel(ctx)
	defer stop()
	native := &supervisor{backend:nativeRuntime{},dir: t.TempDir(), memory: 128}
	workerDone := make(chan error, 1)
	go func() {
		workerDone <- serveConnector(stopCtx, config{Panel: s.URL, Node: 1, Credential: "private"}, native, nil, 20*time.Millisecond, func(jobCtx context.Context, _ job) (bool, string) {
			executions.Add(1)
			native.mu.Lock()
			defer native.mu.Unlock()
			close(started)
			select {
			case <-release:
				return true, "finished"
			case <-jobCtx.Done():
				return false, "cancelled"
			}
		})
	}()
	for completions.Load() < 2 && ctx.Err() == nil {
		time.Sleep(10 * time.Millisecond)
	}
	stop()
	<-workerDone
	if executions.Load() != 1 || completions.Load() != 2 || heartbeats.Load() < 2 || renewals.Load() < 1 {
		t.Fatalf("claims %d executions %d acks %d heartbeats %d renewals %d", claims.Load(), executions.Load(), completions.Load(), heartbeats.Load(), renewals.Load())
	}
}

func TestRevokedLeaseCancelsExecution(t *testing.T) {
	var completed atomic.Bool
	s := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/omcp/v1/jobs/claim":
			json.NewEncoder(w).Encode(map[string]any{"job": job{ID: workerJobID, Lease: strings.Repeat("1", 64), LeaseSeconds: 4}})
		case "/api/omcp/v1/jobs/renew":
			http.Error(w, "revoked", 401)
		default:
			completed.Store(true)
			w.WriteHeader(409)
		}
	}))
	defer s.Close()
	old := client
	client = s.Client()
	defer func() { client = old }()
	ctx, cancel := context.WithTimeout(context.Background(), 6*time.Second)
	defer cancel()
	err := pollJob(ctx, config{Panel: s.URL, Node: 1, Credential: "test"}, func(jobCtx context.Context, _ job) (bool, string) { <-jobCtx.Done(); return false, "cancelled" })
	if err == nil || ctx.Err() != nil || completed.Load() {
		t.Fatalf("revocation failed: %v", err)
	}
}
