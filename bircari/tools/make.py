# -*- coding: utf-8 -*-
"""Assemble the authored sections into one HTML file."""
import os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import book
from content import SECTIONS

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
out = os.path.join(ROOT, 'build', 'book.html')
open(out, 'w', encoding='utf8').write(
    book.page('\n'.join(SECTIONS), 'مەلزەمەی بیرکاری — ڕێژە سێگۆشەییەکان'))
print('wrote', out, os.path.getsize(out), 'bytes')
