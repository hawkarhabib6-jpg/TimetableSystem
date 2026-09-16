# -*- coding: utf-8 -*-
"""Convert Word's OMML equations to LaTeX.

The source carries 1,126 equations as real Word maths, with Kurdish words
used as operands ("sin A = بەرامبەر/ژێ"). Reading them as plain text loses
the structure - a fraction collapses into its numerator followed by its
denominator - so they are walked as a tree instead.
"""
M = '{http://schemas.openxmlformats.org/officeDocument/2006/math}'


def _q(name):
    return M + name


def _children(el):
    return [c for c in el if isinstance(c.tag, str)]


def _text_of(el):
    return ''.join(t.text or '' for t in el.iter(_q('t')))


def _is_one_group(s):
    """True when the outermost braces enclose the whole string.

    "{y}_{2}-{y}_{1}" also starts and ends with a brace, but its first
    group closes early; treating it as one group produced a fraction whose
    numerator was only "y".
    """
    if not (s.startswith('{') and s.endswith('}')):
        return False
    depth = 0
    for i, ch in enumerate(s):
        if ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                return i == len(s) - 1
    return False


def _wrap(s):
    """Brace a LaTeX group unless it already is exactly one."""
    s = s.strip()
    if len(s) == 1 or _is_one_group(s):
        return s if s.startswith('{') else '{' + s + '}'
    return '{' + s + '}'


def _arg(el, name, conv):
    child = el.find(_q(name))
    return conv(child) if child is not None else ''


def to_latex(el, convert_text=lambda s: s):
    """Render one OMML element (usually m:oMath) as a LaTeX string."""

    def walk(node):
        tag = node.tag.split('}')[-1] if isinstance(node.tag, str) else ''

        if tag == 'r':                       # run of literal text
            return convert_text(_text_of(node))

        if tag == 'f':                       # fraction
            num = _arg(node, 'num', walk)
            den = _arg(node, 'den', walk)
            return r'\frac' + _wrap(num) + _wrap(den)

        if tag == 'sSup':                    # superscript
            return _wrap(_arg(node, 'e', walk)) + '^' + _wrap(_arg(node, 'sup', walk))

        if tag == 'sSub':                    # subscript
            return _wrap(_arg(node, 'e', walk)) + '_' + _wrap(_arg(node, 'sub', walk))

        if tag == 'sSubSup':
            return (_wrap(_arg(node, 'e', walk))
                    + '_' + _wrap(_arg(node, 'sub', walk))
                    + '^' + _wrap(_arg(node, 'sup', walk)))

        if tag == 'rad':                     # root
            deg = _arg(node, 'deg', walk)
            body = _wrap(_arg(node, 'e', walk))
            return (r'\sqrt[' + deg + ']' + body) if deg.strip() else r'\sqrt' + body

        if tag == 'd':                       # delimiters
            pr = node.find(_q('dPr'))
            beg, end = '(', ')'
            if pr is not None:
                b = pr.find(_q('begChr'))
                e = pr.find(_q('endChr'))
                if b is not None:
                    beg = b.get(_q('val'), '(')
                if e is not None:
                    end = e.get(_q('val'), ')')
            inner = ''.join(walk(c) for c in _children(node) if c.tag != _q('dPr'))
            beg = {'{': r'\{', '|': r'|', '': '.'}.get(beg, beg)
            end = {'}': r'\}', '|': r'|', '': '.'}.get(end, end)
            return r'\left' + beg + inner + r'\right' + end

        if tag == 'func':                    # sin, cos, log …
            name = _arg(node, 'fName', walk).strip()
            body = _arg(node, 'e', walk).strip()
            known = ('sin', 'cos', 'tan', 'cot', 'sec', 'csc',
                     'log', 'ln', 'exp')
            if name.lower() in known:
                name = '\\' + name.lower()
            return f'{name}{{{body}}}' if body else name

        if tag == 'nary':                    # sum, integral, product
            pr = node.find(_q('naryPr'))
            chr_ = '∑'
            if pr is not None:
                c = pr.find(_q('chr'))
                if c is not None:
                    chr_ = c.get(_q('val'), '∑')
            op = {'∑': r'\sum', '∏': r'\prod', '∫': r'\int',
                  '∬': r'\iint', '⋃': r'\bigcup', '⋂': r'\bigcap'}.get(chr_, r'\sum')
            sub, sup = _arg(node, 'sub', walk), _arg(node, 'sup', walk)
            out = op
            if sub.strip():
                out += '_' + _wrap(sub)
            if sup.strip():
                out += '^' + _wrap(sup)
            return out + ' ' + _arg(node, 'e', walk)

        if tag in ('acc', 'bar'):
            body = _wrap(_arg(node, 'e', walk))
            return (r'\overline' if tag == 'bar' else r'\hat') + body

        if tag in ('limLow', 'limUpp'):
            base = _wrap(_arg(node, 'e', walk))
            lim = _wrap(_arg(node, 'lim', walk))
            return base + ('_' if tag == 'limLow' else '^') + lim

        if tag == 'eqArr':                   # stacked equations
            parts = [walk(c) for c in node.findall(_q('e'))]
            return r' \\ '.join(p.strip() for p in parts)

        if tag == 'm':                       # matrix
            rows = []
            for mr in node.findall(_q('mr')):
                rows.append(' & '.join(walk(c).strip()
                                       for c in mr.findall(_q('e'))))
            return r'\begin{matrix}' + r' \\ '.join(rows) + r'\end{matrix}'

        if tag.endswith('Pr') or tag == 'ctrlPr':
            return ''

        return ''.join(walk(c) for c in _children(node))

    return walk(el).strip()
