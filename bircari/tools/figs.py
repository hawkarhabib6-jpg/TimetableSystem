# -*- coding: utf-8 -*-
"""Crop the diagrams out of images that also carry the old question text.

Several source pictures bundle a question banner above the triangle. The
prompt is re-typed in the booklet, so the banner is trimmed away rather
than printed twice in two different styles.
"""
import os
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'assets', 'img')
OUT = os.path.join(ROOT, 'build', 'fig')

# name -> (left, top, right, bottom) as fractions of the image
CROPS = {
    'image21.png': (0.00, 0.00, 0.22, 1.00),   # diagram at the far left
    'image104.png': (0.00, 0.22, 1.00, 1.00),  # banner across the top
    'image105.png': (0.00, 0.22, 1.00, 1.00),
}


def build():
    os.makedirs(OUT, exist_ok=True)
    made = {}
    for name, (l, t, r, b) in CROPS.items():
        p = os.path.join(SRC, name)
        if not os.path.exists(p):
            continue
        im = Image.open(p).convert('RGB')
        w, h = im.size
        box = (int(w * l), int(h * t), int(w * r), int(h * b))
        out = os.path.join(OUT, name)
        im.crop(box).save(out)
        made[name] = out
    return made


def path(name):
    """The cropped copy when there is one, else the original."""
    c = os.path.join(OUT, name)
    if os.path.exists(c):
        return c
    p = os.path.join(SRC, name)
    return p if os.path.exists(p) else None


if __name__ == '__main__':
    print('cropped:', list(build()))
