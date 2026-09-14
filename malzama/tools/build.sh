#!/bin/sh
# Build both deliverables from src/malzama.docx.
set -e
cd "$(dirname "$0")"
CHROME=${CHROME:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}

python3 extract.py                     # .docx  -> build/doc.json
python3 gfx.py                         # full-page artwork -> build/gfx/*.png
python3 book.py                        # doc.json -> build/book.html
python3 topdf.py                       # book.html -> the print PDF, with footers
python3 docx_out.py                    # doc.json  -> build/Sunrise12-Companion.docx
echo "done: build/Sunrise12-Companion.{pdf,docx}"
