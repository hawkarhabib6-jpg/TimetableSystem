# -*- coding: utf-8 -*-
"""Collect every worked example the source contains.

The source lays its solutions out in table cells: a chain of equations,
usually beside the diagram they belong to. Each cell becomes one example,
so nothing is dropped in favour of a hand-picked selection.
"""
import json, os, re

import prompts

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)

# Topic boundaries, as block indices into build/source.json. Taken from the
# prose headings and section banners of each part.
TOPICS = [
    # (part, first block, title, english, law hint for step reasons)
    ('5', 0,   'ڕێژە سێگۆشەییەکان', 'Trigonometric Ratios', ['sin', 'cos', 'tan']),
    ('5', 102, 'یاسای ساین', 'Law of Sine', ['sin']),
    ('5', 136, 'یاسای کۆساین', 'Law of Cosine', ['cos']),
    ('5', 201, 'ڕووبەری سێگۆشە', 'Area of a Triangle', ['sin']),
    ('5', 284, 'هاوئەنجامە سێگۆشەییەکان', 'Trigonometric Identities',
     ['sin', 'cos', 'tan']),

    ('6', 366, 'لاری', 'Slope', ['m=']),
    ('6', 387, 'هاوکێشەی ڕاستەهێڵ', 'Slope–Intercept Form', ['y=', 'm=']),
    ('6', 398, 'تێکڕای گۆڕان', 'Average Rate of Change', ['f\\left']),
    ('6', 414, 'داتاشراوە', 'The Derivative', ['f', 'x']),
    ('6', 489, 'لاری بە داتاشراوە', 'Slope from the Derivative', ['f', 'm']),
    ('6', 517, 'کێشانی وێنەی نەخشەکان', 'Sketching Graphs', ['x=']),

    ('7', 614, 'پێکنەرەکانی ئاراستەبڕ', 'Components of a Vector', ['x_', 'y_']),
    ('7', 634, 'درێژی ئاراستەبڕ', 'Magnitude of a Vector', ['sqrt']),
    ('7', 654, 'ئاراستەی ئاراستەبڕ', 'Direction of a Vector', ['tan']),
    ('7', 758, 'کۆکردنەوەی ئاراستەبڕەکان', 'Adding Vectors', []),
    ('7', 814, 'لێکدانی ئاراستەبڕ', 'The Dot Product', []),
    ('7', 833, 'ئەستوونی ئاراستەبڕەکان', 'Perpendicular Vectors', []),
]


def load():
    return json.load(open(os.path.join(ROOT, 'build', 'source.json'),
                          encoding='utf8'))


def ranges(blocks):
    """-> [(part, start, end, title, english, hint), ...]"""
    out = []
    for i, (part, start, title, en, hint) in enumerate(TOPICS):
        end = len(blocks)
        for p2, s2, *_ in TOPICS[i + 1:]:
            if p2 == part:
                end = s2
                break
            end = s2
            break
        out.append((part, start, end, title, en, hint))
    return out


# The source used Kurdish words as operands. No maths font carries the
# letterforms, and an equation full of them cannot be pasted anywhere, so
# each is replaced by the notation the booklet defines once in prose.
OPERANDS = [
    ('بەرامبەر لای', r'\mathrm{a}'),
    ('بەرامبەر', r'\mathrm{opp}'),
    ('تەنیشت', r'\mathrm{adj}'),
    ('ژێ', r'\mathrm{hyp}'),
    ('ڕووبەری سێگۆشە', 'A'),
    ('گشتی', r'\mathrm{T}'),
    ('نێوانیان گۆشەی', r'\theta'),
    ('دراوەکە گۆشە', r'\theta'),
    ('دراو گۆشەی', r'\theta'),
    ('ب.گ', r'\theta'),
    ('گۆشە', r'\theta'),
    ('ڕەگەکە ناو داتاشراوەی', "u'"),
    ('ڕەگ ناو', 'u'),
    ('خۆی ڕەگەکە', r'\sqrt{u}'),
    ('توان', 'n'),
    ('یەکەم', 'x'),
    ('دووەم', 'y'),
    ('لا', 'a'),
]

# The source typed function names as plain letters, which a maths engine
# then sets in italic - "sin" reads as s x i x n. Written as commands they
# come out upright, the way every maths text sets them.
_FUNC = re.compile(r'(?<![\\A-Za-z])\{?(sin|cos|tan|cot|sec|csc|log|ln)\}?'
                   r'(?![A-Za-z])')


def clean_math(tex):
    for word, sym in OPERANDS:
        tex = tex.replace(word, sym)
    tex = _FUNC.sub(r'\\\1', tex)
    # The source marked an angle with a hat, even where the angle was a
    # number: "sin 45-with-a-hat" means the angle of 45 degrees.
    tex = re.sub(r'\\hat\{(\d+(?:\.\d+)?)\}', r'\1°', tex)
    # The dot between two vectors is their product, not a full stop. A
    # decimal point always has digits round it, so the two do not collide.
    tex = re.sub(r'(?<=[a-zA-Z])\.(?=[a-zA-Z])', r'\\cdot ', tex)
    tex = tex.replace('\\\\', ' ')       # a line break typed inside an equation
    return balance(tex)


def balance(tex):
    """Drop the braces a few source equations were typed without a partner.

    An unmatched brace makes the whole equation unreadable, and the working
    it belongs to is otherwise sound, so the brace goes rather than the
    equation.
    """
    out, open_at = [], []
    for ch in tex:
        if ch == '{':
            open_at.append(len(out))
            out.append(ch)
        elif ch == '}':
            if not open_at:
                continue
            open_at.pop()
            out.append(ch)
        else:
            out.append(ch)
    for i in open_at:
        out[i] = ' '
    return ''.join(out)


# A lone function name or single letter is a label the typist set as maths,
# not a step of the working.
_TRIVIAL = re.compile(r'^\\?[A-Za-z]{1,10}$')


def usable(tex):
    return len(tex.strip()) > 2 and not _TRIVIAL.match(tex.strip())


def cell_equations(cell):
    return [t for t in (clean_math(p['math'])
                        for p in cell['parts'] if 'math' in p) if usable(t)]


def cell_text(cell):
    return ''.join(p['text'] for p in cell['parts'] if 'text' in p).strip()


def diagrams(cell):
    """Pictures that look like a figure rather than a text screenshot."""
    from PIL import Image
    out = []
    for name in cell['images']:
        if name in prompts.P:
            continue          # a question, re-typed; not a diagram
        p = os.path.join(ROOT, 'assets', 'img', name)
        if not os.path.exists(p):
            continue
        try:
            im = Image.open(p)
        except Exception:
            continue
        if im.width / max(im.height, 1) <= 3.5 and im.width > 60:
            out.append(name)
    return out


_OPS = ('=', '+', '×', '÷', '⇒', '⟹', '\\cdot')


def worked(eqs):
    """True when a chain of equations is a solution, not a heading.

    Some tables set their definitions out the same way as their solutions -
    a cell holding just "sin" and "tan", or the letters of a general rule.
    Those are read back as two-step examples, so a chain has to show some
    working before it is counted as one.
    """
    solid = [e for e in eqs if len(e) >= 6]
    return len(solid) >= 2 and any(o in e for e in solid for o in _OPS)


def examples(blocks, start, end, min_steps=2):
    """Every worked solution in a block range, with its diagram.

    Solutions live in table cells in some topics and as consecutive
    equation paragraphs in others, so both shapes are collected.
    """
    found = []
    run, run_fig, run_ask = [], None, None

    def flush():
        nonlocal run, run_fig, run_ask
        if len(run) >= min_steps and (run_ask or worked(run)):
            found.append({'equations': list(run), 'text': '',
                          'prompt': run_ask, 'figure': run_fig})
        run, run_fig, run_ask = [], None, None

    for b in blocks[start:end]:
        if b['kind'] == 'tbl':
            flush()
            for row in b['rows']:
                # a row often pairs a diagram cell with its solution cell
                figs = [f for c in row for f in diagrams(c)]
                solved = [c for c in row if len(cell_equations(c)) >= min_steps]
                # A row that pairs one question cell with one solution cell
                # keeps its question; a row of several solutions does not,
                # or every example in it would carry the same prompt.
                row_ask = _ask(row) if len(solved) == 1 else None
                for c in row:
                    eq = cell_equations(c)
                    ask = prompts.lookup(c['images']) or row_ask
                    if len(eq) >= min_steps and (ask or worked(eq)):
                        own = diagrams(c)
                        found.append({
                            'equations': eq,
                            'text': cell_text(c),
                            'prompt': ask,
                            'figure': (own or figs or [None])[0],
                        })
            continue

        eq = [t for t in (clean_math(p['math'])
                          for p in b['parts'] if 'math' in p) if usable(t)]
        figs = diagrams(b)
        ask = prompts.lookup(b['images'])
        if eq:
            run.extend(eq)
            run_fig = run_fig or (figs[0] if figs else None)
            run_ask = run_ask or ask
        else:
            # prose between equations ends one solution and starts the next
            flush()
            if figs:
                run_fig = figs[0]
            if ask:
                run_ask = ask
    flush()
    return found


def _ask(cells):
    """The question re-typed for whichever cell of a row carried it."""
    for c in cells:
        got = prompts.lookup(c['images'])
        if got:
            return got
    return None


def stats():
    blocks = load()
    total = 0
    for part, s, e, title, en, hint in ranges(blocks):
        got = examples(blocks, s, e)
        total += len(got)
        print(f'  بەشی {part}  {title:28s} {len(got):3d} نموونە'
              f'   ({s}-{e})')
    print(f'  کۆی گشتی: {total}')


if __name__ == '__main__':
    stats()
