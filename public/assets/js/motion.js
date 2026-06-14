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
    moveTo(activeItem());
    requestAnimationFrame(function () { pill.classList.add('is-ready'); });

    items.forEach(function (a) {
      a.addEventListener('mouseenter', function () { moveTo(a); });
      a.addEventListener('focus', function () { moveTo(a); });
    });
    nav.addEventListener('mouseleave', function () { moveTo(activeItem()); });
    window.addEventListener('resize', function () { moveTo(activeItem()); });
  })();

  /* ---- KPI count-up: integer .kpi__num only, once, rAF eased.
     Skips money / % / decimals; reduced-motion or no-JS shows the real value. ---- */
  (function countUp() {
    var nums = Array.prototype.slice.call(document.querySelectorAll('.kpi__num'));
    if (!nums.length) return;
    var dur = durOf('--dur-count');
    if (reduced() || dur <= 0) return; /* leave the server-rendered numbers as-is */
    nums.forEach(function (el) {
      var raw = (el.textContent || '').trim();
      if (!/^\d{1,9}$/.test(raw)) return;
      var to = parseInt(raw, 10);
      if (to <= 0) return;
      var start = null;
      el.textContent = '0';
      function step(t) {
        if (start === null) start = t;
        var p = Math.min((t - start) / dur, 1);
        var e = 1 - Math.pow(1 - p, 3);
        el.textContent = (p < 1) ? Math.round(to * e).toString() : to.toString();
        if (p < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  })();
})();
