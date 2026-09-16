# مەلزەمەی بیرکاری — بەشەکانی ٥، ٦ و ٧

Rebuilds three maths booklets from their source .docx files as academic texts:
trigonometry, the derivative, and vectors.

    tools/make_all.py  →  build/Bircari-Bashi-5.docx   72 pages
                          build/Bircari-Bashi-6.docx   29 pages
                          build/Bircari-Bashi-7.docx   83 pages

`src/`, `src6/` and `src7/` hold the three sources; `tools/harvest.py` reads
every worked solution out of them, `tools/steps.py` gives each step of each
solution its reason, and `tools/scaffold.py` carries the teaching written
around them - the concept, the laws, the common mistakes and the shortcuts.

## Every worked example, not a selection

The three sources hold 297 worked solutions between them. They are laid out
as table cells and as runs of consecutive equations, so `harvest.py` collects
both shapes, and all 297 are in the booklets. A cell that holds only a
definition - "sin", "tan", or the letters of a general rule - reads back like
a two-step solution, so `harvest.worked()` requires a chain to show some
working before it counts as an example.

## The questions are text

The original typist pasted every question as a picture of itself. 175 of them
were read off the image and written out in `tools/prompts.py`, Kurdish as
Kurdish and the maths as LaTeX, so the questions can be searched, resized and
corrected like the rest of the booklet. `harvest.diagrams()` then knows those
pictures are questions, not figures, and leaves them out of the page.

## Pipeline

    src/document.xml
      → tools/alik.py      Ali-K legacy encoding → Unicode Kurdish
      → tools/omml.py      Word equations (OMML) → LaTeX
      → tools/extract.py   → build/source.json, build/readable.txt
      → tools/content.py   the authored booklet
      → tools/book.py      content → designed HTML
      → render_math.js     KaTeX pre-pass
      → Chromium           → build/Bircari-Trigonometry.pdf

## Why the equations are converted, not copied

The source carries 547 equations as real Word maths. Read as plain text a
fraction collapses into its numerator followed by its denominator - "sin A =
بەرامبەرژێ" - so `omml.py` walks them as a tree and emits LaTeX instead.

## Kurdish stays outside the equations

The source wrote its ratios with Kurdish words as operands. That reads poorly,
breaks on copy-paste, and no maths font carries the letterforms. Each ratio is
therefore defined once in a styled box, and the working itself is pure
notation:

    \sin A = \frac{\mathrm{opp}}{\mathrm{hyp}}     opp = بەرامبەر …

This is also what lets an equation be pasted into Word or InDesign without
its symbols reordering.

## Output

`build/Bircari-Trigonometry.docx` — editable Word. Its 161 equations are real
Word maths built by `tools/latex2omml.py`, not pictures, so the reader can
click into one and change it.

`latex2omml.py` supports only the constructs the booklet actually writes and
raises on anything else, so an equation cannot render silently wrong.

## Figures

Several source pictures bundle a question banner above the triangle. The
prompt is re-typed in the booklet, so `tools/figs.py` trims the banner rather
than printing it twice in two different styles. A few examples in the source
carried a diagram belonging to a different problem; those were replaced.

## Build

    cd tools && python3 extract.py && python3 make_all.py

`write.py` builds the older single-part booklet; `make_all.py` builds all
three.
