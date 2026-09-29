const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const types = {'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.woff2':'font/woff2','.avif':'image/avif','.webp':'image/webp','.png':'image/png'};
http.createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1:8850');
  const file = path.resolve(root, '.' + (url.pathname === '/' ? '/index.html' : url.pathname));
  if (!file.startsWith(root + path.sep)) { response.writeHead(403).end(); return; }
  fs.stat(file, (error, stats) => {
    if (error || !stats.isFile()) { response.writeHead(404).end(); return; }
    response.writeHead(200, {'Content-Type': types[path.extname(file)] || 'application/octet-stream'});
    fs.createReadStream(file).pipe(response);
  });
}).listen(8850, '127.0.0.1', () => process.stdout.write('ready\n'));
