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

See lucas42/lucos_worlds#38 (supersedes the prod-aithne approach from
lucas42/lucos_worlds#35).
