const CACHE_NAME = 'meta-data-platforms-v3';
const ASSETS = [
    '/',
    '/assets/css/styles.css',
    '/assets/js/app.js',
    '/assets/images/market-og.svg',
    '/assets/images/products/google-maps-api-platform.svg',
    '/assets/images/products/ip-geolocation-api-pro-tier.svg',
    '/assets/images/products/mnotify-sms-gateway-prepaid-bulk.svg',
    '/assets/images/products/openai-ecosystem.svg',
    '/assets/images/products/gemini-ecosystem.svg',
    '/assets/images/products/leaflet-mapbox-vector-tiles.svg',
    '/assets/images/products/advanced-charting-engine-commercial.svg',
    '/assets/images/products/sentry-error-observability-suite.svg',
    '/assets/images/products/posthog-product-analytics-cloud.svg'
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const requestUrl = new URL(event.request.url);

    if (requestUrl.origin !== self.location.origin || requestUrl.pathname.startsWith('/api/') || event.request.method !== 'GET') {
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
            if (response.ok && ['style', 'script', 'image'].includes(event.request.destination)) {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
            }
            return response;
        }))
    );
});
