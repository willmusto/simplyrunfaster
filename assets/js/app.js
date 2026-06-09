/**
 * SimplyRunFaster — App JS
 * Theme management, PWA utilities, offline detection
 */

(function () {
    'use strict';

    // ── Offline detection ────────────────────────────────────
    function updateOnlineStatus() {
        document.body.classList.toggle('is-offline', !navigator.onLine);
    }
    window.addEventListener('online',  updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    updateOnlineStatus();

    // ── Service Worker registration ──────────────────────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').then(function (reg) {
                console.log('[SRF] Service worker registered', reg.scope);
            }).catch(function (err) {
                console.warn('[SRF] Service worker registration failed', err);
            });
        });
    }

    // ── PWA push subscription ────────────────────────────────
    window.SRF = window.SRF || {};

    window.SRF.subscribePush = async function (vapidPublicKey) {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        try {
            const reg  = await navigator.serviceWorker.ready;
            const sub  = await reg.pushManager.subscribe({
                userVisibleOnly:      true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });
            await fetch('/push/subscribe', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrf() },
                body:    JSON.stringify(sub.toJSON()),
            });
        } catch (e) {
            console.warn('[SRF] Push subscription failed', e);
        }
    };

    // ── Day-picker interactivity ─────────────────────────────
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.day-btn[data-day]');
        if (!btn) return;

        const group = btn.closest('.day-picker');
        const input = group ? group.querySelector('input[type="hidden"]') : null;
        if (!input) return;

        btn.classList.toggle('selected');

        const selected = Array.from(group.querySelectorAll('.day-btn.selected'))
            .map(b => b.dataset.day);
        input.value = JSON.stringify(selected);
    });

    // ── Goal-path card selection ─────────────────────────────
    document.addEventListener('click', function (e) {
        const card = e.target.closest('.goal-card');
        if (!card) return;

        const group = card.closest('.goal-card-group');
        if (group) {
            group.querySelectorAll('.goal-card').forEach(c => c.classList.remove('selected'));
        }

        card.classList.add('selected');
        const radio = card.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    });

    // ── Pill-choice selection ────────────────────────────────
    document.addEventListener('change', function (e) {
        const input = e.target;
        if (!input.matches('.pill-choice input')) return;

        const group = input.closest('.pill-choices');
        if (!group) return;

        if (input.type === 'radio') {
            group.querySelectorAll('.pill-choice').forEach(p => p.classList.remove('selected'));
        }
        input.closest('.pill-choice').classList.toggle('selected', input.checked);
    });

    // ── Auto-dismiss flash messages ──────────────────────────
    document.querySelectorAll('.flash').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity 0.4s';
            el.style.opacity    = '0';
            setTimeout(function () { el.remove(); }, 400);
        }, 4000);
    });

    // ── Helpers ──────────────────────────────────────────────
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw     = window.atob(base64);
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }

    function getCsrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }
})();
