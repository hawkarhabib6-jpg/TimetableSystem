# -*- coding: utf-8 -*-
"""Render a handful of representative pages, to judge the design before
committing four hundred of them to it."""
import json, os, subprocess, sys
import book, render

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'


def main():
    blocks = json.load(open(os.path.join(ROOT, 'build', 'doc.json'),
                            encoding='utf8'))
    units = book.segment(blocks)
    parts = [book.front_matter(units), book.part_divider(0)]
    # One unit's opening pages, where every component appears.
    p, n, s, e = units[0]
    parts.append(render.render_range(blocks, s, min(s + 170, e), 'one', 1))

    html = os.path.join(ROOT, 'build', 'sample.html')
    open(html, 'w', encoding='utf8').write(
        render.page('\n'.join(parts), 'Sunrise 12 — sample'))
    subprocess.run([CHROME, '--headless', '--no-sandbox', '--disable-gpu',
                    '--no-pdf-header-footer',
                    f'--print-to-pdf={ROOT}/build/sample.pdf', html],
                   check=True, capture_output=True)
    import pymupdf
    d = pymupdf.open(os.path.join(ROOT, 'build', 'sample.pdf'))
    for i in range(min(6, d.page_count)):
        d[i].get_pixmap(dpi=100).save(os.path.join(ROOT, 'build', f's{i+1}.png'))
    print(f'{d.page_count} sample pages')


if __name__ == '__main__':
    main()
