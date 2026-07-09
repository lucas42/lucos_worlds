#!/bin/sh
# Builds the real, patched lucos_worlds_web image (the actual production
# Dockerfile — this test must exercise the genuine artifact, not a
# stand-in), then a thin test-only layer on top that adds the unit test file
# (see unit.Dockerfile for why this has to be a second build stage rather
# than a docker-compose bind-mount), then runs it with the image's own
# vendored PHPUnit against InfoController.php exactly as shipped.
#
# Used identically by CircleCI and local runs — see .circleci/config.yml.
# Unlike test/oidc-es256 (a full login-flow integration test, extended by
# lucas42/lucos_worlds#6 to also assert the live /_info schema + health
# against the real running app + DB), this is a fast, no-network unit test
# of the payload-mapping logic itself — no docker-compose stack needed.
set -eu
cd "$(dirname "$0")"

docker build -t lucos_worlds_unit_test_info_base -f ../../Dockerfile ../..
docker build -t lucos_worlds_unit_test_info --build-arg BASE_IMAGE=lucos_worlds_unit_test_info_base -f unit.Dockerfile .

docker run --rm --entrypoint /app/www/vendor/bin/phpunit lucos_worlds_unit_test_info \
    --bootstrap /app/www/vendor/autoload.php --no-configuration /tmp/InfoPayloadTest.php
