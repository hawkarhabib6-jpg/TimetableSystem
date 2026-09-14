# Sunrise 12 — Companion (مەلزەمە) redesign

Rebuilds the Grade 12 English booklet with a single, consistent design system.

## Pipeline

    src/malzama.docx
      → tools/alik.py      Ali-K legacy encoding → Unicode Kurdish
      → tools/extract.py   classify every paragraph into a semantic role
      → tools/render.py    roles → designed HTML components
      → Chromium           HTML → print-ready A4 PDF

## Why the conversion step exists

The source stores Kurdish as Arabic codepoints that only read correctly with
the `Ali_K_*` fonts installed (`ثةروةردة` is really `پەروەردە`). Everything is
converted to Unicode once, up front, so the text is searchable, spell-checkable
and renders on any machine.

## Design system

`assets/style.css` styles by meaning rather than by the ad-hoc colours the
original used:

| Role | Component |
|---|---|
| Kurdish translation | violet rail attached to its English line |
| Question + options | numbered card, lettered option grid |
| Teacher's-book note | amber callout with `T.B` badge |
| Example | sunrise-tinted strip |
| Grammar pattern | chip chain (`Subject + [Must/Have to/…] + v(base)`) |
| Exercise instruction | orange prompt rule |
| Question bank | navy banner |

The grammar patterns are the one place the layout improves on the source: the
original scattered each alternative across separate one-line paragraphs, which
`tools/render.py` regroups into a single diagram.

## Mixed Kurdish and English

Brackets and punctuation carry no script of their own, so `tools/mixed.py`
assigns each one deliberately - an opening bracket goes with what it opens, a
closing bracket with what it closes - and both outputs then set a font per run.
`(base)` inside a Kurdish sentence keeps English brackets in the English face;
`(کۆما)` keeps Kurdish brackets in the Kurdish face.

Brackets also arrive damaged. The booklet opened an option list with "(a. …"
in one paragraph and closed it with "… d. X)" several paragraphs later, so
gathering those options into one card leaves the halves stranded, often beside
an exam year that lost a bracket of its own. `tools/textfix.py` rebuilds the
year's pair - from either side, "… correct) (2021" or "… b) 2018" - and drops
whatever parenthesis is still unmatched. Across the book that is 368 damaged
blocks, all balanced.

Two further rules hold in both outputs:

- A heading travels with the block beneath it (`.keep` in print, `w:keepNext`
  in Word), so a new topic never starts on the last line of a page.
- A paragraph's translation is exactly the Kurdish that follows it. Taking only
  the first block would drop the rest of the same translation; running past it
  would absorb the next paragraph's.

## Running head and foot

Every text page carries the book's identity at the head and the schools at the
foot:

    SUNRISE 12                                         Falah H. Younis
    ───────────────────────────────────────────────────────────────────
    Ibrahim Ahmad preparatory school   پەیمانگای ژیر   Shahid Aram preparatory school

Chromium applies its header and footer templates to every page alike, so
`tools/topdf.py` prints twice and takes the full-bleed pages from the clean
pass, leaving the artwork unmarked; it finds those pages by sampling the
saturated band each one ends on. Word gets real header and footer parts, set on
the body sections only - with the built-in Header and Footer styles' own tab
stops cleared, or the right-hand text stops short of the margin.

Since the page head now carries the title, the per-unit marker in the text flow
is a chip rather than a second full-width bar.

## Output

Both deliverables come from the same source and the same stylesheet:

| File | What it is |
|---|---|
| `build/Sunrise12-Companion.pdf` | 393 pages, A4, print-ready |
| `build/Sunrise12-Companion.docx` | the same book as editable Word content |

The Word file carries real paragraphs, tables and pictures rather than a
picture of each page, so it stays editable. Only the full-page artwork (cover,
part dividers, unit openers) is placed as an image, rendered from the same HTML
the PDF uses so the two stay identical.

`fonts/*.ttf` are the Noto Naskh Arabic and Noto Kufi Arabic faces the design
uses. Install them before opening the .docx or Word will substitute.

## Build

    tools/build.sh

## Structure

21 units in three parts, segmented from the unit headings (which the source
kept inside drawing shapes, not in the text flow):

| Part | Units |
|---|---|
| 1 · Grammar | 1-7 |
| 2 · Reading & Apsod | 1-3, 5-8 |
| 3 · Activities | 1-3, 5-8 |
