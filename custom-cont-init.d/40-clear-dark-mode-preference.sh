#!/usr/bin/with-contenv bash
# Clears everyone's persisted "dark-mode-enabled" preference on every
# container start, as the companion half of removing the dark-mode toggle
# (lucas42/lucos_worlds#61 — see the theme override at
# theme/lucos/common/dark-mode-toggle.blade.php for the other half).
#
# Removing only the toggle isn't safe on its own: the preference lives in
# BookStack's settings table under the per-user key `user:<id>:dark-mode-
# enabled` (app/Settings/SettingService.php's userKey()), read directly by
# resources/views/layouts/base.blade.php to set `class="dark-mode"` on
# <html> — confirmed against the pinned BookStack source, not assumed. With
# no toggle to flip it back, anyone whose preference is already true (most
# plausibly lucas42 himself, since he confirmed this bug on a real device
# while presumably in dark mode) would be stranded with unreadable headings
# and no way out. Clearing it here removes that trap.
#
# `Setting::where(...)->delete()` — BookStack's own Eloquent model, not raw
# SQL against a table this repo doesn't own — rather than looping every
# User and calling SettingService, because SettingService's key-building
# (userKey()) is protected and has no "for every user" variant; a single
# scoped query is both the more idiomatic and the cheaper option here. The
# `LIKE` pattern only ever touches this one setting suffix, never a user's
# other preferences (sort order, view mode, etc.) — deliberately narrower
# than SettingService::deleteUserSettings(), which wipes everything for a
# user.
#
# Idempotent and git-is-the-source-of-truth, same rationale as
# 20-set-branding.sh: once the toggle is gone there's no UI path back to
# `true`, so after the first run this is permanently a no-op — safe to
# leave running on every start rather than special-casing "only if needed".
#
# Retries: same ordering caveat as 20-set-branding.sh and
# 30-set-admin-role-auth-id.sh — this script's position relative to
# BookStack's own `php artisan migrate` (which creates the settings table)
# isn't guaranteed by the base image's s6 service graph, so wait for
# migrate to have done its job.
set -u

ATTEMPTS=0
MAX_ATTEMPTS=30
until php /app/www/artisan tinker --execute="
    \$count = \BookStack\Settings\Setting::where('setting_key', 'like', 'user:%:dark-mode-enabled')->delete();
    echo \"lucos_worlds: cleared dark-mode-enabled preference for {\$count} user(s)\" . PHP_EOL;
" 2>&1; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ ${ATTEMPTS} -ge ${MAX_ATTEMPTS} ]]; then
        echo "[clear-dark-mode-preference] gave up after ${MAX_ATTEMPTS} attempts — settings table may not exist yet"
        exit 1
    fi
    sleep 2
done
