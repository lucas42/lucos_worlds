#!/usr/bin/with-contenv bash
# Sets the built-in BookStack "Admin" role's External Auth ID to
# `worlds:admin` on every container start, via BookStack's own Role model
# rather than a hand-edited row in a database schema this repo doesn't own.
#
# This is the automated, repeatable form of a manual step that previously
# had to be done directly against the production database (there is no
# BookStack UI/env-var route for this field) — see
# lucas42/lucos_worlds#17's "safe sequence" write-up for the full
# lock-out-risk analysis behind this design, and why setting this field
# alone (independent of the OIDC_* group-sync vars in docker-compose.yml)
# is safe to do unconditionally, in any order, in any environment:
# BookStack's OIDC group-role sync only ever *reads* this field during a
# login where OIDC_USER_TO_GROUPS=true, so writing it here has no effect
# in dev (AUTH_METHOD=standard, group-sync never runs) and no effect in
# prod until a login actually happens.
#
# `Role::getSystemRole('admin')` (rather than a raw SQL query) is the same
# lookup BookStack's own migrations use to find the built-in Admin role —
# confirmed against the pinned image in Dockerfile. Reusing the existing
# Admin role (rather than creating a new "worlds:admin" role) means the
# aithne `worlds:admin` scope inherits the Admin role's full existing
# permission set with nothing to hand-copy.
#
# Idempotent and git-is-the-source-of-truth, same rationale as
# 20-set-branding.sh: re-running with the same value on every start is a
# no-op, so a future hand-edit of this field via the Admin Settings UI
# would just get reverted on the next container start — deliberate, see
# 20-set-branding.sh's comment on the same trade-off.
#
# Retries: same ordering caveat as 20-set-branding.sh — this script's
# position relative to BookStack's own `php artisan migrate` (which
# creates the roles table) isn't guaranteed by the base image's s6
# service graph, so wait for migrate to have done its job.
set -u

ATTEMPTS=0
MAX_ATTEMPTS=30
until php /app/www/artisan tinker --execute="
    \$role = \BookStack\Users\Models\Role::getSystemRole('admin');
    if (!\$role) {
        throw new \Exception('Admin system role not found');
    }
    \$role->external_auth_id = 'worlds:admin';
    \$role->save();
    echo 'lucos_worlds: Admin role external_auth_id set to worlds:admin' . PHP_EOL;
" 2>&1; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ ${ATTEMPTS} -ge ${MAX_ATTEMPTS} ]]; then
        echo "[admin-role-auth-id] gave up after ${MAX_ATTEMPTS} attempts — roles table may not exist yet"
        exit 1
    fi
    sleep 2
done
