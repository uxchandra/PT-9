

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Session keep-alive — pings the server every few minutes, but ONLY when the
// user has actually interacted with the page since the last ping. A user who
// really walks away/leaves a tab idle still gets logged out on schedule
// (SESSION_LIFETIME is unchanged and stays short on purpose); this just stops
// a genuinely active user — e.g. mid-way through filling a long form — from
// hitting a 419 "Page Expired" the moment they finally hit submit, because
// their session's expiry keeps getting pushed forward while they work.
(function () {
    const authMeta = document.querySelector('meta[name="user-authenticated"]');
    if (!authMeta || authMeta.content !== '1') return;

    const PING_INTERVAL_MS = 5 * 60 * 1000; // well under SESSION_LIFETIME
    let activeSinceLastPing = false;

    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach((evt) => {
        window.addEventListener(evt, () => { activeSinceLastPing = true; }, { passive: true });
    });

    setInterval(() => {
        if (!activeSinceLastPing) return;
        activeSinceLastPing = false;
        fetch('/keep-alive', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .catch(() => { /* network hiccup — next tick tries again */ });
    }, PING_INTERVAL_MS);
})();
