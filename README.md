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

BookStack's OIDC integration hard-requires the issuer (and every discovered
endpoint) to be `https://`, checked in `OidcProviderSettings::validateInitial()`
before any network call is made — there is no config toggle to relax it. In
local dev, `lucos_aithne` normally runs over plain `http://localhost:8039`,
which BookStack rejects outright with a generic "An unknown error occurred"
in the UI (`InvalidArgumentException: Issuer value must start with https://`
underneath).

To work around this, dev BookStack's `AITHNE_ORIGIN` (`OIDC_ISSUER`) is set
in lucos_creds to **prod** aithne (`https://aithne.l42.eu`) rather than the
local dev instance. This means:

- Discovery, JWKS, token exchange, and userinfo calls from dev BookStack go
  out to prod aithne over the public internet.
- The browser is redirected to prod aithne's authorize endpoint, so local
  dev login authenticates against **prod aithne identities**, not a local
  dev user set.
- The localhost redirect URI (`http://localhost:8040/oidc/callback`) is
  already registered on the prod `lucos_worlds` OIDC client — no separate
  registration is needed for this to work.
- The dev `KEY_LUCOS_AITHNE` client secret must match the secret prod
  aithne holds for the `lucos_worlds` client, or token exchange fails after
  redirect. This is a manual/production-side credential and needs
  lucas42 to set it correctly.

This coupling to prod identities is accepted as a trade-off in
lucas42/lucos_worlds#35.
