# -*- coding: utf-8 -*-
"""Give every step of a worked solution a short reason.

The source stores its solutions as bare chains of equations. Rewriting 250
of them by hand is not practical, and leaving them bare fails the brief -
each step is meant to say why it was taken. Steps fall into a small number
of recognisable shapes, so the reason is derived from the shape.
"""
import re

UNKNOWNS = 'xyzabcmnABCDMNKTRSV'


def _nums(tex):
    return re.findall(r'\d+(?:\{?\.\}?\d+)?', tex)


def _letters(tex):
    body = re.sub(r'\\[A-Za-z]+|\\mathrm\{[^}]*\}', ' ', tex)
    return set(re.findall(r'[A-Za-z]', body))


def _is_final(tex):
    """Something like "x = 7.13" - one unknown, then a bare number."""
    return bool(re.match(r'^\s*[A-Za-z]{1,3}(?:\^?\{?[^=]{0,6}\}?)?\s*'
                         r'(?:=|≈)\s*-?\d', tex.strip()))


# Shapes that say plainly what a step is doing, whatever the topic. These
# are checked before the generic before/after comparison, which can only
# guess from how the line changed.
SHAPES = [
    # a line carrying a question mark is the question, not a step of working
    (r"\?",                        'بەهاکانی دراو دادەنێین.'),
    (r"^m\s*=\s*[^=]+$",          'لاری دەخوێنینەوە.'),
    (r"^b\s*=\s*[^=]+$",          'یەکتربڕین دەخوێنینەوە.'),
    (r"^m=.*⇒.*b=",                'لاری و یەکتربڕین دەخوێنینەوە.'),
    (r"^\\left\(0,",              'خاڵی یەکتربڕین دەنووسین.'),
    (r"^y\s*=.*x",                 'هاوکێشەکە بە شێوەی y = mx + b دەنووسینەوە.'),
    (r"f\s*'\s*\\left\(x", 'داتاشراوە وەردەگرین.'),
    (r"\\overrightarrow|\\langle",  'پێکنەرەکانی ئاراستەبڕ دەدۆزینەوە.'),
    (r"\\left\|.*\\right\|\s*=",  'درێژی ئاراستەبڕەکە هەژمار دەکەین.'),
    (r"\\tan\\?theta|\\tan\s*\\theta", 'یاسای ئاراستە دادەنێین.'),
    (r"\\cdot",                    'لێکدانی خاڵی هەژمار دەکەین.'),
    (r"\\text\{ROC\}|ROC",         'یاسای تێکڕای گۆڕان دادەنێین.'),
    (r"^x\s*=\s*\\frac\{-b\}",   'سەری کەوانەکە دەدۆزینەوە.'),
]


def _shape(tex):
    t = tex.strip()
    for pat, why in SHAPES:
        if re.search(pat, t):
            return why
    return None


def reason(tex, prev=None, first=False, last=False, law_hint=None):
    """One short Kurdish line saying what this step does."""
    t = tex.replace(' ', '')

    shaped = _shape(tex)
    if shaped:
        return shaped

    if first:
        # a line written in named quantities - opp, adj, hyp - is the law
        # itself, before any number has been put into it
        if '\\mathrm{' in tex:
            return 'یاساکە دادەنێین.'
        if law_hint and any(k in t for k in law_hint):
            return 'یاساکە دادەنێین.'
        if _nums(tex):
            return 'بەهاکانی دراو دادەنێین.'
        return 'یاساکە دادەنێین.'

    if re.search(r'\\(sin|cos|tan)\^\{?-1', t) or '^{-1}' in t:
        return 'بە کرداری پێچەوانە گۆشەکە دەردەهێنین.'

    if t.startswith('\\sqrt') or re.match(r'^\\sqrt', t) or '\\sqrt' in t and \
            prev and '\\sqrt' not in prev.replace(' ', ''):
        return 'ڕەگی دووەمی هەردوو لا دەگرین.'

    if '÷' in tex or (prev and prev.count('\\frac') < tex.count('\\frac')
                      and '=' in tex):
        return 'هەردوو لا بەسەر هاوکۆلکەی نەزانراوەکەدا دابەش دەکەین.'

    if prev is not None:
        pn, cn = len(_nums(prev)), len(_nums(tex))
        pl, cl = _letters(prev), _letters(tex)
        if cl and pl and cl < pl:
            return 'نەزانراوەکە جیا دەکەینەوە.'
        if cn < pn and cn:
            return 'هەژمار دەکەین.'
        if cn > pn:
            return 'بەهاکان جێگیر دەکەین.'
        if len(tex) < len(prev) * 0.75:
            return 'کورت دەکەینەوە.'

    if last:
        return 'ئەنجام.'
    if _is_final(tex):
        return 'بەهاکە دەردەکەوێت.'
    return 'هەنگاوی دواتر.'


# When a shape repeats down a solution, only the first line is naming the
# step; the ones after it are carrying out the arithmetic.
CONTINUATION = ['بەهاکان جێگیر دەکەین.', 'هەژمار دەکەین.',
                'کورت دەکەینەوە.', 'ئەنجام.']


def build(equations, law_hint=None):
    """-> [(reason, latex), ...] for a chain of solution equations."""
    out, prev, seen = [], None, None
    n = len(equations)
    for i, tex in enumerate(equations):
        why = reason(tex, prev, first=(i == 0), last=(i == n - 1),
                     law_hint=law_hint)
        if why == seen:
            # same shape twice running: say what changed, not what it is
            k = min(sum(1 for w, _ in out if w in CONTINUATION),
                    len(CONTINUATION) - 1)
            why = CONTINUATION[-1] if i == n - 1 else CONTINUATION[k]
        else:
            seen = why
        out.append((why, tex))
        prev = tex
    return out
