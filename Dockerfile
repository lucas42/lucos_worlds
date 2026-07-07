# lucos_worlds_web — thin wrapper around the upstream BookStack image.
#
# There is no official image published by the BookStack project itself (only
# community images — see lucas42/lucos_worlds ADR-0001). linuxserver/bookstack
# is the most actively maintained community image and publishes multi-arch
# (amd64 + arm64) builds. Wrapping it (rather than deploying it directly)
# keeps the estate's lucas42/lucos_<project>_<role> image convention, pins
# the version in git so Dependabot can raise upgrade PRs, and gives a home
# for the baked-in "lucos" theme.
FROM lscr.io/linuxserver/bookstack:26.05.2

# Theme source lives OUTSIDE /config on purpose: /config is a persistent
# volume, so anything baked into the image under a /config-backed path would
# be shadowed forever after the first container start. The custom-init
# script below copies from here into the persisted /config/www/themes/lucos
# on every start, so an updated theme always wins.
COPY theme/lucos /theme-source/lucos

# linuxserver images run any executable script dropped into
# /custom-cont-init.d after the base image's own init (which is what
# populates /config on first run) and before the app service starts —
# see https://docs.linuxserver.io/general/container-customization/.
COPY custom-cont-init.d/10-copy-theme.sh /custom-cont-init.d/10-copy-theme.sh
COPY custom-cont-init.d/20-set-branding.sh /custom-cont-init.d/20-set-branding.sh
RUN chmod +x /custom-cont-init.d/10-copy-theme.sh /custom-cont-init.d/20-set-branding.sh
