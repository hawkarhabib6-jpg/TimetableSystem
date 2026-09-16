# مەلزەمەی بیرکاری — ڕێژە سێگۆشەییەکان

Rebuilds the trigonometry booklet from the source .docx as an academic text.

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

    cd tools && python3 write.py
