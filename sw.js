/* Service worker: deixa a interface instalável e rápida. Áudio e API nunca são cacheados. */
const CACHE = 'sonora-v1';
const SHELL = ['./', 'assets/app.css', 'assets/app.js', 'assets/icon.svg', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  if (/\/(api|stream|install)\.php/.test(url.pathname)) return;
  // rede primeiro, cache como reserva (offline)
  e.respondWith(
    fetch(e.request).then((r) => {
      const copy = r.clone();
      if (r.ok) caches.open(CACHE).then((c) => c.put(e.request, copy));
      return r;
    }).catch(() => caches.match(e.request))
  );
});
