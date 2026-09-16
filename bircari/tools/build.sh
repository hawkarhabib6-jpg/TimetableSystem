#!/bin/sh
# Author -> HTML -> KaTeX pass -> PDF
set -e
cd "$(dirname "$0")"
CHROME=${CHROME:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}
python3 make.py
node render_math.js ../build/book.html
"$CHROME" --headless --no-sandbox --disable-gpu --no-pdf-header-footer \
  --print-to-pdf=../build/Bircari-Trigonometry.pdf ../build/book.html 2>/dev/null
echo "done: build/Bircari-Trigonometry.pdf"
