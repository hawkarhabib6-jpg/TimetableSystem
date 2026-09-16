# -*- coding: utf-8 -*-
"""Read the maths source into a structured, readable dump.

Equations become LaTeX rather than flattened text, and the Kurdish - which
the source stores in the Ali-K legacy encoding, inside the equations as well
as around them - becomes Unicode.
"""
import json, os, sys
from lxml import etree
from alik import convert
from omml import to_latex

W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
M = '{http://schemas.openxmlformats.org/officeDocument/2006/math}'
R = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SRC = os.path.join(ROOT, 'src', 'document.xml')
RELS = os.path.join(ROOT, 'src', 'document.xml.rels')
OUT = os.path.join(ROOT, 'build', 'source.json')


def rel_map():
    m = {}
    if os.path.exists(RELS):
        for r in etree.parse(RELS).getroot():
            if 'image' in (r.get('Type') or ''):
                m[r.get('Id')] = os.path.basename(r.get('Target'))
    return m


def inline(el, rels):
    """Text, equations and pictures of one paragraph or cell, in order."""
    parts, imgs = [], []
    for node in el.iter():
        if not isinstance(node.tag, str):
            continue
        if node.tag == M + 'oMath':
            tex = to_latex(node, convert)
            if tex:
                parts.append({'math': tex})
        elif node.tag == W + 't':
            # Text inside an equation is already handled above.
            if any(a.tag == M + 'oMath' for a in node.iterancestors()):
                continue
            s = convert(node.text or '')
            if s.strip():
                parts.append({'text': s})
        elif node.tag.endswith('}blip'):
            rid = node.get(R + 'embed') or node.get(R + 'link')
            if rid in rels:
                imgs.append(rels[rid])
    merged = []
    for p in parts:
        if merged and 'text' in p and 'text' in merged[-1]:
            merged[-1]['text'] += p['text']
        else:
            merged.append(dict(p))
    return merged, imgs


def flat(parts):
    return ''.join(p.get('text') or ('$' + p['math'] + '$') for p in parts)


def main():
    rels = rel_map()
    body = etree.parse(SRC).getroot().find(W + 'body')
    blocks = []
    for el in body.iterchildren():
        if el.tag == W + 'p':
            parts, imgs = inline(el, rels)
            if parts or imgs:
                blocks.append({'kind': 'p', 'parts': parts, 'images': imgs,
                               'flat': flat(parts)})
        elif el.tag == W + 'tbl':
            rows = []
            for tr in el.findall(W + 'tr'):
                row = []
                for tc in tr.findall(W + 'tc'):
                    parts, imgs = inline(tc, rels)
                    row.append({'parts': parts, 'images': imgs,
                                'flat': flat(parts)})
                rows.append(row)
            blocks.append({'kind': 'tbl', 'rows': rows})

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(blocks, open(OUT, 'w', encoding='utf8'),
              ensure_ascii=False, indent=1)

    eq = sum(1 for b in blocks if b['kind'] == 'p'
             for p in b['parts'] if 'math' in p)
    eq += sum(1 for b in blocks if b['kind'] == 'tbl'
              for r in b['rows'] for c in r for p in c['parts'] if 'math' in p)
    img = sum(len(b.get('images', [])) for b in blocks if b['kind'] == 'p')
    img += sum(len(c['images']) for b in blocks if b['kind'] == 'tbl'
               for r in b['rows'] for c in r)
    print(f'blocks {len(blocks)}  equations {eq}  images {img}')


if __name__ == '__main__':
    main()
