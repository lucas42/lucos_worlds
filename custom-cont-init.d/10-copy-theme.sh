#!/usr/bin/with-contenv bash
# Re-syncs the version-controlled "lucos" theme into BookStack's persistent
# /config volume on every container start.
#
# /config/www/themes is symlinked from /app/www/themes by the base
# linuxserver/bookstack image, and /config is a named Docker volume that
# survives container recreation. Without this step, a theme baked into a new
# image build would never actually take effect after the first deploy — the
# stale copy already sitting in the volume would silently keep shadowing it
# (see lucas42/lucos_worlds ADR-0001's caution on this exact class of bug).
#
# Overwriting here is deliberate and safe: this directory only ever holds a
# build artifact copied from the image, never user-authored data.
set -eu
mkdir -p /config/www/themes/lucos
cp -rf /theme-source/lucos/. /config/www/themes/lucos/
