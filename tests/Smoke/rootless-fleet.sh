#!/usr/bin/env bash
set -euo pipefail
umask 077
engine="${1:?specify podman or docker}"
case "$engine" in
    podman) cli=(podman --remote=false) ;;
    docker) cli=(docker --host "unix:///run/user/$UID/docker.sock") ;;
    *) exit 2 ;;
esac
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
mkdir "$tmp/root"
CGO_ENABLED=0 go build -trimpath -o "$tmp/root/java" tests/Smoke/runtime-probe/main.go
mkdir "$tmp/root/bin"
mv "$tmp/root/java" "$tmp/root/bin/java"
tar -C "$tmp/root" -cf "$tmp/rootfs.tar" .
# Both engines load the locally built probe, without pulling any game image.
image="$("${cli[@]}" import "$tmp/rootfs.tar" | tail -n1)"
image="sha256:${image#sha256:}"
[[ "$image" =~ ^sha256:[a-f0-9]{64}$ ]]
(cd connector && OMCP_TEST_ENGINE="$engine" OMCP_TEST_IMAGE="$image" go test -v -tags=container_e2e -run '^TestRootlessFleet$' -count=1 -timeout=3m)
