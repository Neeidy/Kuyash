# Rule: Frontend

- Vanilla JavaScript + modern custom CSS only. No frontend framework, no jQuery, no Tailwind build system unless explicitly approved.
- Phase 0: ZERO external network dependencies — no CDN fonts, icons, libraries, or analytics. Must work offline via file://.
- Responsive: usable at 375px, 768px, 1280px+. Mobile node-graph fallback = stacked workflow cards.
- Clear UI states everywhere: empty, loading, error.
- Node graph stays simple: node cards, connection lines, selection state, right-side settings panel. No general-purpose graph engine.
- Truthful approval badges: "Approved by you" vs "Auto-approved by compliance agent".
