# Test-only layer on top of the real, patched lucos_worlds_web image (built
# separately — see build-and-run.sh): adds this single unit test file so it
# can run against the actual patched Page::getExcerpt() and the real
# vendored PHPUnit, via Composer's PSR-4 autoloader.
#
# A build-time COPY, not a docker-compose bind-mount, for the same reason as
# test/oidc-es256/web.Dockerfile: CircleCI's setup_remote_docker runs the
# Docker daemon on a separate remote host with no access to this job's
# checked-out files, so a bind-mounted source path silently resolves to an
# empty directory there.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
COPY PageExcerptTest.php /tmp/PageExcerptTest.php
