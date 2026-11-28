// Test-only HTTPS bridge; both endpoints are fixed to loopback.
package main

import (
    "crypto/tls"
    "log"
    "net/http"
    "net/http/httputil"
    "net/url"
    "os"
    "time"
)

func main() {
    if len(os.Args) != 3 {
        log.Fatal("usage: https-proxy CERT KEY")
    }
    target, _ := url.Parse("http://127.0.0.1:8080")
    proxy := httputil.NewSingleHostReverseProxy(target)
    handler := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        if r.Host != "127.0.0.1:8443" {
            http.Error(w, "invalid host", http.StatusBadRequest)
            return
        }
        r.Header.Del("Forwarded")
        r.Header.Del("X-Forwarded-For")
        r.Header.Set("X-Forwarded-Proto", "https")
        r.Header.Set("X-Forwarded-Host", r.Host)
        proxy.ServeHTTP(w, r)
    })
    server := &http.Server{
        Addr: "127.0.0.1:8443", Handler: handler,
        ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 20 * time.Second,
        WriteTimeout: 30 * time.Second, IdleTimeout: 30 * time.Second,
        TLSConfig: &tls.Config{MinVersion: tls.VersionTLS12},
    }
    log.Fatal(server.ListenAndServeTLS(os.Args[1], os.Args[2]))
}
