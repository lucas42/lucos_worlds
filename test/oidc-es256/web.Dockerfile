# Test-only layer on top of the real, patched lucos_worlds_web image (built
# separately — see build-and-run.sh): trusts the mock IdP's self-signed TLS
# cert so BookStack's real, unmodified TLS verification can complete
# discovery/JWKS/token-exchange calls to it over HTTPS.
#
# This MUST be a build-time COPY, not a docker-compose bind-mount. CircleCI's
# setup_remote_docker runs the Docker daemon on a separate remote host that
# cannot see this job's checked-out files — a bind-mounted source path
# that only exists on the CircleCI executor silently resolves to an empty
# directory on that remote host, so the mounted "file" comes through as a
# directory and the custom-cont-init script fails with "Is a directory"
# (confirmed in build lucas42/lucos_worlds#28 CI run #76). A build-time COPY
# has no such split — the build context is uploaded to wherever the build
# actually runs.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
COPY mock_idp_cert.pem /usr/local/share/ca-certificates/mock-idp-ca.crt
RUN cat /usr/local/share/ca-certificates/mock-idp-ca.crt >> /etc/ssl/certs/ca-certificates.crt
