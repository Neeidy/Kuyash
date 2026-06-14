/* Kuyash pipeline node-graph connectors (Phase 18). Draws the lines BETWEEN the
   nodes: a solid channel between completed nodes, an animated left-to-right
   fill-flow (stroke-dashoffset) + leading dot feeding the active node, and faint
   dashes for what's still waiting. Node state comes from data-pl-state (real job
   status, server-rendered). Clicking a node opens the drawer — that's wired by
   drawer.js via data-drawer-open, not here. Colours track the CSS tokens.
   On mobile the SVG is display:none (stacked layout) and draw() bails.
   Animations are transform/opacity/dashoffset only and skip under reduced-motion. */
(function () {
  'use strict';
  var wrap = document.querySelector('[data-pipeline]');
  if (!wrap) return;
  var svg = wrap.querySelector('.pipeline-conns');
  var nodesEl = wrap.querySelector('.pipeline-nodes');
  if (!svg || !nodesEl) return;
  var NS = 'http://www.w3.org/2000/svg';

  function reduced() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function cssVar(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }

  function hexA(hex, a) {
    hex = (hex || '').replace('#', '');
    if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    var n = parseInt(hex, 16);
    if (isNaN(n) || hex.length < 6) return 'rgba(120,120,124,' + a + ')';
    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
  }
  function line(x1, y, x2, w, stroke, dash) {
    var l = document.createElementNS(NS, 'line');
    l.setAttribute('x1', x1); l.setAttribute('y1', y); l.setAttribute('x2', x2); l.setAttribute('y2', y);
    l.setAttribute('stroke', stroke); l.setAttribute('stroke-width', w); l.setAttribute('stroke-linecap', 'round');
    if (dash) l.setAttribute('stroke-dasharray', dash);
    return l;
  }
  function smil(attr, values, dur) {
    var a = document.createElementNS(NS, 'animate');
    a.setAttribute('attributeName', attr); a.setAttribute('values', values);
    a.setAttribute('dur', dur); a.setAttribute('repeatCount', 'indefinite');
    return a;
  }

  function draw() {
    svg.innerHTML = '';
    if (getComputedStyle(svg).display === 'none') return; /* mobile stacked layout */
    var chips = Array.prototype.slice.call(nodesEl.querySelectorAll('.pl-node__chip'));
    var states = Array.prototype.slice.call(nodesEl.querySelectorAll('.pl-node')).map(function (n) { return n.getAttribute('data-pl-state'); });
    if (chips.length < 2) return;
    var box = svg.getBoundingClientRect();
    if (box.width < 2) return;
    var ok = cssVar('--ok') || '#4ade80';
    var acc = cssVar('--accent') || '#2ff0d2';
    var ln = cssVar('--border-strong') || '#34343b';

    for (var i = 0; i < chips.length - 1; i++) {
      var a = chips[i].getBoundingClientRect(), b = chips[i + 1].getBoundingClientRect();
      var x1 = a.right - box.left, x2 = b.left - box.left, y = (a.top + a.bottom) / 2 - box.top;
      if (x2 <= x1) continue;
      var sL = states[i], sR = states[i + 1];
      if (sL === 'done' && sR === 'done') {
        svg.appendChild(line(x1, y, x2, 5, hexA(ok, 0.14)));
        svg.appendChild(line(x1, y, x2, 2.4, hexA(ok, 0.85)));
      } else if (sL === 'done' && sR === 'active') {
        svg.appendChild(line(x1, y, x2, 5, hexA(ok, 0.12)));
        var fill = line(x1, y, x2, 2.6, hexA(ok, 0.95));
        if (reduced()) {
          svg.appendChild(fill);
        } else {
          var len = x2 - x1;
          fill.setAttribute('stroke-dasharray', len);
          fill.setAttribute('stroke-dashoffset', len);
          fill.appendChild(smil('stroke-dashoffset', len + ';0', '1.8s'));
          svg.appendChild(fill);
          var dot = document.createElementNS(NS, 'circle');
          dot.setAttribute('r', '3.2'); dot.setAttribute('cy', y); dot.setAttribute('cx', x1); dot.setAttribute('fill', acc);
          dot.appendChild(smil('cx', x1 + ';' + x2, '1.8s'));
          svg.appendChild(dot);
        }
      } else {
        svg.appendChild(line(x1, y, x2, 2, hexA(ln, 0.9), '4 5'));
      }
    }
  }

  var raf;
  function schedule() { if (raf) cancelAnimationFrame(raf); raf = requestAnimationFrame(draw); }
  setTimeout(draw, 60);
  window.addEventListener('resize', schedule);
})();
