# 3. Serve `/_info` from an adopted app via its own extension hook (shim as fallback)

- **Status:** Accepted
- **Date:** 2026-07-09
- **Deciders:** lucas42 (owner), lucos-architect
- **Related:** [#6](https://github.com/lucas42/lucos_worlds/issues/6) (decision thread); ADR-0001 §Deferred-work #4 (the `/_info` gap this resolves); ADR-0002 (the ES256 OIDC patch, referenced as a contrasting *fork* class of change)

## Context

ADR-0001 adopted BookStack and consciously **deferred** the monitoring `/_info` gap (Deferred-work #4): BookStack does not serve the lucos `/_info` schema, so `lucos_worlds` could not join `lucos_monitoring` the standard way. ADR-0001's Consequences leaned toward *accepting* the gap and monitoring liveness externally.

Two developments (worked through on [#6](https://github.com/lucas42/lucos_worlds/issues/6)) **superseded** that lean:

1. **The gap self-escalates to red.** `lucos_monitoring` does not take a hand-maintained system list — it generates one at image-build time from `configy.l42.eu/systems/http`. `lucos_worlds` already carries `http_port: 8040` in configy, so the next monitoring rebuild auto-adds it; because `worlds.l42.eu/_info` returns 404 (BookStack serves normal HTML there), the generated check fails and worlds shows **permanently red**. "Accept the gap / do nothing" is therefore not a stable resting state — it becomes a red check on the next unrelated monitoring deploy.
2. **A concrete functional consumer appeared.** lucas42 required `lucos_worlds` on the `lucos_root` homepage, whose service tile is driven by `/_info` (verified: `lucos_root` `src/main.go` reads `title`/`icon`/`show_on_homepage`/`network_only`/`start_url` from `/_info`). This is a functional need beyond monitoring that the "accept the gap" lean did not anticipate.

Both consumers fetch **`https://<domain>/_info`** over the public domain (verified: `lucos_monitoring` `fetcher_info.erl` builds `"https://" ++ Domain ++ "/_info"`; `lucos_root` `main.go` builds `scheme://entry.Domain + "/_info"`), and `lucos_router` maps each domain to a **single backend** (`templates/https.conf` is one `location / { proxy_pass {{backend}} }`, no per-path split). So `/_info` **must be served from within the worlds deployment** — a standalone sidecar on a separate domain cannot intercept it.

Two mechanisms were compared on [#6](https://github.com/lucas42/lucos_worlds/issues/6):
- **A** — a reverse-proxy shim container that becomes the `worlds.l42.eu` backend, serves `/_info` itself, and proxies everything else to BookStack.
- **C** — add a `/_info` route *inside* the BookStack image via its own extension point.

## Decision

Adopt the general pattern, and apply it to `lucos_worlds`:

> **An adopted third-party app joins the `/_info` convention via the app's own supported extension hook where one exists. A reverse-proxy shim is the documented fallback for apps that expose no such hook.**

For `lucos_worlds` specifically, implement `/_info` **in-app via BookStack's logical theme system** (Option C):

- Add **`theme/lucos/functions.php`** that registers a public `GET /_info` route on the **`ROUTES_REGISTER_WEB`** theme event. This is verified public/unauthenticated: `ROUTES_REGISTER_WEB` is dispatched inside the **`web`-middleware-only** route group in `app/App/Providers/RouteServiceProvider.php`, separate from the `['web','auth']` group that gets `ROUTES_REGISTER_WEB_AUTH` — so a route registered here is unauthenticated, exactly like BookStack's own `/status`. The theme `functions.php` is loaded by `ThemeService::readThemeActions()` (`require theme_path('functions.php')`).
- The route handler computes BookStack's `database`/`cache`/`session` health **in-process at request time** (mirroring BookStack's own `StatusController`) and returns the lucos `/_info` schema plus the static homepage fields (`title`, `icon` — a real path that 200s on the domain, e.g. the theme's `logo.png`; `show_on_homepage: true`; `network_only: true`; `start_url`).

### Why C over A for worlds

- **Supported API, not a fork.** The theme route hook is BookStack's documented extension mechanism — a *lower-risk class of change* than the ES256 OIDC patches (ADR-0002), which overwrite internal core files. lucas42's "we're already modifying the image" holds, and this addition is safer than the modifications already present.
- **Near-zero machinery.** It rides worlds' already-shipped, already-active theme pipeline (`APP_THEME=lucos`, copied live on every start by `custom-cont-init.d/10-copy-theme.sh`) — one additive file. No new container, no compose rewire, no new CI, and **zero effect on the OIDC flow**.
- **Honest health signal.** The in-process, request-time dependency check honours the estate's "Docker healthy ≠ reachability" concern (real DB/cache/session health, not container liveness), equivalent to the shim's `/status` probe.

### Why the shim stays the fallback

An adopted app with no route-registration hook cannot do C. The reverse-proxy shim (serve `/_info` locally, proxy the rest to the app, and probe the app's real health endpoint at request time) works for **any** app. Its costs — an extra container, a compose rewire, and an OIDC-through-proxy correctness surface (must preserve `Host` + `X-Forwarded-Proto`) — are exactly why it is the fallback, not the default.

## Consequences

### Positive

- `lucos_worlds` joins `lucos_monitoring` (auto-discovered via configy) **and** the `lucos_root` homepage with **no monitoring-side special-casing** — it serves its own `/_info` like any first-class lucos service.
- Real, request-time dependency-health signal (DB/cache/session) — no false-green while a dependency is down.
- Minimal footprint: one theme file; no new container/compose/CI; the OIDC flow is untouched.
- Establishes a reusable, honest estate pattern (hook-first, shim-fallback) for the next adopted third-party app.

### Negative / trade-offs

- **Deepens the "we extend BookStack" surface** — but via a supported API, not a core-file fork. Mitigation: extend the ADR-0002 integration-test harness to hit `/_info` and assert the schema *and* that it returns non-200/`ok:false` when a dependency is down — so a BookStack upgrade that broke the theme-events API is caught in CI, the same safety net used for the OIDC patch.
- **The pattern is app-specific by nature.** Each adopted app's hook differs, and some have none (→ shim). The pattern names the *decision procedure* (hook-first, shim-fallback), not a single mechanism.
- **`/_info` availability is coupled to BookStack's PHP app being up** — correct behaviour (app down ⇒ `/_info` fails ⇒ monitoring red), but worth stating: unlike the shim, there is no independent liveness surface. Acceptable, because app-down is precisely what monitoring should flag red.

### Scope / precedent

- This ADR **supersedes ADR-0001's Option-1 lean** on the `/_info` gap and **resolves** ADR-0001 Deferred-work #4. The superseding reasons are the self-escalation-to-red and lucas42's homepage-tile requirement.
- The hook-first / shim-fallback rule is estate-wide *guidance* for adopted third-party apps; it is **not** a `lucos_repos`-enforced convention.

## Alternatives considered

- **Option A — reverse-proxy shim container.** Most decoupled from BookStack internals (highest upgrade-resilience) and works for any app; rejected as the *default for worlds* because it adds a container, a compose rewire, and an OIDC-through-proxy correctness surface for no health-signal gain over C. **Retained as the documented fallback** for apps without a route hook.
- **Option B — inject a static `/_info` into BookStack's internal (LSIO) nginx.** Rejected: a static payload cannot reflect DB/cache/session health (would show green while a dependency is down, violating "healthy ≠ reachability"), and it deepens fragile coupling to LSIO's nginx internals.
- **Accept the gap (ADR-0001's original lean).** Rejected: not stable (self-escalates to red on the next monitoring rebuild) and does not satisfy the homepage-tile requirement.
