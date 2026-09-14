# -*- coding: utf-8 -*-
"""Repair bracket damage the source carried.

The booklet opened an option list with "(a. …" in one paragraph and closed it
with "… d. X)" several paragraphs later. Once those options are gathered into
one card the stray halves are left behind, so they are removed here, and the
exam years they sat next to are put back inside a proper pair.
"""
import re

YEAR = r'(?:19|20)\d{2}(?:\s*[-–/]\s*\d{1,4})*'

# ") 2005)" and "2005)" standing alone -> "(2005)"
_BROKEN_YEAR = re.compile(r'\)\s*(' + YEAR + r'[^()]{0,18}?)\s*\)')
_SPACED_YEAR = re.compile(r'\(\s+(' + YEAR + r')\s*\)')

OPEN, CLOSE = '(', ')'


_TRAILING_YEAR = re.compile(r'(' + YEAR + r'[^()]{0,18})\s*$')
_LEADING_YEAR = re.compile(r'^\s*(' + YEAR + r')')


def _orphans(text):
    """Indices of parentheses with no partner."""
    stack, out = [], set()
    for i, ch in enumerate(text):
        if ch == OPEN:
            stack.append(i)
        elif ch == CLOSE:
            if stack:
                stack.pop()
            else:
                out.add(i)
    out.update(stack)
    return out


def _adopt_years(text):
    """Rebuild the bracket pair around a year that lost one half.

    An exam year trailing an option list lost a bracket on one side or the
    other - "… correct) (2021" before it, "… b) 2018" after it - so the
    orphan is turned back into the year's own pair rather than deleted.
    """
    for i in sorted(o for o in _orphans(text) if text[o] == CLOSE):
        before = _TRAILING_YEAR.search(text[:i])
        if before:
            return _adopt_years(text[:before.start()] + OPEN
                                + text[before.start():])
        after = _LEADING_YEAR.match(text[i + 1:])
        if after:
            head, tail = text[:i], text[i + 1:]
            cut = after.end()
            return _adopt_years(head + ' ' + OPEN + tail[:cut].strip()
                                + CLOSE + tail[cut:])
    return text


def _strip_orphans(text):
    """Drop every parenthesis that has no partner, keeping matched pairs."""
    orphans = _orphans(text)
    if not orphans:
        return text
    return ''.join(c for i, c in enumerate(text) if i not in orphans)


def clean(text):
    if not text or (OPEN not in text and CLOSE not in text):
        return text
    text = _BROKEN_YEAR.sub(r'(\1)', text)
    text = _adopt_years(text)
    text = _strip_orphans(text)
    text = _SPACED_YEAR.sub(r'(\1)', text)
    # Tidy the spacing the removals leave behind.
    text = re.sub(r'\(\s+', '(', text)
    text = re.sub(r'\s+\)', ')', text)
    text = re.sub(r'(?<=[^\s(])\(', ' (', text)
    text = re.sub(r'[ \t]{2,}', ' ', text)
    return text.strip()


if __name__ == '__main__':
    for s in ['d. first, they must read and answered the questions / instruction)',
              'd. first, he must choose the title / possibility ) (2020)',
              'c. what about        d. I suggest )   (2017)',
              'نوسراوە) 2005)، لە کۆتایی',
              'All of them 2021)',
              '(a. it is said that he has a map/ active voice',
              'کارە یاریدەرەکان وەکو (is, are, am, was) دەبن',
              'A &C)',
              'This sentence (correct) stays (as is).']:
        print(f'  {s!r}\n→ {clean(s)!r}\n')
