# -*- coding: utf-8 -*-
"""Build one .docx per part, carrying every worked example the source has."""
import os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from docx import Document
from docx.shared import Pt, Mm
from docx.oxml import OxmlElement

import docx_out as D
import harvest, scaffold, figs
from steps import build as build_steps
from latex2omml import LatexError, convert as to_omml

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

PART_TITLES = {
    '5': ('نەخشە سێگۆشەییەکان', 'Trigonometric Functions'),
    '6': ('لاری، ڕاستەهێڵ و داتاشراوە', 'Slope, Lines and the Derivative'),
    '7': ('ئاراستەبڕەکان', 'Vectors'),
}
# Numbering stays in western digits. The booklet is full of equations set
# in them, and the Arabic-Indic five is a ring that a student reads as a
# zero when it sits beside them.
def ku(n):
    return str(n)


def safe(tex):
    """Equations the converter cannot read are dropped rather than faked."""
    try:
        to_omml(tex)
        return True
    except (LatexError, Exception):
        return False


def cover(d, part):
    title, en = PART_TITLES[part]
    p = d.add_paragraph()
    D.run(p, f'بەشی {ku(part)}', D.KUD, 12, bold=True, color=D.KEY)
    D.rtl(p)
    D.spacing(p, 46, 2)
    t = d.add_paragraph()
    D.run(t, title, D.KUD, 25, bold=True, color=D.INK)
    D.rtl(t)
    D.spacing(t, 0, 2)
    e = d.add_paragraph()
    D.run(e, en, D.EN_SANS, 13, color=D.SOFT)
    D.spacing(e, 0, 8)
    s = d.add_paragraph()
    D.run(s, 'مەلزەمەی ڕوونکراوە — یاساکان، نموونەی شیکراو، و تێبینی',
          D.KU, 11.5, color=D.SOFT)
    D.rtl(s)
    D.borders(s._p.get_or_add_pPr(), bottom=(8, D.HEX_KEY))
    D.spacing(s, 0, 14)
    return title


def topic(d, no, title, en, sc, exs):
    D.heading_chapter(d, f'{ku(no)}', title, en)

    if sc.get('concept'):
        D.concept(d, sc['concept'])
    if sc.get('bullets'):
        D.bullets(d, sc['bullets'])
    for law_title, lines, note in sc.get('laws', []):
        keep = [l for l in lines if safe(l)]
        if keep:
            D.law(d, law_title, keep, note=note)

    if exs:
        D.heading_section(d, 'نموونەی شیکراو')
        # The task is the same for every example of a topic, so it is said
        # once here rather than repeated over each one.
        if sc.get('ask'):
            D.para(d, sc['ask'], size=10.5, color=D.SOFT)
    n = 0
    for ex in exs:
        eqs = [e for e in ex['equations'] if safe(e)]
        if len(eqs) < 2:
            continue
        n += 1
        fig = None
        if ex.get('figure'):
            fig = os.path.join(ROOT, 'assets', 'img', ex['figure'])
            if not os.path.exists(fig):
                fig = None
        # Only the question re-typed from the source; where the source gave
        # none, the first step already states what was given.
        D.example(d, str(n), ex.get('prompt') or (), figure=fig,
                  steps=build_steps(eqs, law_hint=sc.get('hint')),
                  answer=None)

    if sc.get('mistakes'):
        D.mistakes(d, sc['mistakes'])
    if sc.get('tips'):
        D.tips(d, sc['tips'])
    return n


def main():
    blocks = harvest.load()
    rngs = harvest.ranges(blocks)
    figs.build()

    for part in ('5', '6', '7'):
        d = Document()
        d.styles['Normal'].font.size = Pt(11)
        sec = d.sections[0]
        sec.page_width, sec.page_height = Mm(210), Mm(297)
        sec.top_margin, sec.bottom_margin = Mm(18), Mm(16)
        sec.left_margin, sec.right_margin = Mm(16), Mm(16)
        sec._sectPr.append(OxmlElement('w:bidi'))

        cover(d, part)
        mine = [r for r in rngs if r[0] == part]
        for i, (_, s, e, title, en, hint) in enumerate(mine, 1):
            sc = dict(scaffold.S.get(title, {}))
            sc['hint'] = hint
            sc['ask'] = scaffold.ASK.get(title)
            exs = harvest.examples(blocks, s, e)
            got = topic(d, f'{part}-{i}', title, en, sc, exs)
            print(f'  بەشی {part} · {title:26s} {got:3d} نموونە')

        out = os.path.join(ROOT, 'build', f'Bircari-Bashi-{part}.docx')
        d.save(out)
        print(f'  → {out}  {round(os.path.getsize(out)/1e6,2)} MB\n')


if __name__ == '__main__':
    main()
