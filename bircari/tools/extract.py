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

# One unpacked .docx per part. Images are namespaced by part, since the
# three documents all number theirs from image1.png.
PARTS = {
    '5': 'src',
    '6': 'src6',
    '7': 'src7',
}


def rel_map(rels):
    m = {}
    if os.path.exists(rels):
        for r in etree.parse(rels).getroot():
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


def read_part(part, folder):
    base = os.path.join(ROOT, folder)
    doc = os.path.join(base, 'word', 'document.xml')
    if not os.path.exists(doc):                       # part 5 was unpacked flat
        doc = os.path.join(base, 'document.xml')
        rels = os.path.join(base, 'document.xml.rels')
    else:
        rels = os.path.join(base, 'word', '_rels', 'document.xml.rels')
    if not os.path.exists(doc):
        return []
    rmap = rel_map(rels)
    body = etree.parse(doc).getroot().find(W + 'body')
    blocks = []
    for el in body.iterchildren():
        if el.tag == W + 'p':
            parts, imgs = inline(el, rmap)
            if parts or imgs:
                blocks.append({'kind': 'p', 'part': part, 'parts': parts,
                               'images': [f'{part}/{i}' for i in imgs],
                               'flat': flat(parts)})
        elif el.tag == W + 'tbl':
            rows = []
            for tr in el.findall(W + 'tr'):
                row = []
                for tc in tr.findall(W + 'tc'):
                    parts, imgs = inline(tc, rmap)
                    row.append({'parts': parts,
                                'images': [f'{part}/{i}' for i in imgs],
                                'flat': flat(parts)})
                rows.append(row)
            blocks.append({'kind': 'tbl', 'part': part, 'rows': rows})
    return blocks


def main():
    blocks = []
    for part, folder in sorted(PARTS.items()):
        got = read_part(part, folder)
        print(f'  part {part}: {len(got)} blocks')
        blocks.extend(got)

    out = os.path.join(ROOT, 'build', 'source.json')
    os.makedirs(os.path.dirname(out), exist_ok=True)
    json.dump(blocks, open(out, 'w', encoding='utf8'),
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
