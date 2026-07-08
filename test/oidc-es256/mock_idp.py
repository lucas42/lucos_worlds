"""
Minimal mock OIDC provider that signs exclusively with ES256 — standing in for
lucos_aithne's signing behaviour, for lucos_worlds' ES256 OIDC integration test
(lucas42/lucos_worlds#26, decision in #21).

Serves exactly what a real client needs to complete an OIDC authorization-code
flow against a discovery-based RP:
  - GET  /.well-known/openid-configuration
  - GET  /.well-known/jwks.json
  - GET  /oauth2/authorize   (issues a fixed dummy code, redirects straight back
                               — there's no real user to log in as, this only
                               exists to test the RP's own token handling)
  - POST /oauth2/token       (exchanges the code for a real ES256-signed id_token)
  - GET  /oauth2/userinfo

Deliberately NOT a general-purpose OIDC test double — just enough surface for
this one integration test.
"""
import base64
import json
import ssl
import time
import uuid
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs, urlencode

from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric.utils import decode_dss_signature

# BookStack's OidcProviderSettings hardcodes "issuer must start with https://"
# (a real, sensible check — not something to weaken). So this mock serves TLS
# using a test-only self-signed cert (mock_idp_cert.pem/mock_idp_key.pem,
# generated once, committed alongside this file — throwaway, no security
# relevance). The `web` service in docker-compose.yml trusts this same cert
# via a custom-cont-init script that appends it to the system CA bundle —
# test-harness-only, never touches the real production Dockerfile/image.
ISSUER = "https://mock_idp:9000"
CLIENT_ID = "lucos_worlds"
CLIENT_SECRET = "test-client-secret"
FIXED_CODE = "test-auth-code"
USER_SUB = "test-user"
USER_EMAIL = "test-user@example.test"
USER_NAME = "Test User"

_private_key = ec.generate_private_key(ec.SECP256R1())
_public_numbers = _private_key.public_key().public_numbers()


def b64u(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def sign_es256(header: dict, payload: dict) -> str:
    header_b64 = b64u(json.dumps(header).encode())
    payload_b64 = b64u(json.dumps(payload).encode())
    signing_input = f"{header_b64}.{payload_b64}".encode()
    der_sig = _private_key.sign(signing_input, ec.ECDSA(hashes.SHA256()))
    r, s = decode_dss_signature(der_sig)
    raw_sig = r.to_bytes(32, "big") + s.to_bytes(32, "big")
    return f"{header_b64}.{payload_b64}.{b64u(raw_sig)}"


def make_id_token(redirect_uri_aud: str = CLIENT_ID) -> str:
    now = int(time.time())
    header = {"alg": "ES256", "typ": "JWT", "kid": "test-key-1"}
    payload = {
        "iss": ISSUER,
        "sub": USER_SUB,
        "aud": redirect_uri_aud,
        "exp": now + 300,
        "iat": now,
        "jti": str(uuid.uuid4()),
        "email": USER_EMAIL,
        "name": USER_NAME,
    }
    return sign_es256(header, payload)


class Handler(BaseHTTPRequestHandler):
    def _json(self, obj, status=200):
        body = json.dumps(obj).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/.well-known/openid-configuration":
            self._json({
                "issuer": ISSUER,
                "authorization_endpoint": f"{ISSUER}/oauth2/authorize",
                "token_endpoint": f"{ISSUER}/oauth2/token",
                "userinfo_endpoint": f"{ISSUER}/oauth2/userinfo",
                "jwks_uri": f"{ISSUER}/.well-known/jwks.json",
                "scopes_supported": ["openid", "profile", "email"],
                "response_types_supported": ["code"],
                "subject_types_supported": ["public"],
                "id_token_signing_alg_values_supported": ["ES256"],
                "token_endpoint_auth_methods_supported": ["client_secret_post"],
            })
        elif parsed.path == "/.well-known/jwks.json":
            x = b64u(_public_numbers.x.to_bytes(32, "big"))
            y = b64u(_public_numbers.y.to_bytes(32, "big"))
            self._json({"keys": [{
                "kty": "EC", "crv": "P-256", "alg": "ES256", "use": "sig",
                "kid": "test-key-1", "x": x, "y": y,
            }]})
        elif parsed.path == "/oauth2/authorize":
            # Note: BookStack's OAuth2 client does send PKCE params
            # (code_challenge/code_challenge_method) here, but this mock does
            # NOT validate them (or the corresponding code_verifier at the
            # /oauth2/token exchange below) — that's out of scope for what
            # this test verifies (the ES256 signature-verification patch),
            # not a claim that PKCE round-tripping itself is covered.
            qs = parse_qs(parsed.query)
            redirect_uri = qs["redirect_uri"][0]
            state = qs.get("state", [""])[0]
            location = f"{redirect_uri}?{urlencode({'code': FIXED_CODE, 'state': state})}"
            self.send_response(302)
            self.send_header("Location", location)
            self.end_headers()
        elif parsed.path == "/oauth2/userinfo":
            self._json({"sub": USER_SUB, "email": USER_EMAIL, "name": USER_NAME})
        else:
            self._json({"error": "not_found"}, status=404)

    def do_POST(self):
        parsed = urlparse(self.path)
        if parsed.path == "/oauth2/token":
            # BookStack's OidcOAuthProvider hardcodes HttpBasicAuthOptionProvider
            # (see app/Access/Oidc/OidcService.php::getProvider()) — client
            # credentials arrive via the Authorization header, not the POST body,
            # regardless of what token_endpoint_auth_methods_supported advertises.
            auth_header = self.headers.get("Authorization", "")
            expected = "Basic " + base64.b64encode(f"{CLIENT_ID}:{CLIENT_SECRET}".encode()).decode()
            if auth_header != expected:
                self._json({"error": "invalid_client"}, status=401)
                return

            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length).decode()
            params = parse_qs(body)
            code = params.get("code", [None])[0]
            if code != FIXED_CODE:
                self._json({"error": "invalid_grant"}, status=400)
                return
            self._json({
                "access_token": "test-access-token",
                "token_type": "Bearer",
                "expires_in": 3600,
                "id_token": make_id_token(),
            })
        else:
            self._json({"error": "not_found"}, status=404)

    def log_message(self, fmt, *args):
        print("[mock_idp] " + (fmt % args))


if __name__ == "__main__":
    server = HTTPServer(("0.0.0.0", 9000), Handler)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(certfile="/certs/mock_idp_cert.pem", keyfile="/certs/mock_idp_key.pem")
    server.socket = ctx.wrap_socket(server.socket, server_side=True)
    print("[mock_idp] listening on :9000 (TLS)")
    server.serve_forever()
