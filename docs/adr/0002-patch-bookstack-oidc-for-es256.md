# 2. Patch BookStack's OIDC to accept ES256 (via the wrapper image)

- **Status:** Proposed
- **Date:** 2026-07-08
- **Deciders:** lucas42 (decision on lucas42/lucos_worlds#21, 2026-07-08), lucos-architect
- **Related:** [ADR-0001 (adopt BookStack)](0001-adopt-bookstack.md); lucas42/lucos_worlds#21 (incident + decision of record); lucas42/lucos_worlds#26 (implementation); `lucos_aithne` ADR-0001 (ES256-only signing)

## Context

lucos_worlds adopts BookStack (ADR-0001), authenticating users via `lucos_aithne` over OIDC. On the first production verification, OIDC login returned HTTP 500 and nobody — including lucas42 — could log in.

Root cause (verified against source, full detail on lucas42/lucos_worlds#21): **BookStack's OIDC hardcodes RSA/RS256-only key acceptance** in three independent places — `OidcProviderSettings::filterKeys()`, `OidcJwtWithClaims::validateTokenSignature()`, and `OidcJwtSigningKey` (which builds an RSA key from JWK `e`/`n` via `phpseclib3\Crypt\RSA`). **`lucos_aithne` signs id_tokens exclusively with ES256** (EC/P-256) by deliberate design (`lucos_aithne` ADR-0001; its live JWKS publishes a single EC key; `lucos_locations` already depends on this). BookStack therefore filters out 100% of aithne's keys, ends with an empty key set, and throws. This is not fixable by configuration (the manual `OIDC_PUBLIC_KEY` path enforces the same RSA check), and no BookStack release — including its `development` branch — supports ES256; the upstream ES256 request is unresolved and a community PR was closed unmerged.

Options considered (full weighing on lucas42/lucos_worlds#21): (1) patch BookStack's OIDC for ES256 in the wrapper; (2) `lucos_aithne` additionally publishes an RS256 key; (3a) a plain oauth2-proxy gate; (3b) a re-signing OIDC shim; (4) switch to an ES256-native tool.

## Decision

**Patch BookStack's vendored OIDC code to accept ES256/EC keys, delivered through the existing wrapper image — and leave `lucos_aithne` ES256-only.**

The patch is small (~40 lines across 3 files; **no new dependency** — BookStack already vendors `phpseclib3`, which has EC support, and `firebase/php-jwt`, which already contains a proven EC-JWK→key reference implementation for P-256):

1. `OidcProviderSettings::filterKeys()` — accept `kty === 'EC' && alg === 'ES256'` alongside RSA/RS256.
2. `OidcJwtWithClaims::validateTokenSignature()` — relax its independent hardcoded `alg === 'RS256'` gate.
3. `OidcJwtSigningKey` — add an EC branch: parse the JWK `crv`/`x`/`y` into a `phpseclib3\Crypt\EC` key with `->withSignatureFormat('IEEE')` (the raw R‖S signature format JWTs use).

Implementation is tracked in lucas42/lucos_worlds#26.

## Consequences

### Positive
- Restores `lucos_aithne` SSO into BookStack with the compromise **contained in the low-criticality leaf**; aithne's deliberate ES256-only posture — and every other consumer, including `lucos_locations` — is untouched.
- Small, well-understood patch using **already-vendored, vetted crypto** (no new dependency, no hand-rolled cryptography), with a known-good reference implementation already in BookStack's own vendor tree.

### Negative / trade-offs
- **We now own a patch to BookStack's vendored, security-critical auth code.** It touches signature verification (a bug there is an auth bypass), so it requires a **`lucos-security` review of the implementation diff** (on #26) and must be **re-verified on every BookStack upgrade**.
- **Version numbers and release notes cannot flag breakage.** Because we patch internal code, even a seemingly-inert upstream change (e.g. renaming a source file) could silently break the integration. **Integration tests are therefore the sole defence** (lucas42's mandate): a suite that exercises the ES256 OIDC login end-to-end against the patched image, wired so that when Dependabot raises a BookStack version-bump PR the tests **gate auto-merge — pass → merge, fail → block** until the patch is brought up to date. (This wiring is required implementation work, in scope on #26.)
- An ongoing (bounded) maintenance line-item against ADR-0001's "thin wrapper, don't own the code" premise.

### Scope / precedent (explicit, per lucas42)
- This decision is made **only because BookStack is the sole tool in the estate that does not support ES256.** It **does not set a precedent** for future tools we add SSO to.
- If that changes — i.e. the estate becomes more varied in which signing algorithms its tools support — **re-evaluate**, explicitly including whether `lucos_aithne` should support multiple algorithms (rejected *here* on blast-radius grounds for one non-conforming leaf, but a legitimate reconsideration at estate scale).
- Revisit this ADR if BookStack gains upstream ES256 support — at which point the patch can be dropped and we return to a pure thin wrapper.

## Alternatives considered
(Full analysis on lucas42/lucos_worlds#21.)

- **`lucos_aithne` additionally publishes an RS256 key — rejected.** The shared, public JWKS cannot be scoped to one relying party: restricting who a token is *issued to* does not restrict who can *use the key material* (issuing-audience ≠ key usability). Keeping aithne single-algorithm is defence-in-depth against algorithm-confusion (RFC 8725). Per `lucos-security`'s recalibration: this is **not a live vulnerability in our current libraries today**, but the ES256-only stance is a **free protection for future integrations** — not worth trading away for one non-conforming leaf.
- **Re-signing OIDC shim — rejected.** A small service that re-mints aithne's ES256 as RS256 for BookStack is genuinely fork-free, but it is a new service we build, own, and run in the critical login path — **more owned code than the patch**, not less.
- **Plain oauth2-proxy gate — does not apply.** It can gate the route but cannot deliver a per-user BookStack login: BookStack has no reverse-proxy/header auth method, so it cannot consume a proxy identity (unlike `lucos_locations`' owntracks map UI, which needs no per-user application account).
- **Switch to an ES256-native tool — not chosen.** It would discard a live deployment, and no ES256-native alternative was verified to actually work.
