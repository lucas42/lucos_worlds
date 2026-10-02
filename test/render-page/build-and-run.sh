#!/bin/sh
# Builds the real, patched lucos_worlds_web image, then runs the page-render
# smoke test (lucas42/lucos_worlds#99) against it with a disposable database.
# The driver runs inside the compose network because CircleCI's
# setup_remote_docker daemon is on another host, so published ports aren't
# reachable from the job. Used identically by CircleCI and local runs.
set -eu
cd "$(dirname "$0")"

docker build -t lucos_worlds_render_test -f ../../Dockerfile ../..
export LUCOS_WORLDS_WEB_TEST_IMAGE=lucos_worlds_render_test

trap 'docker compose down -v --remove-orphans >/dev/null 2>&1' EXIT
docker compose build driver
docker compose up -d --wait web
rc=0
docker compose run --rm driver || rc=$?
if [ "$rc" != 0 ]; then
    echo "--- laravel.log errors from web ---"
    docker compose exec -T web sh -c 'grep production.ERROR /app/www/storage/logs/laravel.log | cut -c1-300 | tail -5' || true
fi
exit "$rc"
