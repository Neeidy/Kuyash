/* Kuyash Phase 0 — in-page state. No persistence beyond page state (spec);
   the only stored preference is the UI language (handled in i18n.js).
   System log entries are written as KEY + PARAMS — never prose. */
(function () {
  'use strict';
  var PL = window.Kuyash;

  /* Deep copy so user interactions mutate page state, never the pristine MOCK. */
  var data = JSON.parse(JSON.stringify(PL.MOCK));

  var state = {
    data: data,
    workspaceId: data.workspaces[0].id,
    route: 'dashboard',
    approvalModes: {},
    killSwitch: {},
    /* per-screen demo UI state: 'data' | 'empty' | 'loading' | 'error' */
    uiStates: { trends: 'data', library: 'data', queue: 'data', logs: 'data', usage: 'data' },
    trendsNiche: {},
    librarySearch: '', libraryType: 'all',
    studioTab: 'ideas', studioScriptId: null,
    workflowTemplate: 'full', workflowSelected: 'COMPLIANCE',
    workflowHistory: [], workflowFuture: [],
    previewRenderId: null, previewPlatform: 'instagram',
    onboardingStep: 0,
    onboarding: { workspace: '', platform: null, oauth: 'idle', niche: null, trendReviewed: false, contentCreated: false, testRun: 'idle' },
    logsFilter: 'all',
    logsPaused: false,
    sidebarOpen: false
  };
  data.workspaces.forEach(function (w) {
    state.approvalModes[w.id] = w.approval_mode;
    state.killSwitch[w.id] = data.guardrails[w.id] ? data.guardrails[w.id].kill_switch : false;
  });

  PL.state = state;

  PL.store = {
    workspace: function () {
      return data.workspaces.find(function (w) { return w.id === state.workspaceId; });
    },
    approvalMode: function () { return state.approvalModes[state.workspaceId]; },
    setApprovalMode: function (mode) {
      state.approvalModes[state.workspaceId] = mode;
      PL.store.audit(mode === 'auto' ? 'log.mode_set_auto' : 'log.mode_set',
        { mode: mode.toUpperCase(), ws: PL.store.workspace().name });
    },

    /* tenant-scoped accessors — every list filters by workspace_id */
    byWorkspace: function (collection) {
      return (data[collection] || []).filter(function (r) { return r.workspace_id === state.workspaceId; });
    },
    find: function (collection, id) {
      return (data[collection] || []).find(function (r) { return r.id === id; });
    },
    credits: function () { return data.credits[state.workspaceId]; },
    analytics: function () { return data.analytics[state.workspaceId]; },
    guardrails: function () { return data.guardrails[state.workspaceId]; },

    /* keyed system logs — message resolved via t() at render time */
    logKeyed: function (key, params, level, kind, jobId) {
      var entry = {
        id: 'lg_x' + Math.random().toString(36).slice(2, 7),
        workspace_id: state.workspaceId, at: { day: 'now' },
        level: level || 'info', kind: kind || 'transition',
        key: key, params: params || {}, job_id: jobId || null
      };
      data.logs.unshift(entry);
      return entry;
    },
    audit: function (key, params, level, kind) {
      return PL.store.logKeyed(key, params, level, kind || 'compliance');
    }
  };
})();
