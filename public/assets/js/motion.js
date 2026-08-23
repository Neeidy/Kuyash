/* Kuyash motion core (Phase 16) — PL namespace, sliding-pill nav, KPI count-up.
   Pure progressive enhancement. All timing reads --dur-* tokens, so
   prefers-reduced-motion (which zeroes those tokens) turns everything instant.
   Vanilla JS, no framework, no build step. */
(function () {
  'use strict';
  var root = document.documentElement;
  var PL = (window.PL = window.PL || {});

  /* read a CSS --duration token in ms (handles "250ms" / "1s" / "0ms") */
  function durOf(name) {
    var v = getComputedStyle(root).getPropertyValue(name).trim();
    if (!v) return 0;
    if (v.indexOf('ms') > -1) return parseFloat(v);
    if (v.indexOf('s') > -1) return parseFloat(v) * 1000;
    return parseFloat(v) || 0;
  }
  function reduced() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }
  PL.motion = { durOf: durOf, reduced: reduced };

  /* ---- sliding-pill sidebar nav: a single pill slides under the hovered item
     and rests on the active one. No-JS keeps the static marker/highlight. ---- */
  (function pillNav() {
    var nav = document.querySelector('.sidebar__nav');
    if (!nav) return;
    var items = Array.prototype.slice.call(nav.querySelectorAll('.nav-item'));
    if (!items.length) return;
    function activeItem() { return nav.querySelector('.nav-item.is-active') || items[0]; }

    var pill = document.createElement('span');
    pill.className = 'nav-item__pill';
    pill.setAttribute('aria-hidden', 'true');
    nav.appendChild(pill);

    function moveTo(el) {
      if (!el) return;
      pill.style.height = el.offsetHeight + 'px';
      pill.style.transform = 'translateY(' + el.offsetTop + 'px)';
    }
    /* Place the pill on the active item BEFORE transitions are armed. On a
       multi-page app every click is a document load, so this startup placement
       runs on every screen — if a transform transition were already active the
       pill would slide down from the top each time (the reported "rebound").
       Reading offsetHeight forces a style/layout flush, committing the position
       as the resting value; .is-ready then arms the transition for hover.

       Done synchronously, NOT in requestAnimationFrame: rAF is suspended while a
       tab is hidden, so a page opened in a background tab never reached .is-ready
       and the pill stayed at opacity 0 — the highlight was simply missing until
       the tab was focused. Nothing here needs to wait for a frame. */
    moveTo(activeItem());
    void pill.offsetHeight;
    pill.classList.add('is-ready');

    items.forEach(function (a) {
      a.addEventListener('mouseenter', function () { moveTo(a); });
      a.addEventListener('focus', function () { moveTo(a); });
    });
    nav.addEventListener('mouseleave', function () { moveTo(activeItem()); });
    window.addEventListener('resize', function () { moveTo(activeItem()); });
  })();

  /* ---- KPI count-up, once, rAF eased. Two modes:
       • data-count="48.50" data-count-prefix="$" data-count-decimals="2" → money/explicit
       • bare integer text content → auto (skips %/decimals/non-numeric)
     reduced-motion or no-JS leaves the real server-rendered value untouched. ---- */
  (function countUp() {
    var nums = Array.prototype.slice.call(document.querySelectorAll('.kpi__num'));
    if (!nums.length) return;
    var dur = durOf('--dur-count');
    if (reduced() || dur <= 0) return;
    nums.forEach(function (el) {
      var to, dec, prefix, suffix;
      if (el.hasAttribute('data-count')) {
        to = parseFloat(el.getAttribute('data-count'));
        if (isNaN(to)) return;
        dec = parseInt(el.getAttribute('data-count-decimals') || '0', 10) || 0;
        prefix = el.getAttribute('data-count-prefix') || '';
        suffix = el.getAttribute('data-count-suffix') || '';
      } else {
        var raw = (el.textContent || '').trim();
        if (!/^\d{1,9}$/.test(raw)) return;
        to = parseInt(raw, 10); dec = 0; prefix = ''; suffix = '';
      }
      if (to <= 0) return;
      var start = null;
      el.textContent = prefix + (0).toFixed(dec) + suffix;
      function step(t) {
        if (start === null) start = t;
        var p = Math.min((t - start) / dur, 1);
        var e = 1 - Math.pow(1 - p, 3);
        var v = (p < 1) ? (to * e) : to;
        el.textContent = prefix + v.toFixed(dec) + suffix;
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  })();

  /* ---- live countdown to the next scheduled publish (Phase 23).
     The server already rendered a correct phrase, so this only keeps it fresh
     while the page stays open — with JS off the static phrase stands. Ticks once
     a minute: the copy is minute-grained, so a per-second timer would burn
     wakeups to redraw the same words. ---- */
  (function countdown() {
    var nodes = document.querySelectorAll('[data-countdown]');
    if (!nodes.length) return;

    /* Wording comes from the element's own data-* attributes, which the server
       filled from the language files — so the countdown speaks the user's
       language without duplicating any string in JS. */
    function phrase(el, deltaMs) {
      var s = Math.floor(deltaMs / 1000);
      var pick = function (name, n) {
        return (el.getAttribute('data-t-' + name) || '').replace('{n}', n);
      };
      if (s < 60) return el.getAttribute('data-t-imminent') || '';
      if (s < 3600) return pick('minutes', Math.floor(s / 60));
      if (s < 86400) return pick('hours', Math.floor(s / 3600));
      return pick('days', Math.floor(s / 86400));
    }

    function tick() {
      var now = Date.now();
      Array.prototype.forEach.call(nodes, function (el) {
        var at = Date.parse(el.getAttribute('data-countdown'));
        var text = isNaN(at) ? '' : phrase(el, Math.max(0, at - now));
        if (text) el.textContent = text;
      });
    }
    tick();
    setInterval(tick, 60000);
  })();
})();
