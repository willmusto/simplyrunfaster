/**
 * SimplyRunFaster â€” Service Worker
 *
 * Cache strategy:
 *   - App shell (CSS, JS, icons): cache-first
 *   - Plan pages (/plan, /): network-first, fallback to cache
 *   - Everything else: network-first, fallback to offline page
 */

// â”€â”€ IMPORTANT: bump CACHE_NAME on every deploy â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// The activate event deletes all caches whose name differs from
// CACHE_NAME. If this string never changes, stale CSS/HTML stays
// cached indefinitely. Update to today's date (YYYYMMDD) before
// committing; the deploy checklist runs the cache-bump command.
const CACHE_NAME    = 'srf-20260702';
const OFFLINE_URL   = '/app/offline';

// Resources to pre-cache on install.
// CSS/JS are NOT listed here because they are now served with ?v=<filemtime>
// query strings. SW cache matching does not ignore query strings by default,
// so pre-caching the un-versioned URL would be useless (the versioned request
// would miss and fall through to network anyway). They are instead cached
// lazily by the cacheFirst handler on the first fetch, keyed by versioned URL.
//
// Only auth-independent resources are pre-cached. Personalized pages (/app,
// /app/plan, …) are intentionally NOT pre-cached: fetching them at install
// time stores a redirect/403/logged-out artifact (the install request reflects
// whatever role/session happens to be active), which then gets re-served as a
// stale "logged out" page after a deploy. They are cached lazily on a real
// visit by networkFirst instead.
const PRECACHE = [
    '/app/offline',
    '/manifest.json',
];

// True only on this service worker's very FIRST install for the scope (no prior
// active worker). Used to gate clients.claim() in activate: we claim a fresh
// first load so Chrome marks the page installable (WebAPK), but we never claim
// on an update/deploy, which would swap the controller of an already-open
// authenticated page mid-session (see the activate comment below).
let isFirstInstall = false;

// â”€â”€ Install â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
self.addEventListener('install', function (event) {
    // On a first install there is no already-active worker; on an update,
    // self.registration.active is the outgoing worker. Read it now (before
    // skipWaiting) so activate can decide whether claiming is safe.
    isFirstInstall = !self.registration.active;
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            // Use {cache: 'reload'} so each request bypasses the browser's HTTP
            // cache — without this, immutable-cached CSS/JS can be pre-cached
            // stale even after a CACHE_NAME bump.
            return Promise.all(
                PRECACHE.map(function (url) {
                    return cache.add(new Request(url, { cache: 'reload' }));
                })
            );
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

// â”€â”€ Activate â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
self.addEventListener('activate', function (event) {
    // Delete stale caches, then claim clients ONLY on the very first install.
    //
    // On an UPDATE (a deploy) we deliberately do NOT call self.clients.claim().
    // Claiming would swap the controller of an already-open, authenticated page
    // mid-session the instant a deploy lands; paired with the cache flush below
    // that produced stale "logged-out" navigations. Without claim on update,
    // open pages keep their current controller until the next natural
    // navigation/relaunch, and the active session is never interrupted.
    // skipWaiting() (in install) still makes the new SW take over on next launch.
    //
    // On a FIRST install there is no prior controller and no established session
    // to disrupt, so claiming is safe AND necessary: a fresh first load is
    // otherwise uncontrolled until the next navigation, which makes Chrome
    // withhold "Install app" (WebAPK) and offer only "Add to Home Screen".
    // Claiming brings that first page under control so Chrome marks it installable.
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k !== CACHE_NAME; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () {
            if (isFirstInstall) {
                return self.clients.claim();
            }
        })
    );
});

// â”€â”€ Message â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
self.addEventListener('message', function (event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// â”€â”€ Fetch â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
self.addEventListener('fetch', function (event) {
    const req = event.request;

    // Non-GET: always network, no caching
    if (req.method !== 'GET') return;

    // Auth / session-dispatch pages (login is the PWA start_url and 302-redirects
    // by role when authenticated): always network-only. A cached copy would show a
    // stale logged-out login screen — or a wrong-role page — to a user whose
    // session cookie is still valid, which looks like being logged out after a
    // deploy. Never read these from, or write them to, the cache.
    if (isAuthPage(req.url)) {
        event.respondWith(networkOnly(req));
        return;
    }

    // Static assets: cache-first
    if (isStaticAsset(req.url)) {
        event.respondWith(cacheFirst(req));
        return;
    }

    // Everything else (app pages): network-first, offline page fallback
    event.respondWith(networkFirst(req, OFFLINE_URL));
});

// â”€â”€ Push Notifications â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
self.addEventListener('push', function (event) {
    if (!event.data) return;

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        data = { title: 'SimplyRunFaster', body: event.data.text() };
    }

    // Always-on (high-value) types stay on screen until the user taps them, which
    // also signals to Chrome that these notifications are intentional, not spam.
    const ALWAYS_ON = ['plan_approved', 'critical_flag', 'message_from_coach', 'message_from_athlete'];

    const options = {
        body:    data.body    || '',
        icon:    '/assets/icons/icon-192.png',
        badge:   '/assets/icons/badge-96.png',
        data:    data.url     || '/',
        // Tag by notification type so Chrome groups/dedupes related notifications;
        // renotify makes a new one of the same type replace + re-alert (not silently drop).
        tag:      data.type   || 'srf-notification',
        renotify: true,
        vibrate:  [200, 100, 200],
        actions:  data.actions || [],
    };

    if (data.type && ALWAYS_ON.indexOf(data.type) !== -1) {
        options.requireInteraction = true;
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'SimplyRunFaster', options)
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data || '/app';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            // Focus an already-open app window and navigate it to the target.
            for (const client of list) {
                if (client.url.indexOf('/app') !== -1 && 'focus' in client) {
                    return client.focus().then(function (c) {
                        return (c && 'navigate' in c) ? c.navigate(url) : c;
                    });
                }
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});

// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function isStaticAsset(url) {
    return url.includes('/assets/') || url.endsWith('.css') || url.endsWith('.js')
        || url.endsWith('.png') || url.endsWith('.svg') || url.endsWith('.ico')
        || url.endsWith('.woff2');
}

function isAuthPage(url) {
    const path = new URL(url).pathname;
    return path === '/app/login'
        || path === '/app/register'
        || path === '/app/logout'
        || path === '/app/forgot-password'
        || path === '/app/reset-password'
        || path.startsWith('/app/invite/');
}

async function networkOnly(req) {
    try {
        return await fetch(req);
    } catch (_) {
        const offline = await caches.match(OFFLINE_URL);
        return offline || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
    }
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
        // Only cache final, non-redirected successes. A redirected response is
        // the result of following a 302 (e.g. an auth/role redirect) — storing
        // it under the originally-requested URL would re-serve a stale
        // logged-out/wrong-role page after a deploy.
        if (response.ok && !response.redirected) {
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
