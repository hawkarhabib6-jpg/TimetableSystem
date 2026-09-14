# -*- coding: utf-8 -*-
"""Render the book's full-page graphics to PNGs for the .docx build.

The same HTML the PDF uses is rendered here in book order, so the Word file
shows exactly the pages the PDF shows.
"""
import json, os, subprocess, sys
import book, render

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'build', 'gfx')
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'


def main():
    blocks = json.load(open(os.path.join(ROOT, 'build', 'doc.json'),
                            encoding='utf8'))
    units = book.segment(blocks)

    pages = [book.front_matter(units)]      # cover + contents (two pages)
    last = None
    for pi, n, s, e in units:
        if pi != last:
            pages.append(book.part_divider(pi))
            last = pi
        word = book.NUM2WORD.get(n, 'one')
        en, ku = render.UNIT_TITLES.get(word, ('', ''))
        topics = [b['text'] for b in blocks[s:e]
                  if b['kind'] in ('heading', 'subheading', 'boxed')
                  and 3 < len(b['text']) < 42][:9]
        toc = ''.join(f'<span>{render.esc(t)}</span>' for t in topics)
        pages.append(f'''
<section class="opener"><span class="bleedmark">§bleed§</span>
  <svg class="rays" viewBox="0 0 100 100" preserveAspectRatio="none">{render.RAYS}</svg>
  <div class="kicker">Sunrise 12 · Part {pi + 1}</div>
  <div class="num">{n:02d}</div>
  <div class="rule"></div>
  <div class="title">{render.esc(en)}</div>
  <div class="title-ku">{render.esc(ku)}</div>
  <div class="toc">{toc}</div>
</section>''')

    html = os.path.join(ROOT, 'build', 'gfx.html')
    open(html, 'w', encoding='utf8').write(
        render.page('\n'.join(pages), 'graphics'))

    pdf = os.path.join(ROOT, 'build', 'gfx.pdf')
    subprocess.run([CHROME, '--headless', '--no-sandbox', '--disable-gpu',
                    '--no-pdf-header-footer', f'--print-to-pdf={pdf}', html],
                   check=True, capture_output=True)

    import pymupdf
    os.makedirs(OUT, exist_ok=True)
    for f in os.listdir(OUT):
        os.remove(os.path.join(OUT, f))
    d = pymupdf.open(pdf)
    for i in range(d.page_count):
        d[i].get_pixmap(dpi=170).save(os.path.join(OUT, f'g{i:03d}.png'))
    print(f'{d.page_count} graphic pages -> {OUT}')


if __name__ == '__main__':
    main()
