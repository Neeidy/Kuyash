/* Kuyash live client (Phase 19). Subscribes to the /live SSE endpoint and keeps
   the topbar "Live · updated …" indicator (and the awaiting count, if shown)
   fresh. The endpoint is immediate-close, so EventSource reconnects every few
   seconds (its `retry` directive) — that reconnect IS the live tick.

   Graceful degradation: no EventSource → the static server render stands. The
   payload is a tiny JSON of integer counts + a timestamp; nothing is injected as
   HTML (textContent only). Vanilla JS, no framework. */
(function () {
  'use strict';
  if (typeof window.EventSource === 'undefined') return; /* no SSE → static page is fine */

  var live = document.querySelector('[data-live]');
  var liveText = live ? live.querySelector('[data-live-text]') : null;
  var awaiting = document.querySelector('[data-live-awaiting]');
  var es;

  function onSnapshot(e) {
    var data;
    try { data = JSON.parse(e.data); } catch (_) { return; }
    if (live) live.classList.add('is-live');
    if (liveText) liveText.textContent = liveText.getAttribute('data-live-updated') || liveText.textContent;
    if (awaiting && typeof data.awaiting === 'number') awaiting.textContent = String(data.awaiting);
  }

  function connect() {
    try { es = new EventSource('/live'); } catch (_) { return; }
    es.addEventListener('snapshot', onSnapshot);
    /* the server closes after one event → EventSource auto-reconnects; on a real
       failure we just drop the live styling and let it keep retrying */
    es.onerror = function () { if (live) live.classList.remove('is-live'); };
  }

  connect();
  window.addEventListener('beforeunload', function () { if (es) es.close(); });
})();
