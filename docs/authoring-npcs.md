# Authoring NPCs in lucos_worlds

How to write an NPC page with a stat block. The *decisions* behind all of this — and the
reasoning for each — are in [ADR-0005](adr/0005-npc-stat-blocks.md); this document is the
procedure.

Applies to the **DM-authored Book only**. Stat blocks are deliberately absent from the
player-side Book (ADR-0005 §2).

---

## The three authoring routes

| Situation | Use |
|---|---|
| A brand-new NPC | The NPCs chapter's **default page template** — applied automatically |
| An NPC who needs full homebrew stats | Insert the saved **Stat Block** template |
| An NPC much like one who already exists | **Copy the existing page** |

Page copy is the answer to "same stats with minor tweaks". It's better than copy/pasting
text: the structure comes across exactly, a table can't be half-pasted and mangled, and
you only edit the deltas. It does bring the lore across too, so there's some deleting.

---

## The page skeleton

The chapter default template. Headings are a starting point — adjust to taste.

```
One sentence: who this person is.

## Description
    What players actually perceive — appearance, manner, voice.

## Role & Motivation
    What they want. What they're hiding.

## Connections
    Links to other NPCs and places.

## Stats
    (reference line, or the stat block — see below)
```

**The first line has no heading, deliberately.** `Page::getExcerpt()` is patched in this
repo (lucas42/lucos_worlds#52, lucas42/lucos_worlds#53) to stop at the first newline, so
that opening sentence becomes the page's description and `og:description`. Start with a
heading and every NPC gets a useless description.

### For a reskinned published monster

Most NPCs need no stat block at all — just the source and the deltas:

```
## Stats
Bandit Captain (MM 2024) — cudgel instead of shortsword, no ranged attack.
```

Complete, accurate, and self-maintaining. Don't transcribe a published block
(ADR-0005 §3).

---

## The stat block

Only for genuine homebrew. The baseline template is a **working all-10s CR 0 creature**,
not a form of blanks — so an NPC you never finish editing is still runnable, and any value
you leave alone is still correct.

```
▸ [Name] — Medium Humanoid · CR 0            ← the <summary> line

  AC 10 · Initiative +0 (10) · HP 4 (1d8) · Speed 30 ft    ← danger callout

  ┌───────┬─────┬─────┬─────┬─────┬─────┬─────┐
  │       │ STR │ DEX │ CON │ INT │ WIS │ CHA │
  │ Score │ 10  │ 10  │ 10  │ 10  │ 10  │ 10  │
  │ Mod   │ +0  │ +0  │ +0  │ +0  │ +0  │ +0  │
  │ Save  │ +0  │ +0  │ +0  │ +0  │ +0  │ +0  │
  └───────┴─────┴─────┴─────┴─────┴─────┴─────┘

  Skills       —
  Senses       passive Perception 10
  Languages    Common
  Gear         —
  Spellcasting —  · DC 10 · +2 to hit

  Actions
  Club — +2 to hit, reach 5 ft, 2 (1d4) bludgeoning
```

Every number is consistent with every other, so when you change an ability score you can
see which values move. See [Derivations](#derivations) below.

### Two rules that make it fast to read

**Only list skills the NPC is proficient in, with the total already computed.** Everything
else falls back to the `Mod` row. That row isn't decoration — it's the default for every
check you didn't anticipate, which is most of them. So a History check is just INT's
modifier; no arithmetic, no decision, no proficiency bonus to remember.

The same applies to saves, and it's why `passive Perception` is written out: it's the one
derived number you'd otherwise compute mid-scene.

**Pre-compute every exception; let everything else fall back to the ability modifier.**
That single rule decides what belongs on a stat block and what doesn't.

---

## Building it in the editor

Uses only BookStack's native blocks — no hand-written HTML. That's deliberate: the newer
editor parses content into a fixed set of node types and a custom `<div>` would be
discarded on save (ADR-0005 §4).

1. **Insert collapsible block** — in the insert group of the toolbar, near image, media,
   horizontal line and code block.
2. With it selected, hit **Edit label** in its context toolbar. That's the summary text
   shown when collapsed — put the name, size/type and CR there. It's the line you see 90%
   of the time, so it matters more than anything inside.
3. Click inside the block and type the `AC … Initiative … HP … Speed …` line as an ordinary
   paragraph.
4. With the cursor still in that line, open the **format dropdown** (the one offering
   Paragraph / Header / Blockquote) and choose **Danger**. It converts the paragraph in
   place. The dropdown shows live previews of the four callout styles.
5. **Table** — the table dropdown gives a grid picker. 7 columns × 4 rows, then use
   **toggle row headers** on the top row.
6. Skills / Senses / Languages / Gear / Spellcasting as ordinary paragraphs, label in bold.
7. **Traits / Actions / Bonus Actions as bold paragraphs — not headings.** See below.

Other collapsible-block controls: **Toggle open/closed** (leave it closed) and **Unwrap**
(dissolves the block, keeping its contents).

### Conventions you can't see from the markup

- **The `danger` callout category is reserved for the AC/HP line.** The theme CSS selects
  stat blocks with `details:has(> p.callout.danger)`, so using `danger` for anything else
  in this Book will pick up stat block styling. Info, Success and Warning are free.
- **No headings inside the stat block.** `PageContent::getNavigation()` builds the page's
  sidebar navigation from heading elements anywhere in the rendered HTML, so headings here
  would fill every NPC's navigation with "Traits", "Actions", "Bonus Actions". Bold
  paragraphs give the same visual weight and keep the sidebar about the page.

### Saving it for reuse

Once one NPC looks right, save the page as a template from the editor sidebar's Templates
panel, then set it as the NPCs chapter's default.

**Do this after you've run a session, not before.** You'll want to move things around once
you've read a stat block under pressure, and it's far easier to fix the template than to
fix twelve pages made from a template you didn't like.

> Both the template and the chapter default are **database settings, not repository
> content** — they aren't recoverable from a fresh deploy, only from backups.

---

## Spells

Put the **operational parameters inline**, and link the name out for the full text:

```
Spellcasting  CHA · DC 15 · +7 to hit
• Fireball (3rd) — 150 ft, 20-ft radius, DEX DC 15, 8d6 fire
• Counterspell (3rd) — reaction, 60 ft
• Detect Magic (ritual)
```

Mid-combat you need the numbers, not the description — that's a dozen words and it saves
the lookup entirely. Utility and ritual spells get a bare name, because you'd look those up
regardless and inlining them turns the block back into a wall of text.

Link the spell **name** to D&D Beyond (or cite the book) for the full rules text. That
serves prep and the occasional edge case; it's not something you want to be clicking
through while four people wait.

Don't copy spell descriptions into worlds. If you start **homebrewing** spells, that
changes — see ADR-0005's Open questions.

---

## Derivations

The numbers you'd otherwise have to remember.

| Value | Formula | All-10s baseline |
|---|---|---|
| Ability modifier | `(score − 10) / 2`, rounded down | `+0` |
| Armour class | `10 + DEX mod` (unarmoured) | `10` |
| Initiative | `DEX mod`; the parenthetical is `10 + DEX mod` | `+0 (10)` |
| Passive Perception | `10 + Perception modifier` | `10` |
| Attack bonus | `proficiency + relevant ability mod` | `+2` |
| Spell save DC | `8 + proficiency + spellcasting ability mod` | `10` |
| Spell attack bonus | `proficiency + spellcasting ability mod`, i.e. **`save DC − 8`** | `+2` |

**Proficiency bonus by CR:** CR 0–4 → `+2`; 5–8 → `+3`; 9–12 → `+4`; 13–16 → `+5`;
17–20 → `+6`.

Three things worth knowing:

- **Spell attack bonus is always `save DC − 8`.** Both derive from
  `proficiency + ability modifier`; the DC just adds 8. If you have one, you have the other.
- **NPCs don't have classes.** A published stat block *states* its save DC and attack bonus
  rather than deriving them from a class. So there's no need to remember which class uses
  which spellcasting ability — pick whatever fits the fiction and set the DC to suit the CR.
  (For when a player asks: INT for wizards and artificers, WIS for clerics, druids and
  rangers, CHA for bards, sorcerers, warlocks and paladins.)
- **Concentration saves are always CON**, never the spellcasting ability. This one catches
  people out, because it feels like it should be the casting stat.

> These are derived from the general rules rather than checked against the 2024 Monster
> Manual's own entries. If a published block differs in a detail, trust the book.
