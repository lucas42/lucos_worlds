#!/bin/sh
# Builds the real, patched lucos_worlds_web image (the actual production
# Dockerfile — this test must exercise the genuine artifact, not a stand-in),
# then a thin test-only layer on top that trusts the mock IdP's TLS cert
# (see web.Dockerfile for why this has to be a second build stage rather
# than a docker-compose bind-mount), then runs the integration test stack.
#
# Used identically by CircleCI and local runs — see .circleci/config.yml.
set -eu
cd "$(dirname "$0")"

docker build -t lucos_worlds_web_test_base -f ../../Dockerfile ../..
docker build -t lucos_worlds_web_test --build-arg BASE_IMAGE=lucos_worlds_web_test_base -f web.Dockerfile .

export LUCOS_WORLDS_WEB_TEST_IMAGE=lucos_worlds_web_test
docker compose up --abort-on-container-exit --exit-code-from driver
