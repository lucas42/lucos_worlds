# 5. NPC stat blocks and spells

- **Status:** Proposed
- **Date:** 2026-08-06
- **Deciders:** lucas42 (owner), lucos-architect
- **Related:** [ADR-0004](0004-item-types-as-chapters.md) (types as Chapters — this ADR
  depends on chapter-level default templates); [ADR-0001](0001-adopt-bookstack.md)
  (adopting BookStack, and its no-fork thesis). Authoring procedure:
  [`docs/authoring-npcs.md`](../authoring-npcs.md). Discussed directly with lucas42 on
  2026-08-06; there is no originating ticket.

## Context

lucas42 has been using `lucos_worlds` as a **player**. He is now preparing to **DM** a
second world, which introduces a content class the system has never held: the mechanical
representation of an NPC — armour class, hit points, ability scores, actions, spells.

The operating context, established before any option was ranked:

- **In person, laptop at the table.** Not a VTT, not remote.
- **D&D 5e (2024 rules).**
- **Roughly an even mix** of reskinned published monsters and genuine homebrew.
- **Two Books** — one recording a world where lucas42 is a player, one he authors as DM.
- **Combat tracking is on paper** (decided separately; see Out of scope below).

"Stat blocks" bundles two things with opposite storage requirements, and separating them
is what makes the rest of this decision tractable:

1. **Stat block content** — AC, HP, ability scores, actions. Durable, authored, changes
   rarely. Wants versioning, search, and to sit beside the lore.
2. **Encounter runtime state** — *current* HP, conditions, initiative order. Ephemeral,
   changes every few seconds during a fight, worthless once it ends.

A wiki is well suited to (1) and actively hostile to (2): every hit-point change would
write a page revision, polluting the world's history with combat noise. This is the same
reasoning that rejected tags-as-session-bookmarks in lucas42/lucos_worlds#59.

### Constraints discovered in the BookStack source

Verified against `lscr.io/linuxserver/bookstack:26.05.2`:

- **BookStack has three editors**: `wysiwyg` (TinyMCE, legacy), `wysiwyg2024` (Lexical,
  current), and `markdown`. The editor is recorded **per page**
  (`PageEditorType::forPage($page) ?: getSystemDefault()`), so changing the system default
  does not touch existing pages.
- **The Lexical editor registers a closed set of node types** — Callout, Heading, Quote,
  List, Table, Caption, Image, HorizontalRule, Details, CodeBlock, Diagram, Media,
  Paragraph, Link. **There is no arbitrary-element node.** A hand-authored
  `<div class="statblock">` has nothing to become, and would be flattened on save.
- **A callout is a single paragraph, not a container.** `CalloutNode::createDOM()` returns
  `document.createElement('p')` and the toolbar button calls
  `$toggleSelectionBlockNodeType` — it converts a block in place, like Blockquote. It
  renders `<p class="callout {info|success|warning|danger}">` and cannot contain a table,
  a heading or a collapsible block.
- **`DetailsNode` is a real container** — it creates a `<details>` with a `<summary>` and
  holds block content.
- **Page navigation is built from headings** (`PageContent::getNavigation()` collects
  header elements from the rendered HTML), including any nested inside other blocks.

## Decision

### 1. Durable stat data lives in `lucos_worlds`; encounter state never does

The stat block belongs on the NPC's page, next to the lore — one lookup at the table
rather than two. Current hit points, conditions and initiative are **never** stored in
worlds, in any form, including as a page section that gets edited during play.

### 2. Stat blocks appear only in the DM-authored Book

Writing an NPC's AC into the player-side Book would record something the player character
cannot know, and it cannot be un-known once read. This is a content rule, but it is a real
one, and it is why the stat block convention attaches to a **chapter** in one Book rather
than being an estate-wide schema.

### 3. Reskinned published monsters get a reference line, not a copied stat block

Where an NPC is mechanically an existing published creature, the Stats section states the
source and the deltas — e.g. *"Bandit Captain (MM 2024) — cudgel instead of shortsword, no
ranged attack"*. Transcribing the full block would create a stale copy of data that is not
ours, maintained for no gain. A full block is written only for genuine homebrew.

### 4. Presentation uses BookStack's native content primitives only

**No custom HTML wrappers.** The structure is:

```html
<details>
  <summary>Bren Ashvale — Medium Humanoid · CR 4</summary>
  <p class="callout danger">AC 17 · Initiative +2 (12) · HP 65 (10d8+20) · Speed 30 ft</p>
  <table>…STR/DEX/CON/INT/WIS/CHA, rows Score / Mod / Save…</table>
  <p><strong>Skills</strong> …</p>
  …
</details>
```

- **`<details>` is the container.** It gives collapse, which is the real answer to
  "concise": the block reduces to a single summary line until it is needed. Deferral, not
  compression.
- **The `<summary>` carries identity** — name, size/type, CR. This is the line seen most
  of the time, so it matters more than anything inside.
- **The `danger` callout category is reserved for the AC/Initiative/HP/Speed line.** A
  callout can only be one paragraph, which turns out to be exactly the right size for the
  line that most deserves emphasis. It doubles as the CSS hook:
  `details:has(> p.callout.danger)` selects stat blocks specifically rather than every
  collapsible block on the page. The choice of `danger` over the other three categories is
  arbitrary; what matters is that it is reserved and consistent.
- **No headings inside the block.** Traits, Actions and Bonus Actions are bold paragraphs.
  Headings would put stat block internals into every NPC page's navigation sidebar, so the
  sidebar would stop describing the page. The block is read as a unit once opened; there is
  nothing to navigate to within it.

### 5. The template is a working baseline creature, not a form of placeholders

The chapter's default page template contains a coherent **all-10s, CR 0** creature — every
number derived from that baseline and internally consistent with every other. An NPC that
is never edited is still runnable, and every value left alone is still correct. Editing is
therefore always *tweaking deltas from a known-good state*, never *filling in blanks*.

The values are **derived from the rules rather than copied** from the published Commoner
entry, for the same reason as §3.

### 6. Spells: inline operational parameters, with the full text linked out

What is needed mid-combat is not the spell description but the operational line — level,
range, area, save, damage. That is a dozen words and it belongs inline in the NPC's
spellcasting list. Full rules text is **linked out** (D&D Beyond or the book): it is not
our content, the URLs are stable, and it serves prep rather than the table. Utility and
ritual spells get a bare name, since they would be looked up regardless.

The spellcasting line records **ability · save DC · attack bonus** explicitly, and is
present in the baseline template even for non-casters, because deleting a line costs
seconds where a missing one costs a rules lookup mid-session.

### 7. Authoring: three mechanisms, layered

| Mechanism | Holds | Applies |
|---|---|---|
| Chapter default page template | the skeleton every NPC has | automatically, to every new page |
| Saved page template | the full stat block | inserted only for homebrew NPCs |
| Page copy | a whole existing NPC | for variants of someone who exists |

A single template cannot serve both §3 and §5 — a skeleton sized for a full homebrew block
would have to be mostly deleted for every reskinned NPC, which is worse than no template —
and `HasDefaultTemplateInterface` allows only one default per chapter. Hence the split.

## Out of scope

**Combat tracking is deliberately not designed for.** It is being done on paper. That
decision is *reversible at zero cost* (a different method next session strands nothing),
whereas where stat blocks live is *sticky* (moving forty NPCs is a migration). Letting an
untested, reversible choice constrain an irreversible one would be backwards, so the two
were decided separately and on their own merits.

If a spreadsheet is ever used, it must live **outside** worlds, for the reason in §1.

## Consequences

### Positive

- One lookup at the table: lore and mechanics on the same page.
- The design survives BookStack's editor migration, because it uses only the primitives
  the new editor supports. Nothing here depends on the legacy editor's tolerance.
- No patch, no fork, no theme JavaScript — ADR-0001's no-fork thesis is untouched.
- Failure mode of the CSS is graceful: if a selector stops matching after an upgrade, the
  block renders as a stock collapsible section — legible, just plain.
- Chapter-level PDF export gives an offline path for the whole cast, which matters because
  BookStack does not work offline at all.

### Negative / honest trade-offs

- **BookStack computes nothing.** No CR calculation, no balance checking. Homebrew is
  built elsewhere and the result pasted in — a genuine two-step.
- **Manual transcription for homebrew.** No tool can push numbers into worlds.
- **Another soft convention.** Nothing enforces the reserved callout category, the absence
  of headings, or the block structure. The template does the work; a page authored without
  it will drift.
- **One callout category is spent** estate-wide within this system, and `danger` is a
  semantic stretch for a stat block.
- **The page-copy route brings the lore across too**, so variants involve deleting
  narrative. Acceptable friction; if it grates, the answer is deliberately-empty archetype
  pages, which is not built now.

## Open questions

Recorded as trigger conditions rather than deferred work — neither has a ticket, because
neither is a task until its trigger fires.

1. **Transclusion for homebrew spells.** BookStack supports page includes
   (`{{@<pageId>#<sectionId>}}`, resolved by `PageContent::render()`, nested up to 3
   levels). This was assessed and **not** adopted: published spells never change, so
   single-sourcing buys nothing that page copy does not already provide, while adding an
   opaque source and a silent coupling — restructure the source page and every NPC's
   included content renders blank with no warning. **The trigger is homebrewing spells**,
   at which point the content becomes ours, genuinely changes, and single-sourcing pays.
2. **A machine-readable stat block format.** Deliberately not designed for. The stored form
   is consistently-structured human-readable content, which is regular enough to parse
   later without being unreadable now. **The trigger is adopting an encounter tracker with
   an import path.**

## Alternatives considered

- **Screenshots of stat blocks from an external tool** — rejected. Invisible to BookStack's
  search, uneditable at the table, undiffable in page revisions, and stale without any
  signal. They also bloat the backed-up storage volume with unsearchable data.
- **An external tool as the home of homebrew NPCs** (e.g. D&D Beyond's homebrew builder) —
  rejected as the *home*, accepted as a *reference*. It splits an NPC's motivations from
  their AC across two systems, makes every table lookup two lookups, and puts authored
  content somewhere with limited export under a vendor that has changed licensing terms
  before. Linking *out* to published content is a different matter and is adopted in §6.
- **A `<div class="statblock">` styled by the theme** — rejected. Viable on the legacy
  TinyMCE editor, but the Lexical editor's closed node set gives it nothing to become.
  Building on the deprecated editor's tolerance is building on a known end date.
- **A callout as the stat block container** — not viable. Callouts render as `<p>`.
- **Spell pages, one per spell** — rejected for now; see Open questions.
- **Hover-preview tooltips on spell names** (as D&D Beyond does) — rejected. BookStack has
  no hover-preview component (the entire `resources/js/components` directory was
  enumerated). Building one would mean theme JavaScript plus the API — a feature build on
  an adopted app, which is what lucas42/lucos_worlds#4 rejected for the map tool on the
  same grounds. It is also a mouse-only affordance, useless on the tablet used for the
  player Book. `<details>` gives the same benefit for a click instead of a hover.

## Setup steps not captured in this repository

BookStack keeps settings in the database, not in git. These are not recoverable from a
fresh deploy and depend on backups:

- The **NPCs chapter's default page template**, and the template page itself.
- The **system default editor** (`app-editor`), set to the Lexical editor by lucas42 on
  2026-08-06. A restore that loses this setting would silently return new pages to the
  legacy TinyMCE editor.
