# 2. Patch BookStack's vendored OIDC code for ES256 support

- **Status:** Accepted
- **Date:** 2026-07-08
- **Deciders:** lucas42 (owner), lucos-architect, lucos-system-administrator
- **Related:** lucas42/lucos_worlds#21 (root cause + options), lucas42/lucos_worlds#26 (implementation)

## Context

lucas42/lucos_worlds#21 found, verified against source, that OIDC login against
`lucos_aithne` does not work on the deployed `lucos_worlds` (BookStack): BookStack's
vendored OIDC client hardcodes acceptance of RSA/RS256 keys only, in two independent
places (`OidcProviderSettings::filterKeys()`, the JWKS discovery filter, and
`OidcJwtWithClaims::validateTokenSignature()`/`OidcJwtSigningKey`, the actual
signature-verification path). `lucos_aithne` signs OIDC ID tokens exclusively with
ES256 (EC/P-256), by deliberate design — already relied on by `lucos_locations` — and
has no RSA key to offer. The result: BookStack's discovery step filters out 100% of
aithne's keys, and clicking "Login with SSO" throws `InvalidArgumentException:
Missing required configuration "keys" value` (HTTP 500). Since `AUTH_METHOD=oidc` is
BookStack's only configured auth method, there is no fallback login path — nobody,
including lucas42, could log in.

No documented BookStack extension point (the "Hacking BookStack" theme/hook system)
offers a way to fix this without modifying vendored source — verified directly:
the crash occurs on the first line of `OidcService::login()`, before either of
BookStack's two OIDC theme hooks (`OIDC_AUTH_PRE_REDIRECT`, `OIDC_ID_TOKEN_PRE_VALIDATE`)
can ever fire, and neither hook touches signature verification even in principle.
No newer BookStack version fixes this either — confirmed unchanged on BookStack's
`development` branch (fetched live, 2026-07-07); there is a 6-month-old open upstream
feature request for ES256 support with no resolution.

lucas42 explicitly flagged a strong aversion to maintaining a long-term fork of a
third-party library, especially one he's new to operating. The options assessed:

1. **Patch BookStack's vendored OIDC code**, delivered via the wrapper image.
2. **Have `lucos_aithne` additionally publish an RS256 key** alongside its ES256 key,
   for RS256-only relying parties.
3. **An auth-proxy retrofit** in front of BookStack (the pattern `lucos_locations`
   already uses via oauth2-proxy) — not assessed in depth before this decision was made.
4. Reconsider the tool choice entirely.

A key clarification made during the decision: framing option 1 as "in our wrapper
layer" does **not** avoid fork-maintenance semantics — whether the patched files are
baked into the image at build time or copied in at container start via
`custom-cont-init`, we are authoring and owning modified versions of BookStack's
actual authentication source, and must re-verify that patch against every future
upstream version bump.

## Decision

**Patch BookStack's vendored OIDC code (Option 1)**, accepting the narrow-fork
maintenance cost, subject to two conditions that make the cost bounded and visible
rather than open-ended:

1. **A real, automated integration test is required, not optional.** Because we're
   patching internal code, version numbers and release notes cannot be trusted to
   reveal a break — even an apparently-inert upstream change (e.g. renaming source
   files touched by the patch) could silently break the login flow. The test
   (`test/oidc-es256/`) drives the **full ES256 OIDC login flow end-to-end against
   the real, patched image** and a mock ES256-only OIDC provider — not a unit test —
   and is wired as a required CI status check ahead of `lucos/deploy-avalon`. A
   future Dependabot BookStack-version-bump PR that breaks the patch fails this
   check and cannot auto-merge; one that doesn't break it merges as normal.
2. **The patch went through a real security review** (not a rubber-stamp), since it
   modifies signature-verification logic — a bug here is an authentication bypass,
   not a cosmetic regression.

### The patch itself

Three files, ~40 lines of new/changed code, no new dependencies (BookStack already
vendors `phpseclib3`, which has full, already-working EC/ECDSA support, including the
raw-signature "IEEE" format JWT's ES256 uses):

- `OidcProviderSettings::filterKeys()` — additionally accept EC/P-256/ES256 keys.
- `OidcJwtWithClaims::validateTokenSignature()` — additionally accept an `ES256`
  token header (this is a second, independent RS256-only gate; relaxing the filter
  alone is not sufficient).
- `OidcJwtSigningKey` — a new EC branch that loads the key via phpseclib3's own
  JWK-format support and sets `.withSignatureFormat('IEEE')`.

Verified empirically (not just by code review) against a real, independently-generated
EC keypair and a genuine ES256-signed test token before being wired into the app:
a valid signature verifies true, a tampered one verifies false, and the existing RSA
path is unaffected (regression-checked).

Delivered via a plain `Dockerfile COPY` overwriting the vendored files at their
`/app/www/app/Access/Oidc/...` paths at build time — `/app/www` is not a
volume-backed path (unlike `/config`, used for the theme-sync mechanism), so a
build-time copy is sufficient; no runtime `custom-cont-init` copy is needed here.

## Scope — this is not a precedent

This decision applies **only because BookStack is currently the sole tool in the
lucos estate whose OIDC client doesn't support ES256.** It is explicitly **not** a
precedent for how future tools should be integrated with `lucos_aithne` SSO — the
default expectation for a new SSO integration remains "the tool supports ES256, or we
pick a tool/approach that does," not "patch it to support our provider." If the
estate's SSO-integrated tools become more varied in which signing algorithms they
support, the better fix is revisiting whether `lucos_aithne` itself should support
multiple signing algorithms (option 2 above), rather than accumulating per-tool
patches.

## Consequences

### Positive

- Unblocks OIDC login for `lucos_worlds` without any change to `lucos_aithne`'s
  signing posture (which `lucos_locations` already depends on).
- Small, well-scoped, already-verified patch — not a rewrite of BookStack's auth
  system.
- The integration test converts "silent breakage risk" into "loud CI failure,"
  which is the actual mitigation for the fork-maintenance risk lucas42 was
  concerned about — not the smallness of the diff alone.

### Negative / honest trade-offs

- **We now own a narrow fork of 3 BookStack files.** Every future Dependabot
  BookStack version bump needs this patch re-verified (the integration test does
  this automatically) and, if those files changed meaningfully upstream, re-adapted
  by a human.
- This is an explicit, bounded exception to the "thin wrapper, don't own the code"
  premise from ADR-0001 — acceptable here because of the integration-test guardrail,
  not because the premise no longer matters.
- If BookStack ever upstreams ES256 support (tracked: their open feature request),
  this patch should be dropped in favour of the stock behaviour.

## Alternatives considered

See lucas42/lucos_worlds#21 for the full technical assessment. In brief:

- **aithne publishes an additional RS256 key** — doesn't touch BookStack at all, but
  changes aithne's signing/key-management posture for all clients, not just this one;
  not pursued for this specific, currently-unique need.
- **Auth-proxy retrofit** (oauth2-proxy, as `lucos_locations` uses) — the one option
  that could avoid touching BookStack's source entirely; not assessed in depth before
  this decision, remains a candidate if the patch-maintenance cost turns out to be
  worse in practice than expected.
- **Reconsider the tool** — rejected for now; BookStack was already chosen deliberately
  in ADR-0001 for its licence, hierarchy fit, and native (if algorithm-limited) OIDC
  support.
