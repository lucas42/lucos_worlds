"""
Page-render smoke test for lucas42/lucos_worlds#99.

Logs in to the real, patched BookStack image as the seeded admin, creates a
book and a page through the normal web forms, then GETs the page and checks
it renders: HTTP 200, the page-nav and book-tree sidebars, and the
og:description excerpt from the Page::getExcerpt() patch (lucos_worlds#52).
Exits non-zero with a diagnostic on any failure.
"""
import html
import re
import sys
import time

import requests

BASE = "http://web"
MAX_WAIT_SECONDS = 90


def fail(message):
    print(f"[driver] FAIL: {message}")
    sys.exit(1)


def csrf_token(page_html):
    match = re.search(r'name="_token" value="([^"]+)"', page_html) or re.search(r'csrf-token" content="([^"]+)"', page_html)
    if not match:
        fail("no CSRF token found in page")
    return match.group(1)


def wait_for_bookstack():
    deadline = time.time() + MAX_WAIT_SECONDS
    last_error = None
    while time.time() < deadline:
        try:
            if requests.get(f"{BASE}/status", timeout=5).status_code == 200:
                return
        except requests.RequestException as e:
            last_error = str(e)
        time.sleep(3)
    fail(f"BookStack never became ready within {MAX_WAIT_SECONDS}s (last error: {last_error})")


def main():
    wait_for_bookstack()
    s = requests.Session()

    login = s.get(f"{BASE}/login", timeout=10)
    r = s.post(f"{BASE}/login", data={"_token": csrf_token(login.text), "email": "admin@admin.com", "password": "password"}, timeout=10)
    if r.status_code != 200 or "/login" in r.url:
        fail(f"login failed: HTTP {r.status_code} at {r.url}")

    form = s.get(f"{BASE}/create-book", timeout=10)
    r = s.post(f"{BASE}/books", data={"_token": csrf_token(form.text), "name": "Smoke Book", "description_html": "<p>Smoke book</p>"}, timeout=10)
    if r.status_code != 200 or "/books/smoke-book" not in r.url:
        fail(f"creating the book failed: HTTP {r.status_code} at {r.url}")

    draft = s.get(f"{BASE}/books/smoke-book/create-page", timeout=10)
    if draft.status_code != 200 or "/draft/" not in draft.url:
        fail(f"creating a draft page failed: HTTP {draft.status_code} at {draft.url}")
    r = s.post(draft.url, data={
        "_token": csrf_token(draft.text),
        "name": "Smoke Page",
        "html": "<p>Opening summary line.</p><ul><li>Bleed item</li></ul><h2 id=\"bkmrk-heading\">Heading</h2><p>Body text.</p>",
    }, allow_redirects=False, timeout=10)
    if r.status_code != 302 or "/books/smoke-book/page/smoke-page" not in r.headers.get("Location", ""):
        fail(f"publishing the page failed: HTTP {r.status_code}, Location {r.headers.get('Location')}")

    r = s.get(f"{BASE}/books/smoke-book/page/smoke-page", timeout=10)
    body = r.text
    if r.status_code != 200:
        fail(f"viewing the page returned HTTP {r.status_code}")

    checks = {
        "page-nav sidebar": 'id="page-navigation"' in body,
        "book-tree sidebar": 'id="book-tree"' in body,
        "page content": "Body text." in body,
    }
    excerpt = re.search(r'og:description" content="([^"]*)"', body)
    og = html.unescape(excerpt.group(1)) if excerpt else None
    checks["og:description is the opening paragraph only"] = og is not None and "Opening summary line." in og and "Bleed item" not in og
    print(f"[driver] og:description = {og!r}")
    failed = [name for name, ok in checks.items() if not ok]
    for name, ok in checks.items():
        print(f"[driver] {'PASS' if ok else 'FAIL'}: {name}")
    if failed:
        fail(f"page rendered but missing: {', '.join(failed)}")
    print("[driver] PASS: page view rendered correctly")


if __name__ == "__main__":
    main()
