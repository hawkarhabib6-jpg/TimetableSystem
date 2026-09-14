# -*- coding: utf-8 -*-
"""Editorial changes to the source text, made at the owner's request.

Everything else in the pipeline preserves the author's words exactly; this
file is the one place where they are deliberately altered, so that any such
change stays visible and reversible.
"""

EDITS = [
    # Requested by the book's owner: drop the guarantee of a hundred marks.
    # "بۆیە" (therefore) leaned on the clause being removed, so it goes with
    # it and a comma takes its place; every other word is the author's.
    (
        'خوێندکاری بەڕێز ئەم مەلزەمەیە گرەنتی سەد نمرەت بۆ دەکات, '
        'بۆیە بەبێ دوودڵی و بەمتمانەیەکی تەواوەوە دەتوانی  سودمەندبی لێی.',
        'خوێندکاری بەڕێز، ئەم مەلزەمەیە بەبێ دوودڵی و بەمتمانەیەکی '
        'تەواوەوە دەتوانی سودمەندبی لێی.'
    ),
]


def apply(blocks):
    """Apply each edit to whichever block carries it. Returns the count."""
    done = 0
    for old, new in EDITS:
        key = ''.join(old.split())
        for b in blocks:
            t = b.get('text')
            if t and ''.join(t.split()) == key:
                b['text'] = new
                done += 1
                break
    return done
