/* Kuyash Phase 0 — Live Ops layer.
   One event interface, two drivers (mock-first applies to the live layer too):
   - MockTicker (Phase 0): setInterval simulation driving mock state.
   - SseDriver (Phase 4+, stub): new EventSource('/events') mapped to the SAME events.
   Live events NEVER trigger a full re-render — screens subscribe and patch
   [data-live] DOM targets; the router tears subscriptions down on navigation. */
(function () {
  'use strict';
  window.Kuyash = window.Kuyash || {};
  var PL = window.Kuyash;

  var subs = [];          /* { type, fn } */
  var driver = null;
  var paused = false;
  var lastAt = null;

  PL.live = {
    /* event types: job_progress, job_done, job_failed, log_line, kpi_delta, trend_tick, heartbeat */
    subscribe: function (type, fn) {
      var rec = { type: type, fn: fn };
      subs.push(rec);
      return function unsubscribe() {
        var i = subs.indexOf(rec);
        if (i !== -1) subs.splice(i, 1);
      };
    },
    clearSubscribers: function () { subs.length = 0; },
    emit: function (evt) {
      lastAt = PL.fmt.clock();
      subs.forEach(function (s) {
        if (s.type === '*' || s.type === evt.type) {
          try { s.fn(evt); } catch (e) { console.error('[live] subscriber error', e); }
        }
      });
    },
    lastUpdate: function () { return lastAt; },
    start: function (d) { driver = d; driver.start(); },
    stop: function () { if (driver) driver.stop(); },
    pause: function () { paused = true; },
    resume: function () { paused = false; },
    isPaused: function () { return paused; }
  };

  /* ---------- Phase 0 driver: MockTicker ---------- */
  function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

  PL.MockTicker = {
    _timer: null,

    start: function () {
      var self = this;
      this.stop();
      function loop() {
        self._timer = setTimeout(function () {
          if (!document.hidden && !PL.live.isPaused()) self.tick();
          loop();
        }, 1800 + Math.random() * 2200);
      }
      loop();
    },
    stop: function () { if (this._timer) { clearTimeout(this._timer); this._timer = null; } },

    tick: function () {
      var ws = PL.store.workspace();
      var killed = PL.state.killSwitch[ws.id];
      var jobs = PL.store.byWorkspace('jobs');
      var processing = jobs.filter(function (j) { return j.status === 'processing' && j.progress != null; });

      /* kill switch gates automation — only heartbeats while paused */
      if (killed) {
        PL.live.emit({ type: 'heartbeat', payload: { queued: jobs.filter(function (j) { return j.status === 'queued'; }).length } });
        return;
      }

      var roll = Math.random();

      if (processing.length && roll < 0.55) {
        /* advance a processing job */
        var j = pick(processing);
        j.progress = Math.min(1, j.progress + 0.05 + Math.random() * 0.12);
        /* mirror progress onto a linked processing render (e.g. jb_107 → rn_7)
           so the queue's render card never drifts from its job */
        var rid = (String(j.entity || '').match(/rn_\d+/) || [null])[0];
        if (rid) {
          var rn = PL.store.find('renders', rid);
          if (rn && rn.status === 'processing') rn.progress = Math.min(0.98, j.progress);
        }
        if (j.progress >= 1) {
          var failed = Math.random() < 0.18;
          j.status = failed ? 'failed' : 'ready';
          j.progress = null;
          if (failed) {
            j.error_key = 'log.tick_failed';
            j.error_params = { type: j.type, job: j.id };
            j.retry_count = (j.retry_count || 0) + 1;
          } else {
            j.finished = { day: 'now' };
          }
          PL.store.logKeyed(failed ? 'log.tick_failed' : 'log.tick_done',
            { type: j.type, job: j.id }, failed ? 'error' : 'info', 'transition', j.id);
          PL.live.emit({ type: failed ? 'job_failed' : 'job_done', payload: { job: j } });
        } else {
          PL.live.emit({ type: 'job_progress', payload: { job: j, pct: Math.round(j.progress * 100) } });
        }
        return;
      }

      if (roll < 0.7) {
        /* requeue: a queued job starts processing (keeps the simulation alive) */
        var q = jobs.filter(function (x) { return x.status === 'queued'; })[0];
        if (q) {
          q.status = 'processing'; q.progress = 0.05; q.started = { day: 'now' };
          PL.store.logKeyed('log.assembly_started', { job: q.id }, 'info', 'transition', q.id);
          PL.live.emit({ type: 'job_progress', payload: { job: q, pct: 5 } });
          return;
        }
      }

      if (roll < 0.85) {
        /* trend velocity tick */
        var trends = PL.store.byWorkspace('trends');
        if (trends.length) {
          var t = pick(trends);
          var dv = Math.random() < 0.7 ? 1 + Math.floor(Math.random() * 3) : -(1 + Math.floor(Math.random() * 2));
          t.velocity_score = Math.max(5, Math.min(99, t.velocity_score + dv));
          PL.live.emit({ type: 'trend_tick', payload: { trend: t } });
          return;
        }
      }

      /* heartbeat */
      PL.live.emit({ type: 'heartbeat', payload: { queued: jobs.filter(function (x) { return x.status === 'queued'; }).length } });
    }
  };

  /* ---------- Phase 4+ driver: SSE (interface stub — NOT used in Phase 0) ----------
     Replaces MockTicker with real events from pure PHP via Server-Sent Events:
       PL.live.start(PL.SseDriver)
     The server emits the same { type, payload } shapes; nothing else changes. */
  PL.SseDriver = {
    _es: null,
    start: function () {
      /* Phase 0: intentionally inert. Later:
         this._es = new EventSource('/events');
         this._es.onmessage = function (m) { PL.live.emit(JSON.parse(m.data)); }; */
    },
    stop: function () { if (this._es) { this._es.close(); this._es = null; } }
  };
})();
