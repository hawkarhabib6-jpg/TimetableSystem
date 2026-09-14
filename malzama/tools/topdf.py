# -*- coding: utf-8 -*-
"""Print book.html to PDF with a school footer on every text page.

Chromium's footer template applies to every page alike, so the book is
printed twice - once with the footer, once without - and the full-bleed
pages are taken from the clean pass, keeping the artwork unmarked.
"""
import os, sys
from playwright.sync_api import sync_playwright

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXE = os.environ.get('CHROME',
                     '/opt/pw-browsers/chromium-1194/chrome-linux/chrome')
HTML = os.path.join(ROOT, 'build', 'book.html')
OUT = os.path.join(ROOT, 'build', 'Sunrise12-Companion.pdf')

TITLE = 'Sunrise 12'
AUTHOR = 'Falah H. Younis'

LEFT = 'Ibrahim Ahmad preparatory school'
CENTRE = 'پەیمانگای ژیر'
RIGHT = 'Shahid Aram preparatory school'

FOOTER = f'''
<div style="width:100%;padding:0 14mm;box-sizing:border-box;
            -webkit-print-color-adjust:exact;">
  <div style="border-top:.5pt solid #D8D2C8;padding-top:2.6mm;
              display:flex;align-items:center;justify-content:space-between;
              font-family:'Inter',system-ui,sans-serif;font-size:6.6pt;
              letter-spacing:.06em;color:#7A869F;">
    <span style="flex:1;text-align:left;">{LEFT}</span>
    <span style="flex:0 0 auto;padding:0 6mm;font-family:'Noto Kufi Arabic',serif;
                 font-size:7.4pt;color:#5F3FA8;direction:rtl;">{CENTRE}</span>
    <span style="flex:1;text-align:right;">{RIGHT}</span>
  </div>
</div>'''

HEADER = f'''
<div style="width:100%;padding:0 14mm;box-sizing:border-box;
            -webkit-print-color-adjust:exact;">
  <div style="border-bottom:.5pt solid #D8D2C8;padding-bottom:2.2mm;
              display:flex;align-items:baseline;justify-content:space-between;
              font-family:'Inter',system-ui,sans-serif;">
    <span style="font-size:7.4pt;font-weight:600;letter-spacing:.22em;
                 text-transform:uppercase;color:#12203A;">{TITLE}</span>
    <span style="font-size:7pt;letter-spacing:.05em;color:#7A869F;">{AUTHOR}</span>
  </div>
</div>'''

MARGIN = {'top': '18mm', 'bottom': '17mm', 'left': '14mm', 'right': '14mm'}


def render(page, path, running):
    """`running` turns the page header and footer on."""
    page.pdf(path=path, format='A4', print_background=True,
             display_header_footer=running,
             header_template=HEADER if running else '<div></div>',
             footer_template=FOOTER if running else '<div></div>',
             margin=MARGIN, prefer_css_page_size=True)


def find_bleed(doc):
    """Pages whose artwork runs to the paper edge.

    Cover, part dividers and unit openers all finish on a saturated band at
    the foot of the page; a text page has paper there. Sampling that strip
    identifies them without needing a marker in the text layer.
    """
    import pymupdf
    found = []
    for i in range(doc.page_count):
        r = doc[i].rect
        strip = pymupdf.Rect(r.width * .3, r.height - 4, r.width * .7, r.height - 1)
        px = doc[i].get_pixmap(clip=strip)
        n = px.width * px.height
        if not n:
            continue
        avg = [sum(px.pixel(x, y)[k] for y in range(px.height)
                   for x in range(px.width)) / n for k in range(3)]
        # Paper is near-white and near-neutral; artwork is neither.
        if min(avg) < 225 or (max(avg) - min(avg)) > 14:
            found.append(i)
    return found


def main():
    with sync_playwright() as p:
        b = p.chromium.launch(executable_path=EXE, args=['--no-sandbox'])
        pg = b.new_page()
        pg.goto('file://' + HTML, wait_until='load')
        pg.emulate_media(media='print')
        withf = os.path.join(ROOT, 'build', '_with.pdf')
        clean = os.path.join(ROOT, 'build', '_clean.pdf')
        render(pg, withf, True)
        render(pg, clean, False)
        b.close()

    import pymupdf
    a, c = pymupdf.open(withf), pymupdf.open(clean)
    if a.page_count != c.page_count:
        raise SystemExit(f'pagination differs: {a.page_count} vs {c.page_count}')

    bleed = set(find_bleed(c))

    # Copy in runs rather than page by page; inserting one page at a time
    # re-walks the whole source document each call.
    out = pymupdf.open()
    i = 0
    while i < a.page_count:
        j, is_b = i, i in bleed
        while j + 1 < a.page_count and ((j + 1) in bleed) == is_b:
            j += 1
        out.insert_pdf(c if is_b else a, from_page=i, to_page=j)
        i = j + 1
    # Merging by run copies each source's fonts and images once per run;
    # garbage=4 folds the duplicates back together.
    out.save(OUT, garbage=4, deflate=True, deflate_images=True,
             deflate_fonts=True, clean=True)
    print(f'{out.page_count} pages -> {OUT} '
          f'({len(bleed)} full-bleed pages left unmarked, '
          f'{os.path.getsize(OUT) / 1e6:.1f} MB)')
    for f in (withf, clean):
        os.remove(f)


if __name__ == '__main__':
    main()
