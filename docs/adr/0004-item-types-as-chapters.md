# 4. Represent item types as Chapters, not tags

- **Status:** Proposed
- **Date:** 2026-08-06
- **Deciders:** lucas42 (owner), lucos-architect
- **Related:** ADR-0001 §1 (partially superseded by this ADR); ADR-0005 (the NPC stat
  block convention, which depends on chapter-level templates). Discussed directly with
  lucas42 on 2026-08-06; there is no originating ticket.

## Context

ADR-0001 §1 mapped item type onto a page **tag** — `type=pc`, `type=npc`, `type=place`.
That ADR was explicit that this was "a *soft convention enforced by discipline*, not by
the software", and listed it among its own honest negatives:

> **Types are a soft (tag) convention, not enforced** — mis-tagging is possible; fine for
> one disciplined user, would need revisiting if the tool became multi-user.

**In practice the convention was never adopted.** lucas42 has been using **Chapters** to
carry type since the system went into real use, and no page carries a `type=` tag
(confirmed by lucas42, 2026-08-06). The divergence surfaced while designing the NPC stat
block convention (ADR-0005) — not through any failure. That is itself informative:
nothing depended on the tag strongly enough to break when it was absent.

Current usage is **two active Books**: one recording a world in which lucas42 is a
*player*, and one he is preparing to DM. These are not two instances of the same thing —
one is a record of what has been discovered, the other is authored content including
secrets and mechanics — and they do not need identical internal structure.

Two pieces of shipped theme CSS assume the tag convention is live:

- **The `type` chip rules** (`theme/lucos/public/css/custom.css`) give `type=pc|npc|place`
  a distinct solid-colour chip per value. Every selector keys on `[data-name="type" i]`,
  so with no `type=` tag in use these ~40 lines are **inert**.
- **The narrow-viewport tag strip** (lucas42/lucos_worlds#56, lucas42/lucos_worlds#60)
  reveals tags above the content below BookStack's `$bp-l` breakpoint. Its reveal rule is
  generic (`section:has(.tag-item)`), so it still works for whatever tags a page carries
  — but its explanatory comment asserts the `type=` tag convention as load-bearing, which
  is now misleading to the next person to touch the file.

Neither breaks anything. The CSS was deliberately written to degrade to nothing. The
material problem is the **stale record**, not the stale code.

### What each representation actually offers

Verified against the pinned BookStack image (`lscr.io/linuxserver/bookstack:26.05.2`,
matching the 26.05.3 tag currently on `main` at patch level):

| | Tag | Chapter |
|---|---|---|
| Enforcement | none — typo silently drops the page from any filter | structural: a page is in a chapter or visibly loose |
| Default page template | not available | `HasDefaultTemplateInterface` is implemented by `Book` and `Chapter` |
| Export | no tag-scoped export exists | `/books/{book}/chapter/{chapter}/export/{pdf,html,markdown,plaintext}` |
| Cross-book query | `[type=npc]` searches every Book at once | a chapter belongs to one Book; no aggregation |
| Multiple values | a page can carry many tags | a page sits in exactly one chapter |
| Navigation | requires a search | visible grouping in the book contents |

Reclassification is **free**: page routes are `/books/{bookSlug}/page/{pageSlug}` — the
chapter slug does not appear in a page's URL — so moving a retired PC into the NPCs
chapter breaks no links.

## Decision

**A page's item type is represented by the Chapter it sits in. The `type=` tag convention
in ADR-0001 §1 is withdrawn.**

Consequent decisions that follow from spending the chapter axis on type:

1. **Types are a per-Book content convention, not a fixed schema.** The two Books have
   different purposes and may carry different chapters. A rule asserting one estate-wide
   set of types would be broken again immediately, and silently.
2. **Place hierarchy is represented by in-page links.** ADR-0001 §1 offered three
   candidates — Chapters, a `region=`/`parent=` tag, or in-page links — and left the
   choice open as a content decision. Chapters are no longer available for it, and
   BookStack offers only one grouping level (Book → Chapter → Page), so this is now
   settled rather than open.
3. **ADR-0001 §1 is amended by pointer, not rewritten.** The original mapping and its
   reasoning stay readable as what was decided in July 2026. A reader following the
   history should be able to see that tags were chosen first, and why the choice changed.

## Consequences

### Positive

- **Retires a stated negative of ADR-0001.** Type becomes structural rather than a matter
  of discipline. A mistyped tag silently removes a page from every filter; a page in the
  wrong chapter is visible in the book contents.
- **Unlocks chapter-level default page templates**, which is what makes the NPC stat block
  convention in ADR-0005 cheap. A book-level template would also land on Places and PCs.
- **Unlocks chapter-level export.** "Export every NPC to PDF before leaving the house" is
  a single URL. This matters more than it looks: BookStack is not a PWA and does not work
  offline at all (`network_only: true` in its `/_info` is accurate), so an export is the
  only mitigation for losing connectivity mid-session.
- **Navigation without search**, which matters on the tablet where BookStack's sidebars
  collapse into mutually-exclusive mobile tabs.

### Negative / honest trade-offs

- **The only grouping axis is now spent.** BookStack has exactly one level of nesting.
  Anything else wanting structural grouping — place hierarchy, factions, sessions — must
  use links, tags or naming conventions.
- **No cross-book type query.** `[type=npc]` would have searched every Book at once;
  chapters do not aggregate. Assessed as near-zero cost here: the two Books are a
  player's notes and a DM's prep, and there is no question worth asking across both.
- **A page has exactly one type.** For types specifically this is arguably correct rather
  than a limitation, but it is a real constraint.
- **~40 lines of dead CSS to remove**, plus a misleading comment to correct — tracked
  separately, and blocked on this ADR.
- **Still a soft convention.** Chapters make type structural *within* a book, but nothing
  stops a page being created loose in the Book with no chapter at all.

## Alternatives considered

- **Keep the tag convention and correct the practice** — rejected. It would give up
  templates, chapter export and structural enforcement in order to regain a cross-book
  query that has no use here, and it would mean changing working practice to match a
  document rather than the reverse.
- **Use both — chapters for structure, `type=` tags retained as a parallel marker** —
  rejected. It reintroduces exactly the unenforced duplicate that chapters eliminate, and
  a duplicate where only one of the two representations is ever consulted will drift. The
  only concrete benefit would have been keeping the cross-book query and the existing chip
  CSS alive; the query is not wanted, and preserving ~40 lines of CSS is not a reason to
  maintain a second representation of the same fact.

## Deferred work

1. **Theme cleanup** — remove the inert `[data-name="type" i]` chip rules and correct the
   `#56` rule's comment. Raised as a tracked issue, blocked on this ADR.
