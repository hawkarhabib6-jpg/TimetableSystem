# -*- coding: utf-8 -*-
"""Render the authored content to a designed HTML booklet.

Content is written as plain data in content.py; this module is only layout.
Kurdish stays outside the equations - a ratio is defined once in a styled
box and the working is then pure notation, which both reads better and
survives a copy-paste into Word or InDesign.
"""
import html as _html
import os

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)


def esc(s):
    return _html.escape(str(s), quote=False)


def M(tex, block=False):
    """A slot the KaTeX pass fills in."""
    kind = 'block' if block else 'inline'
    return f'<span class="math-{kind}">{esc(tex)}</span>'


# ---------------------------------------------------------------- components
def concept(text):
    return f'<p class="concept">{text}</p>'


def para(text):
    return f'<p class="ku">{text}</p>'


def law(title, *lines, note=None):
    body = ''.join(f'<div class="law-line">{M(t, True)}</div>' for t in lines)
    n = f'<p class="law-note">{note}</p>' if note else ''
    return (f'<section class="law"><h4 class="law-title">{title}</h4>'
            f'{body}{n}</section>')


def bullets(*items, kind='plain'):
    li = ''.join(f'<li>{t}</li>' for t in items)
    return f'<ul class="bullets {kind}">{li}</ul>'


def steps(*rows):
    """rows: (explanation, latex) - the why beside the what."""
    out = []
    for i, (why, tex) in enumerate(rows, 1):
        out.append(
            f'<li class="step"><span class="n">{i}</span>'
            f'<div class="body"><p class="why">{why}</p>'
            f'{M(tex, True) if tex else ""}</div></li>')
    return '<ol class="steps">' + ''.join(out) + '</ol>'


def example(no, prompt, figure=None, solution='', answer=None):
    fig = (f'<figure class="dia"><img src="../assets/img/{figure}"></figure>'
           if figure else '')
    ans = (f'<p class="answer">وەڵام: {answer}</p>' if answer else '')
    return (f'<section class="example"><header><span class="badge">نموونە'
            f' {no}</span><p class="prompt">{prompt}</p></header>'
            f'{fig}{solution}{ans}</section>')


def mistake(*items):
    li = ''.join(f'<li>{t}</li>' for t in items)
    return (f'<section class="pitfall"><h4>هەڵە باوەکان</h4>'
            f'<ul>{li}</ul></section>')


def shortcut(*items):
    li = ''.join(f'<li>{t}</li>' for t in items)
    return (f'<section class="tip"><h4>ڕێگا کورتەکان</h4>'
            f'<ul>{li}</ul></section>')


def table(headers, rows, cls=''):
    th = ''.join(f'<th>{h}</th>' for h in headers)
    tb = ''.join('<tr>' + ''.join(f'<td>{c}</td>' for c in r) + '</tr>'
                 for r in rows)
    return (f'<table class="grid {cls}"><thead><tr>{th}</tr></thead>'
            f'<tbody>{tb}</tbody></table>')


def section(no, title_ku, title_en, *body):
    return (f'<section class="chapter"><header class="chead">'
            f'<span class="cnum">{no}</span>'
            f'<h2>{title_ku}</h2><p class="cen">{title_en}</p></header>'
            + ''.join(body) + '</section>')


def page(body, title):
    return f'''<!doctype html><html lang="ku" dir="rtl"><head>
<meta charset="utf-8"><title>{esc(title)}</title>
<link rel="stylesheet" href="../fonts/fonts.css">
<link rel="stylesheet" href="../vendor/package/dist/katex.min.css">
<link rel="stylesheet" href="../assets/style.css">
</head><body>
{body}
</body></html>'''
