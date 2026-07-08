#!/usr/bin/with-contenv bash
# Test-harness-only: trusts the mock IdP's self-signed cert so BookStack's
# outbound Guzzle/curl client (which enforces real TLS verification, correctly)
# can complete discovery/JWKS/token-exchange calls to it over HTTPS. Mounted
# into /custom-cont-init.d only by test/oidc-es256/docker-compose.yml — never
# part of the production Dockerfile or image.
set -eu
cat /mock-idp-ca.pem >> /etc/ssl/certs/ca-certificates.crt
echo "[trust-mock-idp-ca] appended test CA to system trust bundle"
