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
ARG VERSION
ENV VERSION=$VERSION

# ES256/EC OIDC support patch (lucas42/lucos_worlds#26, decision in #21):
# lucos_aithne signs exclusively with ES256 and publishes no RSA key, but
# upstream BookStack's OIDC client is RSA/RS256-only by design (see
# BookStackApp/BookStack#5390, open/unresolved as of 2026-07). These three
# files patch BookStack's OIDC key filtering and signature verification to
# also accept EC/ES256 — see the in-file comments for the exact rationale.
# Unlike the theme files below, /app/www is NOT a persistent volume (only
# /config is), so a plain build-time COPY is sufficient here — no
# custom-cont-init runtime copy needed.
#
# This is a narrow, intentional fork of these 3 files, not a general
# willingness to patch BookStack — see ADR-0002 (lucas42/lucos_worlds#27).
# The integration test (test/oidc-es256/) exercises this patch end-to-end
# against the real image and gates CI, specifically so a future Dependabot
# BookStack version bump can't silently break it.
COPY patches/Oidc/OidcProviderSettings.php /app/www/app/Access/Oidc/OidcProviderSettings.php
COPY patches/Oidc/OidcJwtWithClaims.php /app/www/app/Access/Oidc/OidcJwtWithClaims.php
COPY patches/Oidc/OidcJwtSigningKey.php /app/www/app/Access/Oidc/OidcJwtSigningKey.php

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
COPY custom-cont-init.d/30-set-admin-role-auth-id.sh /custom-cont-init.d/30-set-admin-role-auth-id.sh
RUN chmod +x /custom-cont-init.d/10-copy-theme.sh /custom-cont-init.d/20-set-branding.sh /custom-cont-init.d/30-set-admin-role-auth-id.sh
