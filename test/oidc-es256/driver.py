"""
Integration test driver for lucas42/lucos_worlds#26 (ES256 OIDC support).

Drives the FULL OIDC authorization-code login flow end-to-end against the
real, patched BookStack image and a mock ES256-only OIDC provider — not a
unit test. This is the load-bearing check per #26: version numbers can't be
trusted to reveal a break in the patched files, only an actual login
succeeding proves the patch still works.

Two scenarios, both required (lucos-security review, PR #28): a genuine
ES256-signed token must be ACCEPTED, and a tampered one must be REJECTED.
Checking only the happy path can't catch "verification became too
permissive" — a fail-open regression, which is the worse direction for
auth-verification code and exactly the kind of silent breakage this test
suite exists to catch.

Exits 0 on success, non-zero (with a diagnostic message) on any failure.
"""
import re
import sys
import time

import requests
import urllib3

# The mock IdP serves a test-only self-signed cert (see mock_idp.py), trusted
# by BookStack itself via a real CA-bundle install baked into the test image
# (see web.Dockerfile) — that's the trust path actually under test. This
# driver's own direct hit to the IdP's /oauth2/authorize is just relaying a
# redirect, not part of the security surface being verified, so skip
# verification here rather than duplicating the trust setup in a throwaway
# test script.
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

BOOKSTACK_URL = "http://web"
MOCK_IDP_URL = "https://mock_idp:9000"
MAX_WAIT_SECONDS = 90


def wait_for_bookstack():
    deadline = time.time() + MAX_WAIT_SECONDS
    last_error = None
    while time.time() < deadline:
        try:
            r = requests.get(f"{BOOKSTACK_URL}/status", timeout=5)
            if r.status_code == 200:
                print(f"[driver] BookStack /status is up: {r.json()}")
                return
            last_error = f"HTTP {r.status_code}: {r.text[:200]}"
        except requests.RequestException as e:
            last_error = str(e)
        time.sleep(3)
    fail(f"BookStack never became ready within {MAX_WAIT_SECONDS}s — last error: {last_error}")


def fail(message):
    print(f"[driver] FAIL: {message}")
    sys.exit(1)


def attempt_oidc_login():
    """
    Drives one full attempt at the OIDC login flow, from a fresh session, and
    returns whether it ended up authenticated. Does not itself decide
    pass/fail — the caller knows whether authentication was expected to
    succeed or not for this attempt.
    """
    session = requests.Session()

    # 1. GET /login — establish a session and grab the CSRF token.
    login_page = session.get(f"{BOOKSTACK_URL}/login", timeout=10)
    if login_page.status_code != 200:
        fail(f"GET /login returned {login_page.status_code}")

    csrf_match = re.search(r'name="_token" value="([^"]+)"', login_page.text)
    if not csrf_match:
        fail("Could not find CSRF token on /login page")
    csrf_token = csrf_match.group(1)

    if "oidc/login" not in login_page.text:
        fail("Login page does not contain an OIDC login form — AUTH_METHOD misconfigured?")

    # 2. POST /oidc/login — BookStack builds the authorization URL using our
    #    patched OidcProviderSettings (discovery + EC key filtering) and
    #    redirects to the mock IdP's /oauth2/authorize.
    resp = session.post(
        f"{BOOKSTACK_URL}/oidc/login",
        data={"_token": csrf_token},
        allow_redirects=False,
        timeout=10,
    )
    if resp.status_code != 302:
        fail(
            "POST /oidc/login did not redirect (expected 302, got "
            f"{resp.status_code}). Body: {resp.text[:1000]}"
        )
    authorize_url = resp.headers["Location"]
    if "mock_idp" not in authorize_url:
        fail(f"Expected redirect to mock_idp, got: {authorize_url}")

    # 3. Follow the redirect to the mock IdP's /oauth2/authorize — it issues a
    #    fixed test code and redirects straight back to BookStack's callback
    #    (there's no real user to authenticate against in this mock).
    resp = session.get(authorize_url, allow_redirects=False, timeout=10, verify=False)
    if resp.status_code != 302:
        fail(f"Mock IdP /oauth2/authorize did not redirect (got {resp.status_code})")
    callback_url = resp.headers["Location"]

    # 4. Follow the callback. BookStack now exchanges the code for a token by
    #    calling the mock IdP's /oauth2/token SERVER-SIDE (not via this
    #    session), receives an ES256-signed id_token (genuine or tampered,
    #    depending on what the caller armed on the mock beforehand), and —
    #    this is the actual patch under test — validates that signature.
    resp = session.get(callback_url, allow_redirects=False, timeout=10)
    print(f"[driver]   callback: {resp.status_code}, Location: {resp.headers.get('Location')}")
    if resp.status_code not in (302, 200):
        # Not necessarily a test failure by itself — the negative-path
        # scenario may legitimately get an error response here. The caller
        # decides based on the overall authenticated-or-not outcome below.
        pass

    if resp.status_code == 302 and "login" in resp.headers.get("Location", "").lower():
        return False, session

    # 5. Confirm whether we're actually logged in: GET / must NOT bounce to /login.
    home = session.get(f"{BOOKSTACK_URL}/", allow_redirects=True, timeout=10)
    authenticated = "/login" not in home.url and home.status_code == 200
    return authenticated, session


def main():
    wait_for_bookstack()

    # --- Scenario 1: a genuine ES256-signed token must be ACCEPTED. ---
    print("[driver] Scenario 1: genuine ES256 signature")
    authenticated, _ = attempt_oidc_login()
    if not authenticated:
        fail("Genuine ES256-signed login did not result in an authenticated session.")
    print("[driver] PASS: genuine ES256 signature was accepted, user landed authenticated.")

    # --- Scenario 2: a tampered signature must be REJECTED. ---
    # This is the fail-open check — the whole point of test-oidc-es256 gating
    # deploys is catching a future upstream change that makes verification
    # silently too permissive, and the happy-path check alone can't do that.
    print("[driver] Scenario 2: tampered ES256 signature")
    resp = requests.post(f"{MOCK_IDP_URL}/test-control/tamper-next-token", timeout=10, verify=False)
    if resp.status_code != 200:
        fail(f"Could not arm the mock IdP's tamper-next-token control: HTTP {resp.status_code}")

    authenticated, session = attempt_oidc_login()
    if authenticated:
        fail(
            "A tampered ES256 signature was ACCEPTED — the patched signature "
            "verification is failing open. This is a critical regression."
        )
    print("[driver] PASS: tampered ES256 signature was correctly rejected.")

    print("[driver] PASS: full ES256 OIDC integration test succeeded (accept genuine, reject tampered).")
    sys.exit(0)


if __name__ == "__main__":
    main()
