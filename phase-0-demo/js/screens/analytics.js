/* Screen 10 — Analytics: CSS-only visuals, count-up stats */
(function () {
  'use strict';
  var PL = window.Kuyash, ui = PL.ui, store = PL.store, t = PL.t;

  PL.screens.analytics = {
    id: 'analytics', icon: 'chart',

    render: function (el) {
      var an = store.analytics();
      var split = an.platform_split;
      var total = split.instagram + split.tiktok + split.youtube;
      var igDeg = split.instagram / total * 360;
      var ttDeg = split.tiktok / total * 360;
      /* color = status only: the platform split uses a neutral zinc ramp, not semantics */
      var donut = 'background: conic-gradient(#8b8b96 0deg ' + igDeg + 'deg, #55555e ' + igDeg + 'deg ' + (igDeg + ttDeg) + 'deg, #34343b ' + (igDeg + ttDeg) + 'deg 360deg);';
      var days = ['d3.mon', 'd3.tue', 'd3.wed', 'd3.thu', 'd3.fri', 'd3.sat', 'd3.sun'];
      var maxW = Math.max.apply(null, an.weekly_posts);
      var best = Object.keys(split).reduce(function (a, b) { return split[a] >= split[b] ? a : b; });

      el.innerHTML = `
        <header class="screen-head">
          <div><h1 class="vt-page-title">${ui.esc(t('nav.analytics'))}</h1>
          <p class="screen-sub">${ui.esc(t('an.sub', { ws: store.workspace().name }))}</p></div>
        </header>

        <div class="panel stat-row">
          ${ui.stat('an.posts', String(an.posts_published), ui.esc(t('an.days30')), { count: an.posts_published })}
          ${ui.stat('an.success', an.success_rate + '%', ui.esc(t('an.failedN', { n: an.failed })))}
          ${ui.stat('an.platforms', '3', 'IG · TikTok · YT')}
          ${ui.stat('an.best', ui.platformIcon(best) + ' ' + ui.esc(best), ui.esc(t('an.postsN', { n: split[best] })))}
        </div>

        <div class="bento bento--analytics">
          ${ui.card({
            title: t('an.perDay'), cls: 'an__bars',
            body: `<div class="barchart">
              ${an.weekly_posts.map(function (v, i) {
                return `<div class="barchart__col"><span class="barchart__val mono num">${v}</span>
                  <div class="barchart__bar" style="height:${Math.round(v / maxW * 100)}%"></div>
                  <span class="barchart__label">${ui.esc(t(days[i]))}</span></div>`;
              }).join('')}
            </div>`
          })}

          ${ui.card({
            title: t('an.dist'), cls: 'an__donut',
            body: `<div class="donut-row">
              <div class="donut" style="${donut}"><span class="donut__hole mono num">${total}</span></div>
              <ul class="legend">
                <li><span class="dot" style="background:#8b8b96"></span>Instagram <b class="mono">${split.instagram}</b></li>
                <li><span class="dot" style="background:#55555e"></span>TikTok <b class="mono">${split.tiktok}</b></li>
                <li><span class="dot" style="background:#34343b"></span>YouTube <b class="mono">${split.youtube}</b></li>
              </ul>
            </div>`
          })}

          ${ui.card({
            title: t('an.perAccount'), cls: 'an__table',
            body: `<div class="table-wrap"><table class="table">
              <thead><tr><th>${ui.esc(t('an.thAccount'))}</th><th>${ui.esc(t('an.thPlatform'))}</th><th>${ui.esc(t('an.thPosts'))}</th><th>${ui.esc(t('an.thViews'))}</th><th>${ui.esc(t('an.thTrend'))}</th></tr></thead>
              <tbody>
                ${an.accounts.map(function (a) {
                  var arrow = a.trend === 'up' ? '<span class="text-ok">' + ui.esc(t('an.rising')) + '</span>'
                    : a.trend === 'down' ? '<span class="text-err">' + ui.esc(t('an.falling')) + '</span>'
                    : '<span class="faint">' + ui.esc(t('an.flat')) + '</span>';
                  return `<tr><td>${ui.esc(a.handle)}</td><td>${ui.platformIcon(a.platform)} ${ui.esc(ui.platformName(a.platform))}</td>
                    <td class="mono">${a.posts}</td><td class="mono">${ui.esc(a.avg_views)}</td><td>${arrow}</td></tr>`;
                }).join('')}
              </tbody>
            </table></div>`
          })}
        </div>
        ${ui.note(t('an.note'))}`;

      /* count-up the headline stat */
      var counted = el.querySelector('[data-count]');
      if (counted) {
        var target = parseInt(counted.getAttribute('data-count'), 10);
        counted.textContent = '0';
        counted.dataset.countVal = '0';
        PL.motion.countUp(counted, target);
      }
    }
  };
})();
