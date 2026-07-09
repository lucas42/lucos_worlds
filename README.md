# lucos_worlds

A private, self-hosted worldbuilding system for TTRPGs and other fictional worlds.

Built by adopting [BookStack](https://www.bookstackapp.com/) behind `lucos_aithne`
authentication, with worlds modelled as Books, items as Pages, and types
(Player Character / NPC / Place) as tags.

The founding design decision is recorded in [`docs/adr/0001-adopt-bookstack.md`](docs/adr/0001-adopt-bookstack.md).

Originating brief and build-vs-adopt evaluation: lucas42/lucos#248.

## Deployment

Two services, defined in `docker-compose.yml`:

- `lucos_worlds_web` — a thin wrapper (see `Dockerfile`) around the
  [`linuxserver/bookstack`](https://docs.linuxserver.io/images/docker-bookstack/)
  image (there is no image published by the BookStack project itself). The
  wrapper bakes in a version-controlled "lucos" theme (see `theme/lucos/`)
  which a `/custom-cont-init.d` script re-syncs into BookStack's persistent
  `/config` volume on every container start, so an updated theme in a new
  image build always takes effect — see the comments in
  `custom-cont-init.d/10-copy-theme.sh` for why a plain volume mount alone
  would silently shadow it. The theme is currently a placeholder — the
  "fantasy" styling itself is tracked in lucas42/lucos_worlds#7.
- `lucos_worlds_db` — MariaDB.

Authentication is via `lucos_aithne` (OIDC), registered as an
[ADR-0004](https://github.com/lucas42/lucos_aithne/blob/main/docs/adr/0004-oidc-client-registration.md)
client in lucas42/lucos_aithne's `oidc_clients.json`.

RBAC maps aithne's `worlds:admin` scope onto BookStack's built-in Admin
role via BookStack's native OIDC group-role sync (`OIDC_USER_TO_GROUPS` and
related vars in `docker-compose.yml`) — see the ADR-0001 amendment for the
full design. The one piece BookStack has no env var for — setting the
Admin role's **External Auth ID** field to `worlds:admin` — is applied
automatically on every container start by
`custom-cont-init.d/30-set-admin-role-auth-id.sh`, so a from-scratch
deployment (e.g. disaster recovery) needs no manual database step. The
only manual step left is granting `worlds:admin` to a principal in
aithne's admin UI (`/admin/grants`).

`lucos_worlds_web` serves the estate-wide `/_info` endpoint (consumed by
`lucos_monitoring` and the `lucos_root` homepage) directly — no separate
container or reverse proxy. It's registered via a small addition to the
"lucos" theme (`theme/lucos/functions.php` + `InfoController.php`), using
BookStack's own documented `ROUTES_REGISTER_WEB` theme hook rather than a
core-file patch. It reports the same database/cache/session health BookStack's
own `/status` route does. See lucas42/lucos_worlds#6.

Two named volumes, registered in
[`lucos_configy/config/volumes.yaml`](https://github.com/lucas42/lucos_configy/blob/main/config/volumes.yaml)
per lucas42/lucos_configy#243: `lucos_worlds_db_data` (MariaDB data) and
`lucos_worlds_web_storage` (BookStack's `/config` — uploaded images,
attachments, and the synced theme copy).

`APP_KEY` must be a genuine Laravel application key —
`base64:` followed by 32 random bytes (e.g. `openssl rand -base64 32`), not
an arbitrary string. Laravel's encrypter rejects anything else at boot with
`Unsupported cipher or incorrect key length`.

## Local development auth

Production uses `lucos_aithne` OIDC (see above). Local dev does **not** —
BookStack hard-requires an `https://` OIDC issuer (checked in
`OidcProviderSettings::validateInitial()` before any network call is made,
with no config toggle to relax it), and dev `lucos_aithne` runs over plain
`http://localhost:8039`, so OIDC login is rejected outright with a generic
"An unknown error occurred" in the UI. Pointing dev at prod aithne instead
was considered and rejected — it's blocked by the lucos_creds non-prod →
prod guardrail, and it would place a working prod OIDC client secret in the
dev environment.

Instead, dev uses BookStack's **standard auth**, controlled by
`AUTH_METHOD` in `docker-compose.yml` (`${AUTH_METHOD:-oidc}` — defaults to
`oidc` so production, which never sets this cred, can't accidentally fall
open to standard auth). The dev environment sets `AUTH_METHOD=standard` in
lucos_creds, and a fresh BookStack database auto-seeds a default admin on
first migration:

```
admin@admin.com / password
```

This is dev-only and zero-config — no extra step beyond the `.env` fetch
already required for `APP_KEY`/`DB_PASSWORD`. Dev does not exercise the
real OIDC path; that's accepted since OIDC is verified working in
production. The `OIDC_*` vars stay in `docker-compose.yml` and are ignored
by BookStack under standard auth, so a developer who later stands up a
local HTTPS dev aithne (e.g. via mkcert) can flip `AUTH_METHOD=oidc` with
no rework.

The dev environment doesn't set `AITHNE_ORIGIN` or `KEY_LUCOS_AITHNE` at
all — those OIDC-only creds were removed once dev moved to standard auth.
`docker-compose.yml`'s `${AITHNE_ORIGIN:-}` / `${KEY_LUCOS_AITHNE:-}`
defaults keep `docker compose up` warning-free either way. Production sets
both and uses OIDC as normal.

See lucas42/lucos_worlds#38 (supersedes the prod-aithne approach from
lucas42/lucos_worlds#35) and lucas42/lucos_worlds#40 (creds/compose
cleanup).
