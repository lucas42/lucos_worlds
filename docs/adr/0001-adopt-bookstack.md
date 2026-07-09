# 1. Adopt BookStack as the worldbuilding system

- **Status:** Proposed
- **Date:** 2026-07-07
- **Deciders:** lucas42 (owner), lucos-architect
- **Related:** lucas42/lucos#248 (originating brief + build-vs-adopt evaluation)

## Context

`lucos_worlds` is a new system to satisfy the brief in lucas42/lucos#248: a private,
web-hosted worldbuilding tool for TTRPGs (e.g. D&D) and other fictional worlds.

Requirements, as refined on that issue:

- Multiple **standalone worlds/universes** that don't interact with each other.
- Within a world, a **fixed** set of item types: **Player Characters, NPCs, Places**.
  Places may have an informal hierarchy (region / town / building), but those
  sub-levels need not be first-class types.
- **Free-text, multi-paragraph** content per item, with **links between items**
  (wiki-style linking desired, but acceptable to defer to a phase 2).
- **Per-item image upload** — single images in a specific context. Explicitly *not*
  the bulk file-uploader work (lucas42/lucos#209).
- **Mobile-friendly.**
- **Behind authentication**, ideally `lucos_aithne`.
- **Single user** (lucas42) for now.
- **Data export** useful; the data store must be **self-hosted** and **not encrypted
  with a key we don't control**. Schema need not be export-perfect (transform on export).
- A **custom map-creation tool** is wanted "at some point" — build-in vs link-out is a
  later decision.

The build-vs-adopt evaluation on lucas42/lucos#248 surveyed purpose-built tools (kanka),
SaaS worldbuilding tools (World Anvil, LegendKeeper — ruled out on data control),
desktop apps (chronicler, Obsidian — ruled out on web-hosting), generic self-hostable
wikis with native OIDC SSO (BookStack, Outline, Wiki.js), and a self-build. No
off-the-shelf tool offered *both* a first-class typed model *and* clean aithne SSO.
lucas42's decision: **adopt BookStack**, model the three types as **tags**, and apply
custom CSS for a "fantasy" feel.

## Decision

Self-host **BookStack** in the lucos estate as the system `lucos_worlds`.

### 1. Content model mapping (types-as-tags)

- **World/universe** → a BookStack **Book** (a Shelf can group Books if a world grows
  large). Starting convention: one world = one Book.
- **Item** (character/place) → a **Page** within the world's Book.
- **Type** → a page **tag**: `type=pc`, `type=npc`, `type=place`. Types are a *soft
  convention enforced by discipline*, not by the software — BookStack will not prevent
  mis-tagging. Acceptable for a single user.
- **Place hierarchy** (region / town / building) → represented informally (e.g. Chapters
  within the Book, a `region=`/`parent=` tag, or in-page links). Not modelled as
  first-class types, per the brief. The exact convention is a *content* decision settled
  at setup, not a code change.

### 2. Authentication — `lucos_aithne` via OIDC

- BookStack's native OIDC support (verified against BookStack docs: `AUTH_METHOD=oidc`,
  `OIDC_ISSUER`, `OIDC_ISSUER_DISCOVER=true`, `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET`)
  points at **`lucos_aithne` as the OIDC OP** (`lucos_aithne` ADR-0001, Accepted).
- `lucos_worlds` is registered as an OIDC client in aithne. First-login auto-registration
  provisions the single user.
- Group/role sync (`OIDC_USER_TO_GROUPS`) is available but unnecessary for one user —
  left off.

> **Amendment (2026-07-08, [#17](https://github.com/lucas42/lucos_worlds/issues/17)):**
> Group/role sync is now enabled. lucas42 asked whether aithne scopes could drive real
> BookStack RBAC (admin/editor/viewer) rather than a single catch-all login gate.
> Investigation found aithne's OIDC id_token already carries an `effectiveScopes` claim
> (`scopes`) purpose-built for a generic OIDC RP to gate on (`lucos_aithne`#277) — no
> aithne code changes needed. `docker-compose.yml` now sets `OIDC_USER_TO_GROUPS=true`,
> `OIDC_GROUPS_CLAIM=scopes`, `OIDC_ADDITIONAL_SCOPES=worlds:admin`,
> `OIDC_REMOVE_FROM_GROUPS=true`, and `lucos_auth_scopes` gained a `worlds:admin` scope.
> Right-sized to a single scope for the single current user; `worlds:editor` /
> `worlds:viewer` are deferred until a real second-privilege user appears
> (default-deny). One step remains manual, outside this repo: granting
> `worlds:admin` to lucas42's aithne principal (`/admin/grants`, production-only,
> lucas42-only). Setting the **External Auth ID** field on BookStack's existing
> built-in **Admin** role to `worlds:admin` — reusing the existing Admin permission set
> rather than hand-building a new role — was originally a manual Admin Settings UI /
> direct-database step (there is no env var for it), but is now automated on every
> container start by `custom-cont-init.d/30-set-admin-role-auth-id.sh`, via BookStack's
> own `Role` model rather than a hand-edited database row. `OIDC_REMOVE_FROM_GROUPS=true`
> replaces *all* roles on every OIDC login, so the original rollout had to be sequenced
> carefully to avoid locking lucas42 out entirely — see
> [#17](https://github.com/lucas42/lucos_worlds/issues/17) for the lock-out-safe sequence
> and verification checkpoints used for that one-off rollout (now moot for future/DR
> deployments, since the Admin-role mapping is applied automatically before any login
> can happen).

### 3. Deployment shape

- **`docker-compose.yml`** with two services:
  - `lucos_worlds_web` — a **thin wrapper image** `lucas42/lucos_worlds_web`, built
    `FROM` a **version-pinned upstream BookStack image**. Wrapping (rather than pulling
    upstream directly) keeps the estate's `lucas42/lucos_<project>_<role>` image
    convention, pins the version in git, lets Dependabot's Docker ecosystem raise upgrade
    PRs on the `FROM` tag, and gives a home for the baked-in theme.
  - `lucos_worlds_db` — **MariaDB** (BookStack requires MySQL/MariaDB), official image,
    pinned.
- **Named volumes** (both declared in compose *and* registered in
  `lucos_configy/config/volumes.yaml`):
  - the MariaDB data volume;
  - the BookStack **file/image storage** volume (uploaded images and attachments live on
    the filesystem).
  - *Caution:* a named volume mounted over a path shadows image contents indefinitely.
    The theme, if baked into the image, must live at a path **not** covered by the
    storage volume, or the volume will hide it.
- **Startup ordering:** `depends_on` does not wait for MariaDB readiness; the web service
  needs a startup retry (or a healthcheck-gated dependency) so it does not crash-loop
  before the DB is up.
- **Secrets in `lucos_creds`** (development is agent-writable; production is lucas42-only):
  Laravel `APP_KEY`, MariaDB password, `OIDC_CLIENT_SECRET`. Non-sensitive, non-varying
  values (DB name, internal service URLs) are hardcoded in compose per convention;
  environment-varying values (`APP_URL`, `OIDC_ISSUER`) come from creds. Avoid building
  compound values via compose interpolation — the CI build step only has a dummy `PORT`.
- **CI/deploy:** the standard lucos CircleCI orb (`lucos/deploy`), building the wrapper
  image. Host/architecture selection (amd64 vs avalon arm64) is a setup-ticket decision;
  the upstream BookStack image publishes arm64, so either is viable.

### 4. Theming (the "fantasy" look)

- **Verified mechanism:** BookStack's **Custom HTML Head Content** setting injects
  arbitrary CSS — sufficient for parchment-textured backgrounds and cursive heading fonts
  (via the `--font-heading` / `--font-body` CSS variables plus a web-font import).
- **Decision:** keep the fantasy CSS **version-controlled in this repo**, not only as a
  live database setting, so it is reproducible and reviewable. The preferred route is
  BookStack's file-based **logical theme system** (`APP_THEME` + a `themes/<name>/`
  directory) *if* it supports the head-content injection we need — **to be confirmed at
  setup**. If it does not, the fallback is to hold the CSS as a file in the repo and apply
  it as a documented, seeded setup step. Either way, git is the source of truth for the
  theme — never a hand-edited production setting.

### 5. Backups

- The two stateful volumes (DB + file storage) are **both** critical — losing either
  loses data. Both are registered for `lucos_backups`. The DB backup should be a logical
  dump (`mysqldump`) or a quiesced volume copy for crash-consistency; the file-storage
  volume is a straightforward file backup.

## Consequences

### Positive

- Minimal application code to own; fast to stand up.
- **MIT licence** (verified on the BookStack repo) — no ambiguity, unlike some
  alternatives.
- Native OIDC SSO gives clean `lucos_aithne` integration with **no forking**.
- Mobile UI, rich-text editing, and per-item image upload come for free.
- Data lives in a plain MariaDB DB + filesystem, unencrypted by any foreign key, so
  **export is always possible** (`mysqldump`, or BookStack's HTTP API/export; transform on
  export as needed). Satisfies lucas42's data-control constraint by construction.
- BookStack has native cross-page linking and an inbound-**"References" (backlinks)**
  panel, so the phase-2 wiki-linking requirement may be **largely satisfied out of the
  box** (to be verified against what the pinned version ships); phase 2 may reduce to
  `[[…]]`-style authoring sugar rather than building linking from scratch.

### Negative / honest trade-offs

- **We don't own the code.** Security and upgrade tracking become an ongoing operational
  task. Mitigations: pin the image tag; Dependabot Docker-ecosystem PRs for new BookStack
  releases; watch BookStack security advisories. **CodeQL provides no coverage** (not our
  source) — a divergence from the estate norm where CodeQL guards our own code.
- **A new database engine (MariaDB/MySQL) enters the estate** — most lucos systems use
  Postgres or SQLite. Extra operational surface and a distinct backup path.
- **Two critical stateful volumes** rather than one.
- **No lucos `/_info` endpoint.** BookStack will not serve the estate's monitoring schema,
  so it will not plug into `lucos_monitoring` the standard way. For a single-user personal
  tool the monitoring bar is low, but this gap should be a *conscious* choice, not an
  accident (tracked as deferred work).
- **Types are a soft (tag) convention, not enforced** — mis-tagging is possible; fine for
  one disciplined user, would need revisiting if the tool became multi-user.
- **Custom CSS is cosmetic and version-coupled** — the theme may need touch-ups when
  BookStack makes major UI/markup changes across upgrades.
- **Hard dependency on `lucos_aithne`** for all access — if aithne is down, `lucos_worlds`
  is inaccessible. Consistent with other aithne consumers; acceptable.

## Alternatives considered

See the build-vs-adopt evaluation on lucas42/lucos#248 for the full survey. In brief:

- **Self-build** — rejected: more to own for a small, fixed model.
- **kanka** — rejected: its own auth (aithne would mean forking/patching), an unclear
  licence, and a large PHP app to security-track.
- **Outline / Wiki.js** — rejected: BSL / AGPL licences versus BookStack's MIT, and
  BookStack's Book→Page hierarchy fits worlds→items well.

BookStack was chosen for a clean licence + native SSO + hierarchy fit, accepting
types-as-tags as the cost.

## Deferred work (each to be raised as a tracked GitHub issue)

1. **Wiki-style linking (phase 2)** — assess whether BookStack's native linking +
   References panel suffices, or whether `[[…]]` authoring sugar is wanted.
2. **Custom map-creation tool** — build-in vs link-out; likely a separate future system
   (a candidate `lucos_atlas`).
3. **Data export** — a concrete export/verification mechanism (even if just a documented
   `mysqldump` + transform).
4. **Monitoring / `/_info` gap** — decide how, or whether, `lucos_worlds` integrates with
   `lucos_monitoring` given it cannot serve `/_info` natively. *(Resolved in ADR-0003.)*
