# -*- coding: utf-8 -*-
"""Convert the LaTeX subset used in this booklet to Word's OMML.

The deliverable is an editable .docx, so an equation has to arrive as real
Word maths rather than a picture of it. Only the constructs actually written
in content.py are supported; anything else raises, so a silent wrong render
cannot slip through.
"""
import re
from xml.sax.saxutils import escape

M = 'http://schemas.openxmlformats.org/officeDocument/2006/math'

GREEK = {
    'alpha': 'α', 'beta': 'β', 'gamma': 'γ', 'delta': 'δ', 'theta': 'θ',
    'lambda': 'λ', 'mu': 'μ', 'pi': 'π', 'sigma': 'σ', 'phi': 'φ',
    'omega': 'ω', 'Delta': 'Δ', 'Theta': 'Θ', 'Sigma': 'Σ', 'Omega': 'Ω',
}
SYMBOL = {
    'times': '×', 'div': '÷', 'pm': '±', 'mp': '∓', 'cdot': '·',
    'approx': '≈', 'neq': '≠', 'ne': '≠', 'leq': '≤', 'le': '≤',
    'geq': '≥', 'ge': '≥', 'ldots': '…', 'dots': '…', 'infty': '∞',
    'Rightarrow': '⇒', 'rightarrow': '→', 'to': '→', 'circ': '°',
    'therefore': '∴', 'because': '∵', 'angle': '∠', 'triangle': '△',
    'perp': '⊥', 'parallel': '∥', 'in': '∈', 'notin': '∉',
}
FUNCS = ('sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'log', 'ln', 'exp',
         'arcsin', 'arccos', 'arctan', 'max', 'min')
SPACES = {'quad': '  ', 'qquad': '    ', ',': ' ', ';': ' ', '!': '', ' ': ' '}


class LatexError(ValueError):
    pass


# ----------------------------------------------------------------- tokenizer
TOKEN = re.compile(r'''
    \\[A-Za-z]+        |   # command
    \\[,;!\ ]          |   # spacing command
    \\\{ | \\\}        |   # escaped brace
    [\{\}\^_]          |   # grouping and scripts
    [^\\\{\}\^_]           # anything else, one char
''', re.X)


def tokenize(src):
    return TOKEN.findall(src)


# -------------------------------------------------------------------- writer
def _r(text, style=None):
    """A maths run.

    `style='p'` marks upright type - a function name or a word used as a
    label. Both m:nor and m:sty are written: Word honours the first, other
    readers the second, and a variable set in italic where it should be
    upright is the commonest way a typeset equation looks wrong.
    """
    pr = ''
    if style == 'p':
        pr = (f'<m:rPr xmlns:m="{M}"><m:nor/><m:sty m:val="p"/></m:rPr>')
    return (f'<m:r xmlns:m="{M}">{pr}'
            f'<m:t xml:space="preserve">{escape(text)}</m:t></m:r>')


def _f(num, den):
    return (f'<m:f xmlns:m="{M}"><m:fPr><m:ctrlPr/></m:fPr>'
            f'<m:num>{num}</m:num><m:den>{den}</m:den></m:f>')


def _script(base, sub=None, sup=None):
    if sub is not None and sup is not None:
        return (f'<m:sSubSup xmlns:m="{M}"><m:e>{base}</m:e>'
                f'<m:sub>{sub}</m:sub><m:sup>{sup}</m:sup></m:sSubSup>')
    if sup is not None:
        return (f'<m:sSup xmlns:m="{M}"><m:e>{base}</m:e>'
                f'<m:sup>{sup}</m:sup></m:sSup>')
    return (f'<m:sSub xmlns:m="{M}"><m:e>{base}</m:e>'
            f'<m:sub>{sub}</m:sub></m:sSub>')


def _rad(body, deg=None):
    if deg is None:
        pr = '<m:radPr><m:degHide m:val="1"/><m:ctrlPr/></m:radPr>'
        deg = '<m:deg/>'
    else:
        pr = '<m:radPr><m:ctrlPr/></m:radPr>'
        deg = f'<m:deg>{deg}</m:deg>'
    return f'<m:rad xmlns:m="{M}">{pr}{deg}<m:e>{body}</m:e></m:rad>'


def _delim(body, beg='(', end=')'):
    return (f'<m:d xmlns:m="{M}"><m:dPr>'
            f'<m:begChr m:val="{escape(beg)}"/>'
            f'<m:endChr m:val="{escape(end)}"/><m:ctrlPr/></m:dPr>'
            f'<m:e>{body}</m:e></m:d>')


# -------------------------------------------------------------------- parser
class Parser:
    def __init__(self, tokens):
        self.t, self.i = tokens, 0

    def peek(self):
        return self.t[self.i] if self.i < len(self.t) else None

    def next(self):
        tok = self.peek()
        self.i += 1
        return tok

    def group(self):
        """One argument: a braced group or a single atom."""
        if self.peek() == '{':
            self.next()
            out = self.run(stop='}')
            if self.peek() != '}':
                raise LatexError('unclosed {')
            self.next()
            return out
        atom = self.atom()
        if atom is None:
            raise LatexError('missing argument')
        return atom

    def run(self, stop=None):
        out = []
        while True:
            tok = self.peek()
            if tok is None or tok == stop:
                break
            piece = self.atom()
            if piece is None:
                break
            out.append(piece)
        return ''.join(out)

    def atom(self):
        tok = self.next()
        if tok is None:
            return None

        if tok == '}':
            self.i -= 1
            return None

        if tok in ('^', '_'):
            raise LatexError('script with no base')

        if tok.startswith('\\'):
            piece = self.command(tok[1:])
        elif tok == '{':
            self.i -= 1
            piece = self.group()
        else:
            piece = _r(tok)

        return self.scripts(piece)

    def scripts(self, base):
        sub = sup = None
        while self.peek() in ('^', '_'):
            which = self.next()
            arg = self.group()
            if which == '^':
                sup = arg
            else:
                sub = arg
        if sub is None and sup is None:
            return base
        return _script(base, sub, sup)

    def command(self, name):
        if name in ('frac', 'tfrac', 'dfrac'):
            return _f(self.group(), self.group())

        if name == 'sqrt':
            deg = None
            if self.peek() == '[':
                self.next()
                buf = []
                while self.peek() not in (']', None):
                    buf.append(self.next())
                self.next()
                deg = Parser(buf).run()
            return _rad(self.group(), deg)

        if name == 'left':
            beg = self.next()
            body = []
            depth = 0
            while True:
                tok = self.peek()
                if tok is None:
                    raise LatexError(r'\left with no \right')
                if tok == r'\left':
                    depth += 1
                if tok == r'\right':
                    if depth == 0:
                        break
                    depth -= 1
                body.append(self.next())
            self.next()                       # \right
            end = self.next()
            beg = {'.': '', r'\{': '{'}.get(beg, beg)
            end = {'.': '', r'\}': '}'}.get(end, end)
            return _delim(Parser(body).run(), beg, end)

        if name in FUNCS:
            return _r(name, style='p')

        if name in ('mathrm', 'text', 'mathbf', 'operatorname'):
            plain = re.sub(r'<[^>]+>', '', self.group())
            return _r(plain, style='p')

        if name in GREEK:
            return _r(GREEK[name])

        if name in SYMBOL:
            return _r(SYMBOL[name])

        if name in SPACES:
            return _r(SPACES[name])

        raise LatexError(f'unsupported command: \\{name}')


def convert(tex):
    """LaTeX -> the inner XML of an m:oMath element."""
    p = Parser(tokenize(tex))
    out = p.run()
    if p.peek() is not None:
        raise LatexError(f'unparsed tail at {p.peek()!r}')
    return out


def omath(tex):
    return f'<m:oMath xmlns:m="{M}">{convert(tex)}</m:oMath>'


def omath_para(tex):
    return (f'<m:oMathPara xmlns:m="{M}">'
            f'<m:oMathParaPr><m:jc m:val="center"/></m:oMathParaPr>'
            f'{omath(tex)}</m:oMathPara>')
