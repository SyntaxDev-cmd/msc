/*
 * Service worker:
 *  - App instalável e abre sem internet (interface em cache, rede primeiro)
 *  - Músicas salvas para offline ficam no cache "sonora-media-v1" (gravadas pelo app)
 *    e são servidas daqui com suporte a Range — dá para avançar/voltar a música sem internet.
 */
const SHELL = 'sonora-shell-v5';
const MEDIA = 'sonora-media-v1';
const SHELL_FILES = ['./', 'assets/app.css', 'assets/app.js', 'assets/admin.js', 'assets/icon.svg'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(SHELL).then((c) => c.addAll(SHELL_FILES)).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== SHELL && k !== MEDIA).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/** Monta uma resposta 206 a partir do arquivo inteiro em cache */
async function rangeResponse(cached, rangeHeader) {
  const buf = await cached.arrayBuffer();
  const size = buf.byteLength;
  const m = /bytes=(\d*)-(\d*)/.exec(rangeHeader || '');
  let start = 0, end = size - 1;
  if (m) {
    if (m[1] === '' && m[2] !== '') { start = Math.max(0, size - Number(m[2])); }
    else { start = Number(m[1] || 0); if (m[2] !== '') end = Math.min(Number(m[2]), size - 1); }
  }
  if (start >= size || start > end) {
    return new Response(null, { status: 416, headers: { 'Content-Range': `bytes */${size}` } });
  }
  return new Response(buf.slice(start, end + 1), {
    status: 206,
    headers: {
      'Content-Type': cached.headers.get('Content-Type') || 'audio/mpeg',
      'Content-Range': `bytes ${start}-${end}/${size}`,
      'Content-Length': String(end - start + 1),
      'Accept-Ranges': 'bytes',
    },
  });
}

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;

  // Mídia: se foi salva para offline, serve do aparelho (economiza dados também)
  if (url.pathname.endsWith('/stream.php') && !url.searchParams.has('download')) {
    e.respondWith((async () => {
      const key = new URL(`stream.php?id=${url.searchParams.get('id')}${url.searchParams.has('cover') ? '&cover=1' : ''}`, self.registration.scope).href;
      const cache = await caches.open(MEDIA);
      const hit = await cache.match(key);
      if (!hit) return fetch(req);
      const range = req.headers.get('range');
      return range ? rangeResponse(hit, range) : hit;
    })());
    return;
  }

  // API e páginas administrativas: sempre rede
  if (/\/(api|install|brand|manifest)\.php/.test(url.pathname)) return;

  // Interface: rede primeiro, cache como reserva (abre offline)
  e.respondWith(
    fetch(req).then((r) => {
      if (r.ok && (url.pathname.endsWith('/') || url.pathname.endsWith('index.php') || url.pathname.includes('/assets/'))) {
        const copy = r.clone();
        caches.open(SHELL).then((c) => c.put(url.pathname.includes('/assets/') ? req : './', copy));
      }
      return r;
    }).catch(async () => (await caches.match(req, { ignoreSearch: true })) || caches.match('./'))
  );
});
