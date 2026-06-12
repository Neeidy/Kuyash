/* Kuyash Phase 0 — i18n: t() helper, locale formatters, language persistence.
   All UI chrome lives in i18n/en.js + i18n/tr.js. Mock CONTENT (trend titles,
   scripts, captions) is user data and intentionally stays in its source language. */
(function () {
  'use strict';
  window.Kuyash = window.Kuyash || {};
  var PL = window.Kuyash;
  var LS_KEY = 'kuyash.lang';

  function readStored() {
    try { return window.localStorage.getItem(LS_KEY); } catch (e) { return null; }
  }
  function writeStored(lang) {
    try { window.localStorage.setItem(LS_KEY, lang); } catch (e) { /* file:// may block — in-memory only */ }
  }

  var current = readStored() === 'tr' ? 'tr' : 'en';

  PL.i18n = {
    lang: function () { return current; },
    locale: function () { return current === 'tr' ? 'tr-TR' : 'en-US'; },
    set: function (lang) {
      current = lang === 'tr' ? 'tr' : 'en';
      writeStored(current);
      document.documentElement.lang = current;
    }
  };

  /* t(key, params): active dict → EN fallback → key itself (+ console.warn once). */
  var warned = {};
  window.Kuyash.t = function (key, params) {
    var dicts = PL.I18N || {};
    var s = (dicts[current] && dicts[current][key]) != null ? dicts[current][key]
      : (dicts.en && dicts.en[key]) != null ? dicts.en[key]
      : null;
    if (s == null) {
      if (!warned[key]) { warned[key] = true; console.warn('[i18n] missing key: ' + key); }
      return key;
    }
    if (params) {
      Object.keys(params).forEach(function (p) {
        s = s.split('{' + p + '}').join(String(params[p]));
      });
    }
    return s;
  };

  /* locale-aware formatters */
  PL.fmt = {
    num: function (n) {
      return new Intl.NumberFormat(PL.i18n.locale()).format(n);
    },
    date: function (iso) { /* '2026-03-12' → localized short date */
      var d = new Date(iso + 'T12:00:00');
      return new Intl.DateTimeFormat(PL.i18n.locale(), { day: 'numeric', month: 'short', year: 'numeric' }).format(d);
    },
    when: function (w) { /* {day:'today'|'yesterday'|'tomorrow'|'d2'|'now', time?} | {date} */
      if (!w) return '—';
      if (w.date) return PL.fmt.date(w.date);
      var day = PL.t('day.' + w.day);
      return w.time ? day + ' ' + w.time : day;
    },
    ago: function (f) { /* {m}|{h}|{d} → "20m ago" / "20 dk önce" */
      if (f.m != null) return PL.t('time.mAgo', { n: f.m });
      if (f.h != null) return PL.t('time.hAgo', { n: f.h });
      return PL.t('time.dAgo', { n: f.d });
    },
    clock: function () { /* HH:MM:SS for the LIVE indicator */
      return new Intl.DateTimeFormat(PL.i18n.locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).format(new Date());
    },
    pct: function (n) { /* Turkish writes %71, English 71% */
      return PL.i18n.lang() === 'tr' ? '%' + n : n + '%';
    }
  };
})();
