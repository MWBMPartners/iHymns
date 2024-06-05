const CACHE_NAME = 'hymn-app-cache-v1';
const urlsToCache = [
  '/',
  '/css/styles.css',
  '/js/app.js',
  '/images/icons/icon-192x192.png',
  '/images/icons/icon-512x512.png',
  '/index.html',
  '/manifest.json'
];

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function(cache) {
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('fetch', function(event) {
  event.respondWith(
    caches.match(event.request)
      .then(function(response) {
        return response || fetch(event.request);
      })
  );
});
