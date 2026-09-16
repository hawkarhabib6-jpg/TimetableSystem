// Pre-render every $…$ / $$…$$ span to KaTeX HTML, so the printed page needs
// no script of its own and nothing depends on load timing.
const katex = require('../vendor/package/dist/katex.js');
const fs = require('fs');

const file = process.argv[2];
let html = fs.readFileSync(file, 'utf8');

function render(tex, display) {
  try {
    return katex.renderToString(tex, {
      displayMode: display, throwOnError: false, strict: false,
      trust: true, output: 'html',
    });
  } catch (e) {
    return '<span class="math-error">' + tex + '</span>';
  }
}

html = html.replace(/<span class="math-(inline|block)">([\s\S]*?)<\/span>/g,
  (_, kind, body) => {
    const tex = body.replace(/&lt;/g, '<').replace(/&gt;/g, '>')
                    .replace(/&amp;/g, '&');
    return '<span class="k-' + kind + '">' +
           render(tex, kind === 'block') + '</span>';
  });

fs.writeFileSync(file, html);
console.log('math rendered');
