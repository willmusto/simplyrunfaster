/**
 * SimplyRunFaster — Service Worker
 *
 * Cache strategy:
 *   - App shell (CSS, JS, icons): cache-first
 *   - Plan pages (/plan, /): network-first, fallback to cache
 *   - Everything else: network-first, fallback to offline page
 */

const CACHE_NAME    = 'srf-v2';
const OFFLINE_URL   = '/app/offline';

// Resources to pre-cache on install
const PRECACHE = [
    '/app',
    '/app/plan',
    '/app/offline',
    '/assets/css/app.css',
    '/assets/js/app.js',
    '/manifest.json',
];

// ── Install ───────────────────────────────────────────────────
self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return cache.addAll(PRECACHE);
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

// ── Activate ──────────────────────────────────────────────────
self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k !== CACHE_NAME; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

// ── Fetch ─────────────────────────────────────────────────────
self.addEventListener('fetch', function (event) {
    const req = event.request;

    // Non-GET: always network, no caching
    if (req.method !== 'GET') return;

    // Static assets: cache-first
    if (isStaticAsset(req.url)) {
        event.respondWith(cacheFirst(req));
        return;
    }

    // Plan pages and today view: network-first, cache fallback
    if (isPlanPage(req.url)) {
        event.respondWith(networkFirst(req, OFFLINE_URL));
        return;
    }

    // Everything else: network-first, offline page fallback
    event.respondWith(networkFirst(req, OFFLINE_URL));
});

// ── Push Notifications ────────────────────────────────────────
self.addEventListener('push', function (event) {
    if (!event.data) return;

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        data = { title: 'SimplyRunFaster', body: event.data.text() };
    }

    const options = {
        body:    data.body    || '',
        icon:    data.icon    || '/assets/icons/icon-192.png',
        badge:   data.badge   || '/assets/icons/icon-192.png',
        data:    data.url     || '/',
        tag:     data.tag     || 'srf-notification',
        renotify: true,
        actions: data.actions || [],
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'SimplyRunFaster', options)
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data || '/app';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (const client of list) {
                if (client.url === url && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});

// ── Helpers ───────────────────────────────────────────────────
function isStaticAsset(url) {
    return url.includes('/assets/') || url.endsWith('.css') || url.endsWith('.js')
        || url.endsWith('.png') || url.endsWith('.svg') || url.endsWith('.ico')
        || url.endsWith('.woff2');
}

function isPlanPage(url) {
    const path = new URL(url).pathname;
    return path === '/app' || path === '/app/' || path === '/app/plan' || path.startsWith('/app/log');
}

async function cacheFirst(req) {
    const cached = await caches.match(req);
    if (cached) return cached;
    try {
        const response = await fetch(req);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(req, response.clone());
        }
        return response;
    } catch (_) {
        return new Response('Offline', { status: 503 });
    }
}

async function networkFirst(req, fallbackUrl) {
    try {
        const response = await fetch(req);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(req, response.clone());
        }
        return response;
    } catch (_) {
        const cached = await caches.match(req);
        if (cached) return cached;
        if (fallbackUrl) {
            const fallback = await caches.match(fallbackUrl);
            if (fallback) return fallback;
        }
        return new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
    }
}
