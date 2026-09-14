# -*- coding: utf-8 -*-
"""Split mixed Kurdish/English text into runs that each take one font.

Brackets and punctuation carry no script of their own, so they inherit the
wrong font unless they are assigned deliberately: an opening bracket belongs
to what it opens, a closing bracket to what it closes, and everything else
stays with the text it follows.
"""
import re

KU_RANGES = ((0x0600, 0x06FF), (0x0750, 0x077F), (0x08A0, 0x08FF),
             (0xFB50, 0xFDFF), (0xFE70, 0xFEFF))
OPEN, CLOSE = '([{<«“‘', ')]}>»”’'


def script_of_char(ch):
    o = ord(ch)
    for lo, hi in KU_RANGES:
        if lo <= o <= hi:
            return 'ku'
    if ch.isalpha() or ch.isdigit():
        return 'en'
    return None


def segments(text):
    """-> [(script, text), ...] with 'en' and 'ku' runs, in logical order."""
    if not text:
        return []
    marks = [script_of_char(c) for c in text]

    # Assign each neutral character to the side it belongs with.
    for i, m in enumerate(marks):
        if m is not None:
            continue
        prev = next((marks[j] for j in range(i - 1, -1, -1) if marks[j]), None)
        nxt = next((marks[j] for j in range(i + 1, len(marks)) if marks[j]), None)
        ch = text[i]
        if ch in OPEN:
            marks[i] = nxt or prev
        elif ch in CLOSE:
            marks[i] = prev or nxt
        elif ch.isspace():
            marks[i] = prev or nxt
        else:
            marks[i] = prev or nxt

    out, cur, buf = [], marks[0] or 'en', []
    for ch, m in zip(text, marks):
        m = m or cur
        if m != cur and buf:
            out.append((cur, ''.join(buf)))
            buf = []
        cur = m
        buf.append(ch)
    if buf:
        out.append((cur, ''.join(buf)))

    # A run of pure whitespace takes the font of whatever follows it.
    merged = []
    for sc, t in out:
        if merged and (not t.strip() or merged[-1][0] == sc):
            merged[-1] = (merged[-1][0], merged[-1][1] + t)
        else:
            merged.append((sc, t))
    return merged


if __name__ == '__main__':
    for s in ['جۆری دووەم: ڕستەیەکی ڕێنماییە بە (base) دەست پێدەکات.',
              'لەدوای هەموویانەوە فاریزە (کۆما) دادەنێین تەنها then نەبێ.',
              'A series of instructions   زنجیرەیەک ڕێنمایی',
              'ئەتوانین جێگۆڕکێ بکەین تەنها (first, finally) نەبێ.',
              'واتا ڕستەکە بە base دەست پێ بکات.']:
        print(' | '.join(f'{sc}:{t!r}' for sc, t in segments(s)))
