# -*- coding: utf-8 -*-
"""Ali-K (legacy Kurdish 8-bit font encoding) -> Unicode Kurdish (Sorani).

The source .docx stores Kurdish as Arabic codepoints that only render as
Kurdish when an Ali_K_* font is applied. Characters already in proper
Unicode Kurdish pass through untouched, so the document's mixed content
converts safely.
"""

FATHA, KASRA = 'َ', 'ِ'

# Digraphs (base letter + combining mark) must be replaced before singles.
DIGRAPHS = [
    ('ي' + FATHA, 'ێ'),  # ي + fatha -> ێ
    ('ل' + FATHA, 'ڵ'),  # ل + fatha -> ڵ
    ('ر' + KASRA, 'ڕ'),  # ر + kasra -> ڕ
    ('و' + FATHA, 'ۆ'),  # و + fatha -> ۆ (rare variant)
    ('ه' + FATHA, 'ھ'),  # ه + fatha -> ھ
]

SINGLES = {
    'ة': 'ە',  # ة -> ە
    'ي': 'ی',  # ي -> ی
    'ى': 'ی',  # ى -> ی
    'ك': 'ک',  # ك -> ک
    'ط': 'گ',  # ط -> گ
    'ث': 'پ',  # ث -> پ
    'ض': 'چ',  # ض -> چ
    'ظ': 'ڤ',  # ظ -> ڤ
    'ذ': 'ژ',  # ذ -> ژ
    'ؤ': 'ۆ',  # ؤ -> ۆ
}

# Leftover marks that carried no meaning once digraphs were resolved.
STRIP = {FATHA, KASRA, 'ً', 'ٌ', 'ٍ', 'ُ',
         'ّ', 'ْ', 'ـ'}


def convert(text: str) -> str:
    if not text:
        return text
    for src, dst in DIGRAPHS:
        text = text.replace(src, dst)
    out = []
    for ch in text:
        if ch in STRIP:
            continue
        out.append(SINGLES.get(ch, ch))
    return ''.join(out)


if __name__ == '__main__':
    samples = [
        'ثةروةردة ثاسثؤرتي داهاتووة,سبةي هي ئةو كةسانةية كة ئةمرِؤ خؤياني بؤ ئامادة دةكةن.',
        'خويَندكاري بةرِيَز ئةم مةلزةمةيةي لةثيَشت بةرهةمي ماندوبوني ضةندين سالَي مامؤستاية',
        'لةسةرةتاي هةر يونتيَك باسي هةموو بابةتة رِيَزمانييةكان كراوة بةكؤمةلَيَك تيَبيني طرنطةوة',
        'لةبةشي رِيدينط بابةتةكان بةدوو شيَوة باسكراوة',
        'لةبةشي ئيَثسةود بةهةمان شيَوةي رِيدينط',
        'ثيَداني رِيَنمايي',
        'بةكارهيَناني ئاوةلَكاري يةك لةدواي يةك(زنجيرةيي) لةطةلَ رِستةي رِيَنمايي',
        'هةلَبذاردنيش',
        'طرةنتي سةد نمرةت بؤ دةكات, بؤية بةبيَ دوودلَي',
    ]
    for s in samples:
        print(convert(s))
