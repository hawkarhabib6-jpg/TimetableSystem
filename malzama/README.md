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

## Build

    cd tools && python3 extract.py && python3 render.py 8 165 one
    chromium --headless --no-pdf-header-footer \
      --print-to-pdf=build/unit-one.pdf build/unit-one.html
