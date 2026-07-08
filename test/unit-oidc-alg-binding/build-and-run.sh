#!/bin/sh
# Builds the real, patched lucos_worlds_web image (the actual production
# Dockerfile — this test must exercise the genuine artifact, not a stand-in),
# then a thin test-only layer on top that adds the unit test file (see
# unit.Dockerfile for why this has to be a second build stage rather than a
# docker-compose bind-mount), then runs it with the image's own vendored
# PHPUnit + phpseclib3.
#
# Used identically by CircleCI and local runs — see .circleci/config.yml.
# Unlike test/oidc-es256 (a full login-flow integration test against a mock
# IdP), this is a fast, no-network unit test of the alg-binding logic itself
# (lucas42/lucos_worlds#29) — no docker-compose stack needed.
set -eu
cd "$(dirname "$0")"

docker build -t lucos_worlds_unit_test_base -f ../../Dockerfile ../..
docker build -t lucos_worlds_unit_test --build-arg BASE_IMAGE=lucos_worlds_unit_test_base -f unit.Dockerfile .

docker run --rm --entrypoint /app/www/vendor/bin/phpunit lucos_worlds_unit_test \
    --bootstrap /app/www/vendor/autoload.php --no-configuration /tmp/AlgBindingTest.php
