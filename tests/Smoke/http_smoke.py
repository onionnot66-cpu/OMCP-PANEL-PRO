#!/usr/bin/env python3
"""Real HTTP smoke test using separate cookies and a fixed CI loopback origin."""
import http.cookiejar
import json
import os
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
    if b"Offline File Manager" not in request(admin, instances):
        raise RuntimeError("Admin instance file manager missing")
    request(owner, config, status=403)
    request(owner, instances, status=403)
    owner_ids = [x["id"] for x in json.loads(request(owner, "/api/client/omcp/instances"))["data"]]
    admin_ids = [x["id"] for x in json.loads(request(admin, "/api/client/omcp/instances"))["data"]]
    if owner_ids != [fixture["owner_instance"]] or admin_ids != [fixture["admin_instance"]]:
        raise RuntimeError("Owner reservation scope mismatch")
    if b"OMCP" not in request(owner, "/"):
        raise RuntimeError("User dashboard unavailable")
    print("HTTP smoke passed: compiled assets, real login, admin pages, owner scoping and guest denial.")

if __name__ == "__main__":
    main()
