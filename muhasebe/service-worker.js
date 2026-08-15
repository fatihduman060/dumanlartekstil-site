const CACHE_NAME = 'dumanlar-muhasebe-shell-v1';
const STATIC_ASSETS = [
  './assets/muhasebe.css',
  './assets/mobil-panel.css',
  './assets/muhasebe.js',
  './assets/mobil-panel.js',
  './assets/dumanlar-logo-arkaplansiz.png',
  './assets/app-icon-512.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
    return cache.addAll(STATIC_ASSETS);
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (key) { return key !== CACHE_NAME; }).map(function (key) { return caches.delete(key); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') return;
  var url = new URL(event.request.url);
  if (url.origin !== self.location.origin || !/\.(?:css|js|png|svg)$/.test(url.pathname)) return;
  event.respondWith(fetch(event.request).then(function (response) {
    var copy = response.clone();
    caches.open(CACHE_NAME).then(function (cache) { cache.put(event.request, copy); });
    return response;
  }).catch(function () { return caches.match(event.request); }));
});
