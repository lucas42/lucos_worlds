#!/usr/bin/with-contenv bash
# Sets lucos_worlds branding (app name + logo) on every container start,
# via BookStack's own settings store rather than the admin UI.
#
# There's no env-var route for this: BookStack's `app-name`/`app-logo`
# settings (app/Config/setting-defaults.php in the pinned BookStack source)
# have no env() binding, unlike some neighbouring settings in that same
# file. So this writes the settings directly, through BookStack's own
# SettingService (`php artisan tinker`) rather than raw SQL against a
# settings-table schema we don't own — keeping the same "git is the source
# of truth, never a hand-edited production setting" approach as the theme
# CSS from lucas42/lucos_worlds#2/#7. See lucas42/lucos_worlds#20.
#
# Also useful right now: BookStack's admin UI needs a login, and OIDC login
# is separately broken (see the RS256/ES256 issue) — this doesn't depend on
# that at all.
#
# Logo is a PNG, not SVG: BookStack's theme-asset route deliberately refuses
# to serve SVG inline (WebSafeMimeSniffer excludes image/svg+xml — SVGs can
# carry embedded script, so it forces attachment/octet-stream instead of
# rendering it as an <img>). Confirmed this empirically against the pinned
# BookStack source before picking PNG.
#
# Retries: this script's ordering relative to BookStack's own
# `php artisan migrate` (which creates the settings table) isn't guaranteed
# by the base image's s6 service graph — init-custom-files and
# init-bookstack-config sit on separate dependency branches. Rather than
# assume an ordering that isn't actually enforced, wait for migrate to have
# done its job.
set -u

ATTEMPTS=0
MAX_ATTEMPTS=30
until php /app/www/artisan tinker --execute="
    \$s = app(\BookStack\Settings\SettingService::class);
    \$s->put('app-name', 'lucos_worlds');
    \$s->put('app-name-header', true);
    \$s->put('app-logo', '/theme/lucos/img/logo.png');
    echo 'lucos_worlds branding applied' . PHP_EOL;
" 2>&1; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ ${ATTEMPTS} -ge ${MAX_ATTEMPTS} ]]; then
        echo "[branding] gave up after ${MAX_ATTEMPTS} attempts — settings table may not exist yet"
        exit 1
    fi
    sleep 2
done
