const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const root = path.resolve(__dirname, '..');
const sourcePath = path.join(root, 'index.html');
const outputPath = path.join(root, 'deploy', 'production', 'index.html');
const pagesDirective = '<meta name="robots" content="noindex,nofollow">';
const directiveLine = /^  <meta name="robots" content="noindex,nofollow">\r?\n/m;
const robotsTag = /<meta\b[^>]*\bname\s*=\s*["']robots["'][^>]*>/gi;

function buildProductionHtml(source) {
  const tags = [...source.matchAll(robotsTag)];
  if (tags.length !== 1 || tags[0][0] !== pagesDirective || !directiveLine.test(source)) {
    throw new Error('Expected exactly one unchanged GitHub Pages noindex meta tag');
  }

  const production = source.replace(directiveLine, '');
  if (robotsTag.test(production)) {
    throw new Error('Production HTML still contains a robots meta tag');
  }
  return production;
}

function main() {
  const source = fs.readFileSync(sourcePath, 'utf8');
  const production = buildProductionHtml(source);
  fs.mkdirSync(path.dirname(outputPath), { recursive: true });
  fs.writeFileSync(outputPath, production, 'utf8');
  const sha256 = crypto.createHash('sha256').update(production).digest('hex');
  process.stdout.write(`Prepared ${outputPath}\nSHA-256 ${sha256}\n`);
}

if (require.main === module) main();

module.exports = { buildProductionHtml };
