/*
 * سرور محلیِ اندازه‌گیری — نزدیک به آنچه تولید دارد، فقط برای مقایسهٔ «قبل/بعد» روی همین ماشین.
 *
 *   node perf/tools/perf-server.mjs [port=8099] [backend=http://127.0.0.1:8090] [docroot=/home/user/wp/core] [snapshot.html]
 *
 * - فایل‌های ایستا (CSS، JS، فونت، تصویر) از docroot با gzip (اگر مرورگر بپذیرد) و Cache-Control، مثل سرور تولید.
 *   سرور داخلی PHP ایستا را بی فشرده‌سازی می‌دهد و اندازهٔ انتقال را بیش از واقعیت نشان می‌دهد.
 * - «/» بی پارامتر: اگر snapshot داده شده، همان HTML (مثل کش صفحهٔ WP Rocket: TTFB کوتاه، بی اجرای PHP).
 * - هر چیز دیگر به وردپرس (backend) فرستاده می‌شود.
 * - DELAY="substring=ms,…" (اختیاری، فقط برای آزمایش زمان‌بندی): هر فایل ایستایی که نشانی‌اش آن زیررشته را دارد با آن
 *   تأخیر فرستاده می‌شود — مثلاً برای اینکه فونتی دیرتر از پایان انیمیشن ورود برسد، مثل شبکهٔ واقعی Production.
 * هیچ چیزی در وردپرس یا دیتابیس نمی‌نویسد.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';

const PORT = Number(process.argv[2] || 8099);
const BACKEND = new URL(process.argv[3] || 'http://127.0.0.1:8090');
const ROOT = process.argv[4] || '/home/user/wp/core';
const SNAPSHOT = process.argv[5] || '';
const TYPES = { '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.webp': 'image/webp', '.svg': 'image/svg+xml', '.gif': 'image/gif', '.ico': 'image/x-icon' };
const COMPRESS = new Set(['.css', '.js', '.svg', '.ttf']);
const DELAYS = (process.env.DELAY || '').split(',').filter(Boolean).map((d) => { const i = d.lastIndexOf('='); return [d.slice(0, i), Number(d.slice(i + 1))]; });
const delayFor = (url) => (DELAYS.find(([s]) => url.includes(s)) || [null, 0])[1];

function send(req, res, status, type, body, extra = {}) {
  const gz = /\bgzip\b/.test(req.headers['accept-encoding'] || '') && (type.startsWith('text/') || type.includes('svg') || type === 'font/ttf');
  const out = gz ? zlib.gzipSync(body, { level: 6 }) : body;
  res.writeHead(status, { 'Content-Type': type, 'Content-Length': out.length, ...(gz ? { 'Content-Encoding': 'gzip', Vary: 'Accept-Encoding' } : {}), ...extra });
  res.end(req.method === 'HEAD' ? undefined : out);
}

http.createServer((req, res) => {
  const u = new URL(req.url, `http://127.0.0.1:${PORT}`);
  const file = path.join(ROOT, decodeURIComponent(u.pathname));
  if (SNAPSHOT && u.pathname === '/' && u.search === '' && req.method === 'GET') {
    return send(req, res, 200, 'text/html; charset=UTF-8', fs.readFileSync(SNAPSHOT), { 'Cache-Control': 'no-cache' });
  }
  const ext = path.extname(u.pathname).toLowerCase();
  if (file.startsWith(ROOT) && TYPES[ext] && ext !== '.php' && fs.existsSync(file) && fs.statSync(file).isFile()) {
    const body = fs.readFileSync(file), ms = delayFor(req.url);
    if (ms > 0) return void setTimeout(() => send(req, res, 200, TYPES[ext], body, { 'Cache-Control': 'public, max-age=31536000' }), ms);
    return send(req, res, 200, TYPES[ext], body, { 'Cache-Control': 'public, max-age=31536000' });
  }
  // به وردپرس؛ پاسخ بی تغییر، فقط با gzip برای HTML.
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    const p = http.request({ host: BACKEND.hostname, port: BACKEND.port, method: req.method, path: req.url, headers: { ...req.headers, host: BACKEND.host, 'accept-encoding': 'identity' } }, (r) => {
      const body = [];
      r.on('data', (c) => body.push(c));
      r.on('end', () => {
        const buf = Buffer.concat(body);
        const headers = { ...r.headers };
        delete headers['content-length'];
        delete headers['transfer-encoding'];
        const type = String(r.headers['content-type'] || '');
        if (/text\/html|json|javascript|css/.test(type) && /\bgzip\b/.test(req.headers['accept-encoding'] || '')) {
          const gz = zlib.gzipSync(buf);
          res.writeHead(r.statusCode, { ...headers, 'Content-Encoding': 'gzip', 'Content-Length': gz.length, Vary: 'Accept-Encoding' });
          return res.end(gz);
        }
        res.writeHead(r.statusCode, { ...headers, 'Content-Length': buf.length });
        res.end(buf);
      });
    });
    p.on('error', (e) => { res.writeHead(502); res.end(String(e)); });
    p.end(Buffer.concat(chunks));
  });
}).listen(PORT, '127.0.0.1', () => console.log(`perf-server on ${PORT} → ${BACKEND.href}${SNAPSHOT ? ' (snapshot for /)' : ''}`));
