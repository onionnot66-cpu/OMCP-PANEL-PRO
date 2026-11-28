#!/usr/bin/env python3
"""Real HTTP smoke test using separate cookies and a fixed CI loopback origin."""
import http.cookiejar
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser

BASE = "http://127.0.0.1:8080"

class Page(HTMLParser):
    def __init__(self, data):
        super().__init__()
        self.csrf = None
        self.scripts = []
        self.feed(data.decode("utf-8"))

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "meta" and attrs.get("name") in ("csrf-token", "_token"):
            self.csrf = attrs.get("content")
        if tag == "script" and attrs.get("src"):
            self.scripts.append(attrs["src"])

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def session():
    return urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        NoRedirect(),
    )

def request(client, path, status=200, data=None, csrf=None):
    url = urllib.parse.urljoin(BASE, path)
    if not url.startswith(BASE + "/"):
        raise RuntimeError("Unexpected non-loopback resource")
    headers = {"Accept": "application/json" if data is not None or path.startswith("/api/") else "text/html",
               "Origin": BASE, "Referer": BASE + "/", "X-Requested-With": "XMLHttpRequest"}
    if data is not None:
        headers["Content-Type"] = "application/json"
    if csrf:
        headers["X-CSRF-TOKEN"] = csrf
    req = urllib.request.Request(url, data=None if data is None else json.dumps(data).encode(), headers=headers)
    try:
        response = client.open(req, timeout=20)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        body = response.read(16 * 1024 * 1024)
        if response.code != status:
            raise RuntimeError(f"{path}: expected {status}, got {response.code}")
        return body

def login(username):
    client = session()
    html = request(client, "/auth/login")
    page = Page(html)
    if not page.csrf or b"OMCP" not in html:
        raise RuntimeError("Login page branding or CSRF token missing")
    result = json.loads(request(client, "/auth/login", data={
        "user": username, "password": os.environ["OMCP_SMOKE_PASSWORD"],
    }, csrf=page.csrf))
    if not result.get("data", {}).get("complete"):
        raise RuntimeError("Login did not complete")
    return client

def main():
    with open(os.environ["OMCP_SMOKE_FIXTURE"], encoding="utf-8") as f:
        fixture = json.load(f)
    guest = session()
    login_page = Page(request(guest, "/auth/login"))
    scripts = [p for p in login_page.scripts if p.startswith("/assets/")]
    if not scripts:
        raise RuntimeError("No compiled application script referenced")
    for path in scripts:
        if len(request(guest, path)) < 100:
            raise RuntimeError("Compiled application script is empty")
    request(guest, "/api/client/omcp/instances", status=401)
    admin = login("omcp_ci_admin")
    owner = login("omcp_ci_owner")
    node = fixture["node"]
    config = f"/admin/nodes/view/{node}/configuration"
    instances = f"/admin/nodes/view/{node}/omcp/instances"
    if b"OMCP Connect" not in request(admin, config):
        raise RuntimeError("Admin connector UI missing")
    admin_html = request(admin, instances)
    if b"Offline File Manager" not in admin_html or b"Retire (keep files)" not in admin_html:
        raise RuntimeError("Admin instance file manager or retirement UI missing")
    admin_page = Page(admin_html)
    owner_page = Page(request(owner, "/"))
    action = instances + "/" + fixture["owner_instance"] + "/action"
    request(owner, action, status=403, data={"action": "retire", "confirm_retire": 1}, csrf=owner_page.csrf)
    request(admin, action, status=422, data={"action": "retire"}, csrf=admin_page.csrf)
    request(owner, config, status=403)
    request(owner, instances, status=403)
    owner_ids = [x["id"] for x in json.loads(request(owner, "/api/client/omcp/instances"))["data"]]
    admin_ids = [x["id"] for x in json.loads(request(admin, "/api/client/omcp/instances"))["data"]]
    if owner_ids != [fixture["owner_instance"]] or admin_ids != [fixture["admin_instance"]]:
        raise RuntimeError("Owner reservation scope mismatch")
    owner_action = "/api/client/omcp/instances/" + fixture["owner_instance"] + "/action"
    other_action = "/api/client/omcp/instances/" + fixture["admin_instance"] + "/action"
    grant = instances + "/" + fixture["owner_instance"] + "/controls"
    request(owner, owner_action, status=403, data={"action": "status"}, csrf=owner_page.csrf)
    request(owner, other_action, status=404, data={"action": "status"}, csrf=owner_page.csrf)
    request(owner, grant, status=403, data={"enabled": 1, "trusted_confirmed": 1}, csrf=owner_page.csrf)
    request(admin, grant, status=422, data={"enabled": 1}, csrf=admin_page.csrf)
    request(admin, grant, status=302, data={"enabled": 1, "trusted_confirmed": 1}, csrf=admin_page.csrf)
    result = json.loads(request(owner, owner_action, status=202, data={"action": "status"}, csrf=owner_page.csrf))
    job = "/api/client/omcp/instances/" + fixture["owner_instance"] + "/jobs/" + result["id"]
    if json.loads(request(owner, job))["state"] != "queued":
        raise RuntimeError("Owner job not queued")
    request(admin, job, status=404)
    request(admin, grant, status=302, data={"enabled": 0}, csrf=admin_page.csrf)
    request(owner, job, status=404)
    request(owner, owner_action, status=403, data={"action": "status"}, csrf=owner_page.csrf)
    request(admin, grant, status=302, data={"enabled": 1, "trusted_confirmed": 1}, csrf=admin_page.csrf)
    request(owner, job, status=404)
    plugins = instances + "/" + fixture["owner_instance"] + "/plugins"
    if b"Modrinth catalog" not in request(admin, plugins):
        raise RuntimeError("Plugin manager page missing")
    request(owner, plugins, status=403)
    request(owner, plugins + "/catalog", status=403)
    request(owner, plugins, status=403, data={"action": "plugin_list"}, csrf=owner_page.csrf)
    request(admin, plugins, status=422, data={"action": "plugin_install", "plugin_project": "ABCDEFGH", "plugin_version": "IJKLMNOP", "plugin_sha512": "a" * 128}, csrf=admin_page.csrf)
    print("Owner grant, job access, revocation and generation isolation passed.")
    worlds = instances + "/" + fixture["owner_instance"] + "/worlds"
    if b"World ZIP transfer" not in request(admin, worlds):
        raise RuntimeError("World transfer page missing")
    request(owner, worlds, status=403)
    request(owner, worlds + "/transfer", status=403, data={"direction": "export"}, csrf=owner_page.csrf)
    request(admin, worlds, status=422, data={"action": "world_backup", "world": "world"}, csrf=admin_page.csrf)
    print("Plugin manager admin access and explicit confirmation passed.")
    print("World manager admin access and explicit confirmation passed.")
    if b"OMCP" not in request(owner, "/"):
        raise RuntimeError("User dashboard unavailable")
    print("HTTP smoke passed: compiled assets, real login, admin pages, owner scoping and guest denial.")

def wait_for(check, label, timeout=90):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = check()
        if value:
            return value
        time.sleep(1)
    raise RuntimeError("Timed out: " + label)

def connector_main():
    with open(os.environ["OMCP_SMOKE_FIXTURE"], encoding="utf-8") as f:
        node = json.load(f)["connector_node"]
    admin = login("omcp_ci_admin")
    page = Page(request(admin, f"/admin/nodes/view/{node}/configuration"))
    if not page.csrf:
        raise RuntimeError("Admin CSRF token missing")
    prefix = f"/admin/nodes/view/{node}/omcp"

    def state():
        return json.loads(request(admin, prefix + "/status"))

    def telemetry():
        return state().get("telemetry") or {}

    def job(endpoint, payload):
        result = json.loads(request(admin, prefix + endpoint, status=202, data=payload, csrf=page.csrf))
        def completed():
            response = json.loads(request(admin, prefix + "/diagnostic/" + result["id"]))
            if response["state"] in ("failed", "expired"):
                raise RuntimeError("Connector job failed: " + endpoint)
            return response if response["state"] == "succeeded" else None
        return wait_for(completed, endpoint)

    wait_for(lambda: state().get("online"), "authenticated heartbeat")
    job("/diagnostic", {})
    job("/power", {"action": "start"})
    wait_for(lambda: "OMCP smoke runtime ready" in telemetry().get("console", ""), "game start")
    job("/command", {"command": "list"})
    wait_for(lambda: "OMCP smoke players: 0" in telemetry().get("console", ""), "console response")
    job("/power", {"action": "stop"})
    wait_for(lambda: telemetry().get("metrics", {}).get("process_running") is False and
             "OMCP smoke runtime stopped" in telemetry().get("console", ""), "graceful stop")
    print("Connector protocol and synthetic runtime smoke passed.")

if __name__ == "__main__":
    if sys.argv[1:] == ["--connector"]:
        connector_main()
    else:
        main()
