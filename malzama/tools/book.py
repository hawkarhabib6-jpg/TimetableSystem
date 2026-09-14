# -*- coding: utf-8 -*-
"""Segment the blocks into parts/units and render the whole booklet."""
import json, os, re, sys
import render
from render import (render_range, page, esc, WORD2NUM, UNIT_TITLES)

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DOC = os.path.join(ROOT, 'build', 'doc.json')

PART_TITLES = [
    ('Grammar', 'ڕێزمان',
     'Every grammar point of Sunrise 12, unit by unit, with notes and practice.'),
    ('Reading &amp; Apsod', 'ڕیدینگ و ئێپسەود',
     'The passages and listening texts, translated and answered.'),
    ('Activities', 'چالاکییەکان',
     'Student-book and activity-book tasks, worked through in full.'),
]


def unit_no(text):
    m = re.match(r'\s*unit\s+(\w+)', text, re.I)
    if not m:
        return None
    w = m.group(1).lower()
    if w.isdigit():
        return int(w)
    return WORD2NUM.get(w)


def segment(blocks):
    """-> [(part_index, unit_no, start, end), ...]"""
    marks = []
    for i, b in enumerate(blocks):
        if b['kind'] != 'unit':
            continue
        n = unit_no(b['text'])
        if n is None:
            continue
        # The same heading appears as both a shape and a paragraph.
        if marks and marks[-1][0] == n and i - marks[-1][1] < 4:
            continue
        marks.append((n, i))

    part, out = 0, []
    for idx, (n, i) in enumerate(marks):
        if idx and n <= marks[idx - 1][0]:
            part = min(part + 1, len(PART_TITLES) - 1)
        end = marks[idx + 1][1] if idx + 1 < len(marks) else len(blocks)
        out.append((part, n, i, end))
    return out


def part_divider(pi):
    en, ku, blurb = PART_TITLES[pi]
    return f'''
<section class="divider">
  <div class="dnum">Part {pi + 1}</div>
  <h1>{en}</h1>
  <div class="dku">{esc(ku)}</div>
  <p class="dblurb">{blurb}</p>
</section>'''


def front_matter(units):
    rows = ''
    for pi, (en, ku, _) in enumerate(PART_TITLES):
        items = ''.join(
            f'<li><span class="n">{n}</span>'
            f'<span class="t">{esc(UNIT_TITLES.get(NUM2WORD.get(n, ""), ("Unit " + str(n), ""))[0])}</span></li>'
            for p, n, s, e in units if p == pi)
        rows += (f'<div class="tocpart"><h3>Part {pi + 1} · {en}'
                 f'<span class="ku">{esc(ku)}</span></h3><ul>{items}</ul></div>')
    return f'''
<section class="cover">
  <svg class="rays" viewBox="0 0 100 100" preserveAspectRatio="none">{render.RAYS}</svg>
  <div class="ckicker">Kurdistan Region · Grade 12</div>
  <h1>SUNRISE<span>12</span></h1>
  <div class="csub">Companion &amp; Study Guide</div>
  <div class="cku">مەلزەمەی تەواوی بابەتی ئینگلیزی — پۆلی دوازدەی ئامادەیی</div>
  <div class="cfoot">Grammar · Reading · Apsod · Activities · Question Bank</div>
</section>

<section class="toc-page">
  <div class="runhead"><span class="u">Contents</span><span class="t">ناوەڕۆک</span></div>
  {rows}
</section>'''


NUM2WORD = {v: k for k, v in WORD2NUM.items()}


def main():
    blocks = json.load(open(DOC, encoding='utf8'))
    units = segment(blocks)
    print(f'{len(units)} units across {len({u[0] for u in units})} parts')

    body = [front_matter(units)]
    last_part = None
    for pi, n, s, e in units:
        if pi != last_part:
            body.append(part_divider(pi))
            last_part = pi
        word = NUM2WORD.get(n, 'one')
        body.append(render_range(blocks, s, e, word, n))
        print(f'  part {pi + 1}  unit {n:2d}  blocks {s}-{e}')

    out = os.path.join(ROOT, 'build', 'book.html')
    open(out, 'w', encoding='utf8').write(
        page('\n'.join(body), 'Sunrise 12 — Companion'))
    print('wrote', out, os.path.getsize(out), 'bytes')


if __name__ == '__main__':
    main()
