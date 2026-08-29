'use strict';

const VERSION = 'memi-pwa-v1.2.0';
const CORE = [
  '/app/',
  '/app/index.html',
  '/app/offline.html',
  '/app/manifest.webmanifest',
  '/app/assets/app.css?v=1.2.0',
  '/app/assets/vendor/qrcode.min.js?v=1.0.0',
  '/app/assets/app.js?v=1.2.0',
  '/app/assets/icons/icon-192.png',
  '/app/assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/app/api/')) return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).then((response) => {
      const copy = response.clone();
      if (response.ok && url.pathname.startsWith('/app/')) caches.open(VERSION).then((cache) => cache.put(request, copy));
      return response;
    }).catch(async () => (await caches.match(request)) || (await caches.match('/app/offline.html'))));
    return;
  }

  if (url.pathname.startsWith('/app/assets/') || url.pathname.endsWith('.webmanifest')) {
    event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok) caches.open(VERSION).then((cache) => cache.put(request, response.clone()));
      return response;
    })));
  }
});

self.addEventListener('push', (event) => {
  let data = { title: 'Memi Studio', body: 'Une nouvelle vous attend.', url: '/app/' };
  try { if (event.data) data = { ...data, ...event.data.json() }; } catch { data.body = event.data?.text() || data.body; }
  event.waitUntil(self.registration.showNotification(data.title, {
    body: data.body,
    icon: data.icon || '/app/assets/icons/icon-192.png',
    badge: data.badge || '/app/assets/icons/badge-96.png',
    tag: data.eventId ? `memi-event-${data.eventId}` : 'memi-news',
    renotify: Boolean(data.eventId),
    data: { url: data.url || '/app/' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || '/app/', self.location.origin).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clients) => {
    for (const client of clients) {
      if (new URL(client.url).origin === self.location.origin) {
        await client.navigate(target);
        return client.focus();
      }
    }
    return self.clients.openWindow(target);
  }));
});

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});
