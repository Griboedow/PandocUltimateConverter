#!/usr/bin/env python3
"""
API-based end-to-end test for PandocUltimateConverter on MediaWiki 1.39.

Uses only Python standard-library HTTP (no Playwright / no browser required).

Flow:
  1. Log in as admin via the MW Action API (api.php).
  2. Obtain a CSRF (edit) token.
  3. Convert FIXTURE_URL to a wiki page via action=pandocconvert.
  4. Fetch the raw wikitext of the created page via index.php?action=raw.
  5. Assert it contains the expected heading from the fixture HTML.
"""

import http.cookiejar
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

MW_BASE = "http://localhost:8080"
# MW Action API endpoint (separate from the web-page serving index.php)
MW_API = f"{MW_BASE}/api.php"
FIXTURE_URL = "http://localhost:8081/fixture.html"
TARGET_PAGE = "E2ETestConvertedPage"

# Shared cookie jar — keeps the login session across all requests.
_jar = http.cookiejar.CookieJar()
_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(_jar))


def _read_response(resp) -> dict:
    raw = resp.read().decode()
    try:
        return json.loads(raw)
    except json.JSONDecodeError as exc:
        raise AssertionError(f"Non-JSON response:\n{raw[:500]}") from exc


def api_get(**params) -> dict:
    """GET request to the MW Action API."""
    qs = urllib.parse.urlencode({"format": "json", **params})
    try:
        with _opener.open(f"{MW_API}?{qs}", timeout=30) as resp:
            return _read_response(resp)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode()[:500]
        raise AssertionError(f"GET {exc.url} -> HTTP {exc.code}: {body}") from exc


def api_post(**params) -> dict:
    """POST request to the MW Action API. Timeout is generous for pandoc."""
    data = urllib.parse.urlencode({"format": "json", **params}).encode()
    try:
        with _opener.open(MW_API, data, timeout=90) as resp:
            return _read_response(resp)
    except urllib.error.HTTPError as exc:
        body = exc.read().decode()[:500]
        raise AssertionError(f"POST {MW_API} -> HTTP {exc.code}: {body}") from exc


def run() -> None:
    # ------------------------------------------------------------------
    # Step 1: obtain a login token and authenticate as admin.
    # ------------------------------------------------------------------
    login_token = api_get(
        action="query", meta="tokens", type="login"
    )["query"]["tokens"]["logintoken"]

    r = api_post(
        action="login",
        lgname="admin",
        lgpassword="adminpassword",
        lgtoken=login_token,
    )
    if r["login"]["result"] != "Success":
        raise AssertionError(f"Login failed: {r['login']}")
    print("Logged in as admin")

    # ------------------------------------------------------------------
    # Step 2: obtain a CSRF (edit) token for write operations.
    # ------------------------------------------------------------------
    csrf_token = api_get(action="query", meta="tokens")["query"]["tokens"]["csrftoken"]

    # ------------------------------------------------------------------
    # Step 3: convert the fixture URL to a wiki page via pandocconvert API.
    # ------------------------------------------------------------------
    print(f"Converting {FIXTURE_URL!r} -> page {TARGET_PAGE!r} ...")
    r = api_post(
        action="pandocconvert",
        url=FIXTURE_URL,
        pagename=TARGET_PAGE,
        forceoverwrite="1",
        token=csrf_token,
    )
    if "error" in r:
        raise AssertionError(f"API conversion failed: {r['error']}")
    print(f"Conversion API response: {json.dumps(r)}")

    # ------------------------------------------------------------------
    # Step 4: fetch the raw wikitext of the created page.
    # ------------------------------------------------------------------
    raw_url = (
        f"{MW_BASE}/index.php"
        f"?action=raw&title={urllib.parse.quote(TARGET_PAGE)}"
    )
    try:
        with _opener.open(raw_url, timeout=15) as resp:
            content = resp.read().decode()
    except urllib.error.HTTPError as exc:
        raise AssertionError(f"Raw page fetch {exc.url} -> HTTP {exc.code}") from exc

    # ------------------------------------------------------------------
    # Step 5: assert the page contains the expected heading.
    # ------------------------------------------------------------------
    if "E2E Test Heading" not in content:
        raise AssertionError(
            f"Expected 'E2E Test Heading' in page wikitext.\n"
            f"First 500 chars:\n{content[:500]}"
        )
    print("Page content validated -- 'E2E Test Heading' found")
    print("E2E test PASSED")


if __name__ == "__main__":
    try:
        run()
    except Exception as exc:
        print(f"E2E test FAILED: {exc}", file=sys.stderr)
        sys.exit(1)
