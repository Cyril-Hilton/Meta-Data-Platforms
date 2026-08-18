const CACHE_NAME = 'meta-data-platforms-v12';
const ASSETS = [
    '/assets/css/styles.css',
    '/assets/js/app.js',
    '/assets/images/market-og.svg',
    '/assets/images/product-logos/google-maps-api-platform.svg',
    '/assets/images/product-logos/ip-geolocation-api-pro-tier.png',
    '/assets/images/product-logos/mnotify-sms-gateway-prepaid-bulk.png',
    '/assets/images/product-logos/openai-ecosystem.png',
    '/assets/images/product-logos/gemini-ecosystem.svg',
    '/assets/images/product-logos/leaflet-mapbox-vector-tiles.svg',
    '/assets/images/product-logos/advanced-charting-engine-commercial.svg',
    '/assets/images/product-logos/sentry-error-observability-suite.svg',
    '/assets/images/product-logos/posthog-product-analytics-cloud.svg'
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

    if (event.request.mode === 'navigate' || ['document', 'script', 'style'].includes(event.request.destination)) {
        event.respondWith(
            fetch(event.request).then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            }).catch(() => caches.match(event.request))
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request).then((response) => {
            if (response.ok && ['image'].includes(event.request.destination)) {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
            }
            return response;
        }))
    );
});
