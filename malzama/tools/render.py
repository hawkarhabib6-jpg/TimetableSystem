# -*- coding: utf-8 -*-
"""Turn the classified blocks into the designed HTML book."""
import json, re, os, html, sys, math
from mixed import segments
from textfix import clean, split_marker

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DOC = os.path.join(ROOT, 'build', 'doc.json')

UNIT_TITLES = {
    'one': ('Giving instructions', 'پێدانی ڕێنمایی'),
    'two': ('Giving advice and suggestions', 'پێدانی ئامۆژگاری و پێشنیار'),
    'three': ('Agreement and disagreement', 'ڕازیبوون و ناڕازیبوون'),
    'four': ('Comparing and contrasting', 'بەراوردکردن'),
    'five': ('The passive voice', 'ڕستەی چالاک و ناچالاک'),
    'six': ('Reported speech', 'قسەی گوێزراوە'),
    'seven': ('Relative and participle clauses', 'ڕستەی پەیوەندیدار'),
    'eight': ('Conditionals', 'ڕستەی مەرجدار'),
    'nine': ('Review and practice', 'پێداچوونەوە و ڕاهێنان'),
}
WORD2NUM = {w: i + 1 for i, w in enumerate(
    'one two three four five six seven eight nine ten eleven twelve'.split())}

OPT_SPLIT = re.compile(r'(?<![A-Za-z])([a-dA-D])\s*[.)]\s+')
FORMULA_HINT = re.compile(
    r'(\+\s*v\s*\(|\bV\s*\(base\)|\bSubject\b.*\+|\bcomp…|\bcomp\.\.\.)', re.I)


RAYS = ''.join(
    f'<line x1="50" y1="100" x2="{50 + 78 * math.cos(math.radians(a)):.2f}"'
    f' y2="{100 - 78 * math.sin(math.radians(a)):.2f}"'
    f' stroke="#E9A85C" stroke-width="{0.22 if i % 2 else 0.42}"/>'
    for i, a in enumerate(range(6, 175, 7)))


def esc(s):
    return html.escape(clean(s), quote=False)


def mix(text, host):
    """Escape text, giving every run the font of its own script.

    `host` is the script of the surrounding paragraph; a run in the other
    script - its brackets included - is wrapped so it takes its own font and
    keeps its own direction.
    """
    out = []
    for sc, chunk in segments(clean(text)):
        if sc == host:
            out.append(html.escape(chunk, quote=False))
            continue
        # Whitespace stays outside the isolate: a space trapped at an isolate
        # boundary collapses, running the two scripts together.
        lead = chunk[:len(chunk) - len(chunk.lstrip())]
        tail = chunk[len(chunk.rstrip()):]
        core = html.escape(chunk.strip(), quote=False)
        out.append(f'{lead}<bdi class="x-{sc}">{core}</bdi>{tail}')
    return ''.join(out)


def esc_ku(s):
    return mix(s, 'ku')


def esc_en(s):
    return mix(s, 'en')


def ku_html(text):
    """Inner HTML for a Kurdish paragraph, with any list marker placed.

    Where the marker sits on the page otherwise depends on how the bidi
    algorithm resolves the digits and brackets around it, which is why the
    source's "2-(on)" and "(In) -1" landed differently. Pulling the number
    out and emitting it first puts it at the start of the line every time.
    """
    marker, rest = split_marker(text)
    body = esc_ku(rest)
    if marker is None:
        return body
    return f'<span class="mk">{esc(marker)}-</span>{body}' 


def is_ku(b):
    return b.get('script') == 'ku'


def split_options(text):
    """Split '(a. X  b. Y  c. Z  d. W)' into [(letter, text), ...]."""
    t = text.strip().strip('()').strip()
    parts = OPT_SPLIT.split(t)
    if len(parts) < 3:
        return None
    lead = parts[0].strip()
    opts = []
    for i in range(1, len(parts) - 1, 2):
        letter = parts[i]
        letter = letter.lower()
        body = parts[i + 1].strip().rstrip(')').strip(' .,')
        if body:
            opts.append((letter, body))
    if len(opts) < 2:
        return None
    return lead, opts


def formula_html(text):
    """Render a grammar pattern as a chain of chips."""
    raw = [seg.strip() for seg in re.split(r'\s*\+\s*', text) if seg.strip()]
    if len(raw) < 2:
        return None
    chips = []
    for i, seg in enumerate(raw):
        alts = [a.strip() for a in re.split(r'\s*/\s*|\s{3,}', seg) if a.strip()]
        if len(alts) > 1 and all(len(a) < 22 for a in alts):
            inner = ''.join(f'<i>{esc(a)}</i>' for a in alts)
            chips.append(f'<span class="chip stack">{inner}</span>')
        else:
            cls = 'chip key' if i == 0 else 'chip'
            chips.append(f'<span class="{cls}">{esc(seg)}</span>')
    return '<div class="formula">' + '<span class="op">+</span>'.join(chips) + '</div>'


def img_html(names, caption=''):
    names = [n for n in names if not n.lower().endswith('.wmf')]
    if not names:
        return ''
    cls = 'duo' if len(names) == 2 else ''
    imgs = ''.join(f'<img src="../assets/img/{n}">' for n in names)
    cap = f'<figcaption>{esc(caption)}</figcaption>' if caption else ''
    return f'<figure class="{cls}">{imgs}{cap}</figure>'


def table_html(rows):
    if not rows:
        return ''
    def cls(c):
        return ' class="ku"' if re.search(r'[؀-ۿ]', c) else ''
    head = ''.join(f'<th{cls(c)}>{esc(c)}</th>' for c in rows[0])
    body = ''
    for r in rows[1:]:
        body += '<tr>' + ''.join(f'<td{cls(c)}>{esc(c)}</td>' for c in r) + '</tr>'
    return f'<table class="grid"><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table>'


SHORT_ALT = re.compile(r'^[A-Za-z][A-Za-z\s\'’]{1,22}$')
LEADIN = re.compile(r'^(choose|which|complete|put|rewrite|correct|find)\b.*[:?-]\s*$', re.I)


def group(blocks, start, end):
    """Merge fragments the original scattered across paragraphs.

    The source typeset grammar patterns as a stack of separate one-line
    paragraphs (`Have to` / `Subject Must + v (base)` / `Need to`). Here they
    become a single formula block, and a lead-in followed by loose option
    lines becomes a single mcq block.
    """
    out, i = [], start
    while i < end:
        b = blocks[i]
        t = b.get('text', '')

        # --- grammar pattern ------------------------------------
        if b['kind'] in ('answer', 'subheading', 'text') and FORMULA_HINT.search(t):
            alts, j = [], i - 1
            while j >= start and blocks[j]['kind'] in ('answer', 'subheading', 'text') \
                    and not blocks[j].get('images') \
                    and SHORT_ALT.match(blocks[j].get('text', '')):
                alts.insert(0, blocks[j]['text'])
                if out and out[-1] is blocks[j]:
                    out.pop()
                j -= 1
            k2 = i + 1
            while k2 < end and blocks[k2]['kind'] in ('answer', 'subheading', 'text') \
                    and not blocks[k2].get('images') \
                    and SHORT_ALT.match(blocks[k2].get('text', '')):
                alts.append(blocks[k2]['text'])
                k2 += 1
            if alts:
                out.append({'kind': 'formula', 'text': t, 'alts': alts, 'script': 'en'})
                i = k2
                continue

        # --- an options run that starts mid-sentence -------------
        mo = re.search(r'\(\s*a[.)]\s*(.+)$', t, re.I)
        if mo and not re.search(r'\bb[.)]\s', t, re.I) and i + 1 < end:
            opts, k2 = [mo.group(1).strip()], i + 1
            for w in 'bcd':
                if k2 < end:
                    m2 = re.match(r'^\s*([a-dA-D])\s*[.)]\s+(.{2,90})$',
                                  blocks[k2].get('text', ''))
                    if m2 and m2.group(1).lower() == w \
                            and not blocks[k2].get('images'):
                        opts.append(m2.group(2).strip().rstrip(')'))
                        k2 += 1
                        continue
                break
            if len(opts) >= 3:
                stem = t[:mo.start()].strip()
                out.append({'kind': 'looseq', 'text': stem, 'opts': opts,
                            'script': 'en'})
                i = k2
                continue

        # --- consecutive lettered option lines -------------------
        m = re.match(r'^\s*([a-dA-D])\s*[.)]\s+(.{2,90})$', t)
        if m and m.group(1).lower() == 'a':
            opts, k2 = [m.group(2).strip()], i + 1
            want = 'bcd'
            for w in want:
                if k2 < end:
                    m2 = re.match(r'^\s*([a-dA-D])\s*[.)]\s+(.{2,90})$',
                                  blocks[k2].get('text', ''))
                    if m2 and m2.group(1).lower() == w \
                            and not blocks[k2].get('images'):
                        opts.append(m2.group(2).strip())
                        k2 += 1
                        continue
                break
            if len(opts) >= 3:
                stem = ''
                if out and out[-1].get('kind') in ('text', 'question') \
                        and out[-1].get('script') == 'en' \
                        and not out[-1].get('images'):
                    stem = out.pop()['text']
                out.append({'kind': 'looseq', 'text': stem, 'opts': opts,
                            'script': 'en'})
                i = k2
                continue

        # --- lead-in + loose options ----------------------------
        if b['kind'] in ('text', 'question', 'heading', 'subheading') and LEADIN.match(t):
            opts, k2 = [], i + 1
            while k2 < end and len(opts) < 4 and blocks[k2]['kind'] in ('text', 'answer') \
                    and blocks[k2].get('script') == 'en' \
                    and not blocks[k2].get('images') \
                    and 3 < len(blocks[k2].get('text', '')) < 90:
                opts.append(blocks[k2]['text'])
                k2 += 1
            if len(opts) >= 3:
                out.append({'kind': 'looseq', 'text': t, 'opts': opts, 'script': 'en'})
                i = k2
                continue

        out.append(b)
        i += 1
    return out


def stacked_formula(text, alts):
    """Chip chain where the modal slot holds every alternative."""
    segs = [x.strip() for x in re.split(r'\s*\+\s*', text) if x.strip()]
    chips = []
    for idx, seg in enumerate(segs):
        if idx == 0:
            words = seg.split()
            if len(words) > 1 and alts:
                chips.append(f'<span class="chip key">{esc(words[0])}</span>')
                chips.append('<span class="op">+</span>')
                stack = [' '.join(words[1:])] + alts
                inner = ''.join(f'<i>{esc(a)}</i>' for a in dict.fromkeys(stack))
                chips.append(f'<span class="chip stack">{inner}</span>')
            else:
                chips.append(f'<span class="chip key">{esc(seg)}</span>')
        else:
            chips.append('<span class="op">+</span>')
            chips.append(f'<span class="chip">{esc(seg)}</span>')
    return '<div class="formula">' + ''.join(chips) + '</div>'


def render_range(blocks, start, end, unit_word, unit_no):
    """Render one unit: blocks[start:end]."""
    en_title, ku_title = UNIT_TITLES.get(unit_word, ('', ''))
    out = []

    # --- Opener -------------------------------------------------
    rays = RAYS
    topics = [b['text'] for b in blocks[start:end]
              if b['kind'] in ('heading', 'subheading') and 3 < len(b['text']) < 42][:9]
    toc = ''.join(f'<span>{esc(t)}</span>' for t in topics)
    done = int(round((unit_no - 1) / 8 * 100))
    out.append(f'''
<section class="opener"><span class="bleedmark">§bleed§</span>
  <svg class="rays" viewBox="0 0 100 100" preserveAspectRatio="none">{rays}</svg>
  <div class="kicker">Sunrise 12 · Companion</div>
  <div class="num">{unit_no:02d}</div>
  <div class="rule"></div>
  <div class="title">{esc(en_title)}</div>
  <div class="title-ku">{esc(ku_title)}</div>
  <div class="progress"><div class="bar"><i style="width:{done}%"></i></div>
    Unit {unit_no} of 8 &nbsp;·&nbsp; {done}% of this part behind you</div>
  <div class="toc">{toc}</div>
</section>
<div class="runhead"><span class="u">Unit {unit_no} · {esc(en_title)}</span>
  <span class="t">Sunrise 12 Companion</span></div>''')

    blocks = group(blocks, start, end)
    start, end = 0, len(blocks)

    def translation(at):
        """Every Kurdish block that follows position `at`, and no more.

        A paragraph's translation is whatever Kurdish immediately follows it;
        taking only the first block would drop the rest of the same
        translation, and reaching further would pull in the next one."""
        out_, j = [], at + 1
        while j < end and blocks[j]['kind'] == 'kurdish' \
                and not blocks[j].get('images'):
            out_.append(blocks[j]['text'])
            j += 1
        return out_, j - at - 1

    i, qn = start, 0
    while i < end:
        b = blocks[i]
        k, t = b['kind'], b.get('text', '')
        nxt = blocks[i + 1] if i + 1 < end else None
        ku_all, ku_n = translation(i)
        ku = '\n'.join(ku_all) if ku_all else None

        if k == 'unit':
            i += 1
            continue

        if b.get('images'):
            out.append(img_html(b['images'], t if len(t) < 90 else ''))
            if len(t) >= 90:
                out.append(f'<p class="en plain">{esc_en(t)}</p>')
            i += 1
            continue

        if k == 'table':
            out.append(table_html(b['rows']))
            i += 1
            continue

        if k == 'formula':
            out.append(stacked_formula(t, b['alts']))
            i += 1
            continue

        if k == 'looseq':
            qn += 1
            letters = 'abcd'
            wide = 'wide' if max(len(o) for o in b['opts']) > 46 else ''
            items = ''.join(
                f'<div class="opt"><span class="l">{letters[n]}</span>'
                f'<span>{esc(o)}</span></div>' for n, o in enumerate(b['opts']))
            stem = esc(t) if t else 'Choose the correct answer'
            out.append(f'<div class="qa"><p class="q"><span class="n">{qn}</span>'
                       f'<span>{stem}</span></p>'
                       f'<div class="mcq {wide}">{items}</div></div>')
            i += 1
            continue

        if k == 'tb':
            t = re.sub(r'^\s*T\.?\s*B\s*\d*\s*[:/]?\s*', '', t, flags=re.I)
            body = f'<p class="{"ku" if is_ku(b) else "en"} plain">{ku_html(t) if is_ku(b) else esc(t)}</p>'
            if ku:
                body += ''.join(f'<p class="ku plain">{ku_html(x)}</p>'
                                for x in ku_all)
                i += ku_n
            out.append(f'<div class="tb">{body}</div>')
            i += 1
            continue

        if k == 'example':
            body = esc(re.sub(r'^\s*e\.?\s*g\s*/?\s*', '', t, flags=re.I))
            out.append(f'<div class="eg"><span class="lbl">Example</span>{body}</div>')
            i += 1
            continue

        if re.match(r'^\s*question\s*bank\b', t, re.I) and len(t) < 30:
            out.append('<div class="bank"><span>Question Bank</span>'
                       '<span class="ku">بانکی پرسیار</span></div>')
            i += 1
            continue

        if k == 'heading':
            if re.search(r'question bank', t, re.I):
                out.append('<div class="bank"><span>Question Bank</span>'
                           '<span class="ku">بانکی پرسیار</span></div>')
            else:
                out.append(f'<h2 class="sec">{esc(t)}</h2>')
            i += 1
            continue

        if k == 'subheading':
            f = formula_html(t) if FORMULA_HINT.search(t) else None
            if f:
                out.append(f)
            else:
                cls = 'sub ku' if is_ku(b) else 'sub'
                out.append(f'<h3 class="{cls}">{esc(t)}</h3>')
            i += 1
            continue

        if k == 'mcq':
            parsed = split_options(t)
            if parsed:
                lead, opts = parsed
                if not lead and out:
                    prev = out[-1]
                    if prev.startswith('<p class="en plain">'):
                        lead = re.sub(r'<[^>]+>', '', out.pop())
                qn += 1
                wide = 'wide' if max(len(o[1]) for o in opts) > 46 else ''
                items = ''.join(
                    f'<div class="opt"><span class="l">{l}</span><span>{esc(x)}</span></div>'
                    for l, x in opts)
                head = (f'<p class="q"><span class="n">{qn}</span>'
                        f'<span>{esc(lead)}</span></p>' if lead else
                        f'<p class="q"><span class="n">{qn}</span>'
                        f'<span>Choose the correct answer</span></p>')
                kub = ''.join(f'<p class="ku">{ku_html(x)}</p>' for x in ku_all)
                i += ku_n
                out.append(f'<div class="qa">{head}'
                           f'<div class="mcq {wide}">{items}</div>{kub}</div>')
            else:
                out.append(f'<p class="en plain">{esc_en(t)}</p>')
            i += 1
            continue

        if k == 'question':
            qn += 1
            ans = ''
            if nxt and nxt['kind'] == 'answer':
                ans = f'<p class="a">{esc(nxt["text"])}</p>'
                i += 1
                ku_all, ku_n = translation(i)
            kub = ''.join(f'<p class="ku">{ku_html(x)}</p>' for x in ku_all)
            i += ku_n
            out.append(f'<div class="qa"><p class="q"><span class="n">{qn}</span>'
                       f'<span>{esc(t)}</span></p>{ans}{kub}</div>')
            i += 1
            continue

        if k == 'kurdish':
            out.append(f'<p class="ku plain">{ku_html(t)}</p>')
            i += 1
            continue

        # text / answer, optionally paired with its Kurdish translation
        cls = 'a' if k == 'answer' else 'en'
        if ku_all:
            kub = ''.join(f'<p class="ku">{ku_html(x)}</p>' for x in ku_all)
            out.append(f'<div class="pair"><p class="en">{esc_en(t)}</p>'
                       f'{kub}</div>')
            i += 1 + ku_n
        else:
            tag = f'<p class="en plain">{esc_en(t)}</p>'
            red = any((r.get('color') or '') in ('C00000', 'FF0000', 'E36C0A')
                      for r in b.get('runs', []))
            is_prompt = bool(re.search(r'(:-|:|-|…|\.{3,}|_{3,})\s*$', t)) or \
                re.match(r'^\s*(find|choose|complete|correct|rewrite|put|match|'
                         r'answer|fill|write|underline|question)\b', t, re.I)
            if k == 'answer' and is_prompt and len(t) < 70:
                tag = f'<p class="prompt"><span>{esc(t.rstrip(" -:"))}</span></p>'
            elif k == 'answer' and red and len(t) > 25:
                tag = f'<div class="qa"><p class="a">{esc(t)}</p></div>'
            out.append(tag)
            i += 1

    return '\n'.join(keep_with_next(out))


KEEPS = ('<h2 class="sec"', '<h3 class="sub', '<p class="prompt"',
         '<div class="bank"')


def keep_with_next(parts):
    """Bind each heading to the block under it.

    A heading that lands at the foot of a page leaves its topic orphaned, so
    heading and first block travel together to the next page instead.
    """
    out, i = [], 0
    while i < len(parts):
        p = parts[i]
        if p.startswith(KEEPS) and i + 1 < len(parts):
            out.append(f'<div class="keep">{p}\n{parts[i + 1]}</div>')
            i += 2
        else:
            out.append(p)
            i += 1
    return out


def page(body, title):
    return f'''<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>{esc(title)}</title>
<link rel="stylesheet" href="../fonts/fonts.css">
<link rel="stylesheet" href="../assets/style.css">
</head><body>
{body}
</body></html>'''


if __name__ == '__main__':
    blocks = json.load(open(DOC, encoding='utf8'))
    units = [(i, b['text']) for i, b in enumerate(blocks) if b['kind'] == 'unit']
    print('unit markers:', units)
    start = int(sys.argv[1]); end = int(sys.argv[2])
    word = sys.argv[3] if len(sys.argv) > 3 else 'one'
    body = render_range(blocks, start, end, word, WORD2NUM.get(word, 1))
    out = os.path.join(ROOT, 'build', f'unit-{word}.html')
    open(out, 'w', encoding='utf8').write(page(body, f'Sunrise 12 — Unit {word}'))
    print('wrote', out, len(body), 'bytes')
