/* Kuyash Phase 0 — motion helpers: View Transitions wrapper, count-up, FLIP, stagger.
   All progressively enhanced; every path has a no-animation fallback. */
(function () {
  'use strict';
  var PL = window.Kuyash;

  function reduced() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* read a --dur-* token in ms so JS timings follow the CSS tokens
     (reduced-motion zeroes the tokens → zeroes these too) */
  function durOf(name, fallback) {
    var raw = getComputedStyle(document.documentElement).getPropertyValue('--dur-' + name);
    var ms = parseFloat(raw);
    if (isNaN(ms)) return fallback || 0;
    return raw.indexOf('ms') === -1 && raw.indexOf('s') !== -1 ? ms * 1000 : ms;
  }
  var EASE = 'cubic-bezier(0.22,1,0.36,1)'; /* = --ease-out */

  PL.motion = {
    reduced: reduced,
    durOf: durOf,

    /* Screen morph: View Transitions API with class-swap fade fallback.
       Skipped transitions reject their promises — swallow them (not errors). */
    vt: function (update, fallbackEl) {
      if (!reduced() && document.startViewTransition) {
        var vtRef = document.startViewTransition(update);
        if (vtRef && vtRef.ready && vtRef.ready.catch) vtRef.ready.catch(function () {});
        if (vtRef && vtRef.finished && vtRef.finished.catch) vtRef.finished.catch(function () {});
        return;
      }
      update();
      if (!reduced() && fallbackEl) {
        fallbackEl.classList.remove('vt-fallback');
        void fallbackEl.offsetWidth; /* restart the animation */
        fallbackEl.classList.add('vt-fallback');
      }
    },

    /* Count-up: rAF ease-out interpolation; tabular numerals prevent jitter.
       Duration follows the --dur-count token (0 under reduced motion). */
    countUp: function (el, to, format) {
      var fmt = format || function (v) { return String(Math.round(v)); };
      var from = parseFloat(String(el.dataset.countVal != null ? el.dataset.countVal : el.textContent).replace(/[^\d.-]/g, '')) || 0;
      el.dataset.countVal = to;
      var dur = durOf('count', 1000);
      if (reduced() || dur === 0 || from === to) { el.textContent = fmt(to); return; }
      var t0 = performance.now();
      function tick(now) {
        var p = Math.min(1, (now - t0) / dur);
        var e = 1 - Math.pow(1 - p, 3); /* ease-out cubic */
        el.textContent = fmt(from + (to - from) * e);
        if (p < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    },

    /* FLIP: prepend a node to a list; existing rows shift smoothly (WAAPI).
       Progressive enhancement: without element.animate, the node just appears. */
    flipPrepend: function (listEl, node, maxRows) {
      var kids = Array.prototype.slice.call(listEl.children);
      var canAnimate = !reduced() && node.animate;
      var first = canAnimate ? kids.map(function (k) { return k.getBoundingClientRect().top; }) : null;
      listEl.insertBefore(node, listEl.firstChild);
      if (maxRows && listEl.children.length > maxRows) listEl.removeChild(listEl.lastChild);
      if (!canAnimate) return;
      var dur = durOf('quick', 250);
      node.animate(
        [{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'translateY(0)' }],
        { duration: dur, easing: EASE }
      );
      kids.forEach(function (k, i) {
        if (!k.isConnected) return;
        var dy = first[i] - k.getBoundingClientRect().top;
        if (dy) k.animate(
          [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'translateY(0)' }],
          { duration: dur, easing: EASE }
        );
      });
    },

    /* FLIP-exit: fade the row out, remove it, shift the rows below up.
       `done` runs after removal — callers update state/counters there. */
    flipRemove: function (el, done) {
      var canAnimate = !reduced() && el.animate;
      if (!canAnimate) { el.remove(); if (done) done(); return; }
      var after = [];
      for (var sib = el.nextElementSibling; sib; sib = sib.nextElementSibling) after.push(sib);
      var firstTops = after.map(function (k) { return k.getBoundingClientRect().top; });
      var out = el.animate(
        [{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(-8px)' }],
        { duration: durOf('quick', 250), easing: EASE }
      );
      out.onfinish = function () {
        el.remove();
        after.forEach(function (k, i) {
          if (!k.isConnected) return;
          var dy = firstTops[i] - k.getBoundingClientRect().top;
          if (dy) k.animate(
            [{ transform: 'translateY(' + dy + 'px)' }, { transform: 'translateY(0)' }],
            { duration: durOf('quick', 250), easing: EASE }
          );
        });
        if (done) done();
      };
    },

    /* check-flash: one-shot ok-tinted overlay on a row (approve choreography);
       returns a promise-ish callback after the flash completes. */
    flash: function (el, done) {
      var dur = durOf('long', 550);
      if (reduced() || dur === 0) { if (done) done(); return; }
      el.classList.add('flash');
      setTimeout(function () {
        el.classList.remove('flash');
        if (done) done();
      }, dur);
    },

    /* one-shot success pulse (publish choreography) — 2 ring beats, then cleanup */
    pulseOnce: function (el) {
      if (reduced() || !el) return;
      el.classList.add('pulse-once');
      setTimeout(function () { el.classList.remove('pulse-once'); }, 1900);
    },

    /* Assign --i indexes so CSS stagger delays cascade. */
    stagger: function (listEl) {
      Array.prototype.forEach.call(listEl.children, function (k, i) {
        k.style.setProperty('--i', i);
      });
    }
  };
})();
