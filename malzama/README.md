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
