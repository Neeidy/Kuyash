/* Kuyash Phase 0 — central mock data. Every screen reads ONLY from here.
   No data is hardcoded in index.html. Static demo: nothing here is real.
   i18n: system messages (logs, compliance notes, job notes) are stored as
   KEY + PARAMS (the future backend returns keys too). User CONTENT
   (trend titles, scripts, captions, asset names) is data and stays English.
   Dates are structured: {day:'today'|'yesterday'|'tomorrow'|'d2'|'now', time}
   or {date:'YYYY-MM-DD'} — rendered via PL.fmt with the active locale. */
(function () {
  'use strict';
  window.Kuyash = window.Kuyash || {};

  window.Kuyash.MOCK = {

    meta: {
      product: 'Kuyash',
      version: 'Phase 0 — static demo',
      policy_version: 'v1',
      demo_user: { name: 'Demo User', email: 'demo@kuyash.example', initials: 'DU' }
    },

    workspaces: [
      {
        id: 'ws_fit', name: 'FitPulse', niche: 'Fitness & Home Workouts',
        approval_mode: 'manual', // manual | auto — Manual is the product default
        icon: 'bolt',
        niches: ['Home Workouts', 'Nutrition Myths', 'Mobility', 'Sleep & Recovery']
      },
      {
        id: 'ws_calm', name: 'CalmClips', niche: 'Calm & Motivation (faceless)',
        approval_mode: 'manual',
        icon: 'moon',
        niches: ['Morning Calm', 'Stoic Quotes', 'Focus Sounds']
      }
    ],

    accounts: [
      { id: 'ac_1', workspace_id: 'ws_fit', platform: 'instagram', handle: '@fitpulse.daily',
        status: 'connected', health: 'healthy', daily_cap: 3, posts_today: 1, connected_at: { date: '2026-03-12' },
        recent_posts: [
          { title: 'Hallway 12-3-30 teaser', at: { day: 'today', time: '08:00' }, status: 'published' },
          { title: 'Protein myth pt. 1', at: { day: 'yesterday', time: '18:30' }, status: 'published' },
          { title: 'Desk mobility reset', at: { day: 'd2', time: '12:15' }, status: 'published' }
        ],
        health_history: [
          { day: 'd4', status: 'healthy' }, { day: 'd3', status: 'healthy' }, { day: 'd2', status: 'healthy' },
          { day: 'yesterday', status: 'healthy' }, { day: 'today', status: 'healthy' }
        ] },
      { id: 'ac_2', workspace_id: 'ws_fit', platform: 'tiktok', handle: '@fitpulse',
        status: 'connected', health: 'healthy', daily_cap: 4, posts_today: 3, connected_at: { date: '2026-03-12' },
        recent_posts: [
          { title: '12-3-30 hallway version', at: { day: 'today', time: '16:05' }, status: 'published' },
          { title: 'Wall pilates day 3', at: { day: 'today', time: '12:00' }, status: 'published' },
          { title: 'Protein timing myth', at: { day: 'today', time: '08:30' }, status: 'published' },
          { title: 'Seed oil calm take', at: { day: 'yesterday', time: '17:45' }, status: 'published' }
        ],
        health_history: [
          { day: 'd4', status: 'healthy' }, { day: 'd3', status: 'healthy' }, { day: 'd2', status: 'healthy' },
          { day: 'yesterday', status: 'healthy' }, { day: 'today', status: 'healthy' }
        ] },
      { id: 'ac_3', workspace_id: 'ws_fit', platform: 'youtube', handle: 'FitPulse Shorts',
        status: 'warning', health: 'token_expiring', warn_key: 'acct.warn_token',
        daily_cap: 2, posts_today: 0, connected_at: { date: '2026-01-28' },
        recent_posts: [
          { title: 'Protein myth — research', at: { day: 'd2', time: '09:00' }, status: 'published' },
          { title: 'Mobility stack short', at: { day: 'd3', time: '09:00' }, status: 'failed' }
        ],
        health_history: [
          { day: 'd4', status: 'healthy' }, { day: 'd3', status: 'healthy' }, { day: 'd2', status: 'token_expiring' },
          { day: 'yesterday', status: 'token_expiring' }, { day: 'today', status: 'token_expiring' }
        ] },
      { id: 'ac_4', workspace_id: 'ws_calm', platform: 'instagram', handle: '@calmclips.zen',
        status: 'connected', health: 'healthy', daily_cap: 2, posts_today: 0, connected_at: { date: '2026-04-02' },
        recent_posts: [
          { title: 'Grounding before the scroll', at: { day: 'yesterday', time: '07:35' }, status: 'published' },
          { title: 'Marcus Aurelius mornings', at: { day: 'd2', time: '07:30' }, status: 'published' }
        ],
        health_history: [
          { day: 'd4', status: 'healthy' }, { day: 'd3', status: 'healthy' }, { day: 'd2', status: 'healthy' },
          { day: 'yesterday', status: 'healthy' }, { day: 'today', status: 'healthy' }
        ] },
      { id: 'ac_5', workspace_id: 'ws_calm', platform: 'tiktok', handle: '@calmclips',
        status: 'error', health: 'reconnect_required', warn_key: 'acct.warn_session',
        daily_cap: 3, posts_today: 0, connected_at: { date: '2026-02-19' },
        recent_posts: [
          { title: 'Amor fati commute cut', at: { day: 'd2', time: '11:30' }, status: 'failed' },
          { title: 'One-breath reset', at: { day: 'd3', time: '07:30' }, status: 'published' }
        ],
        health_history: [
          { day: 'd4', status: 'healthy' }, { day: 'd3', status: 'healthy' }, { day: 'd2', status: 'reconnect_required' },
          { day: 'yesterday', status: 'reconnect_required' }, { day: 'today', status: 'reconnect_required' }
        ] },
      { id: 'ac_6', workspace_id: 'ws_calm', platform: 'youtube', handle: 'CalmClips Daily',
        status: 'connected', health: 'healthy', daily_cap: 2, posts_today: 1, connected_at: { date: '2026-04-02' },
        recent_posts: [
          { title: '5-4-3-2-1 grounding', at: { day: 'today', time: '07:30' }, status: 'published' },
          { title: 'Marcus Aurelius vs alarm', at: { day: 'yesterday', time: '07:31' }, status: 'published' }
        ],
        health_history: [
          { day: 'd4', status: 'healthy' }, { day: 'd3', status: 'healthy' }, { day: 'd2', status: 'healthy' },
          { day: 'yesterday', status: 'healthy' }, { day: 'today', status: 'healthy' }
        ] }
    ],

    trends: [
      { id: 'tr_1', workspace_id: 'ws_fit', niche: 'Home Workouts',
        title: '"12-3-30" treadmill alternative for small apartments',
        source: 'google', velocity: 'surging', velocity_score: 92, freshness: { h: 2 },
        recommended_format: 'face', spark: [12, 18, 25, 31, 48, 70, 92],
        angle: 'Show the no-treadmill version in a hallway; hook on "no gym, same burn".' },
      { id: 'tr_2', workspace_id: 'ws_fit', niche: 'Home Workouts',
        title: 'Wall pilates 7-day challenge results',
        source: 'youtube', velocity: 'rising', velocity_score: 74, freshness: { h: 5 },
        recommended_format: 'face', spark: [20, 22, 30, 41, 52, 60, 74],
        angle: 'Day 1 vs Day 7 split screen; honest take on what changed.' },
      { id: 'tr_3', workspace_id: 'ws_fit', niche: 'Nutrition Myths',
        title: '"Protein timing doesn\'t matter" debate',
        source: 'youtube', velocity: 'rising', velocity_score: 68, freshness: { h: 9 },
        recommended_format: 'faceless', spark: [30, 28, 35, 44, 51, 60, 68],
        angle: 'Myth vs study summary with bold text overlays; cite the meta-analysis.' },
      { id: 'tr_4', workspace_id: 'ws_fit', niche: 'Nutrition Myths',
        title: 'Seed oil panic — what dietitians actually say',
        source: 'tiktok_3p', velocity: 'steady', velocity_score: 55, freshness: { h: 14 },
        recommended_format: 'face', spark: [50, 52, 49, 55, 53, 56, 55],
        angle: 'Calm myth-busting tone; avoid absolutist claims.' },
      { id: 'tr_5', workspace_id: 'ws_fit', niche: 'Mobility',
        title: '90/90 hip stretch as a desk-break reset',
        source: 'instagram_be', velocity: 'rising', velocity_score: 61, freshness: { d: 1 },
        recommended_format: 'face', spark: [25, 30, 33, 40, 48, 55, 61],
        angle: 'Before/after sitting posture demo at a desk.' },
      { id: 'tr_6', workspace_id: 'ws_calm', niche: 'Morning Calm',
        title: '5-4-3-2-1 grounding before checking your phone',
        source: 'google', velocity: 'surging', velocity_score: 88, freshness: { h: 3 },
        recommended_format: 'faceless', spark: [15, 20, 34, 47, 60, 75, 88],
        angle: 'Slow nature b-roll + whispered VO; end on a single-line CTA.' },
      { id: 'tr_7', workspace_id: 'ws_calm', niche: 'Stoic Quotes',
        title: 'Marcus Aurelius on mornings ("At dawn, when you...")',
        source: 'youtube', velocity: 'steady', velocity_score: 52, freshness: { h: 8 },
        recommended_format: 'faceless', spark: [48, 50, 47, 52, 51, 53, 52],
        angle: 'Typewriter text over sunrise stock; keep it under 25s.' },
      { id: 'tr_8', workspace_id: 'ws_calm', niche: 'Stoic Quotes',
        title: '"Amor fati" explained in one commute',
        source: 'tiktok_3p', velocity: 'cooling', velocity_score: 38, freshness: { d: 2 },
        recommended_format: 'faceless', spark: [70, 64, 58, 50, 45, 41, 38],
        angle: 'Late to this one — only worth it with a fresh angle.' }
      // 'Sleep & Recovery' (FitPulse) and 'Focus Sounds' (CalmClips) intentionally
      // have no trends -> natural empty state in Trend Radar.
    ],

    /* score: mock audit components feeding the "Why?" disclosure
       (velocity = source trend velocity; 0 = no trend attached) */
    ideas: [
      { id: 'id_1', workspace_id: 'ws_fit', trend_id: 'tr_1', status: 'approved',
        title: 'Hallway "12-3-30" — no treadmill needed',
        hook: 'No gym. No treadmill. Same burn — in your hallway.',
        score: { total: 88, velocity: 92, fit: 90, novelty: 74 } },
      { id: 'id_2', workspace_id: 'ws_fit', trend_id: 'tr_3', status: 'approved',
        title: 'Protein timing myth, 30-second verdict',
        hook: 'You don\'t need protein in 30 minutes. Here\'s what actually matters.',
        score: { total: 78, velocity: 68, fit: 86, novelty: 80 } },
      { id: 'id_3', workspace_id: 'ws_fit', trend_id: 'tr_2', status: 'draft',
        title: 'Wall pilates: my honest day 1 vs day 7',
        hook: 'I did wall pilates for 7 days. Day 3 almost broke me.',
        score: { total: 75, velocity: 74, fit: 82, novelty: 62 } },
      { id: 'id_4', workspace_id: 'ws_fit', trend_id: null, status: 'draft',
        title: 'The 5-minute morning mobility stack',
        hook: 'Stiff every morning? Steal my 5-minute fix.',
        score: { total: 63, velocity: 0, fit: 88, novelty: 72 } },
      { id: 'id_5', workspace_id: 'ws_calm', trend_id: 'tr_6', status: 'approved',
        title: 'Ground yourself before the scroll',
        hook: 'Before you open that app — try this for 20 seconds.',
        score: { total: 85, velocity: 88, fit: 92, novelty: 70 } },
      { id: 'id_6', workspace_id: 'ws_calm', trend_id: 'tr_7', status: 'approved',
        title: 'Marcus Aurelius vs your alarm clock',
        hook: 'A Roman emperor hated mornings too. His fix still works.',
        score: { total: 70, velocity: 52, fit: 90, novelty: 76 } },
      { id: 'id_7', workspace_id: 'ws_calm', trend_id: null, status: 'draft',
        title: 'One-breath reset between meetings',
        hook: 'You have 10 seconds between calls. Use them like this.',
        score: { total: 61, velocity: 0, fit: 84, novelty: 78 } }
    ],

    scripts: [
      {
        id: 'sc_1', workspace_id: 'ws_fit', idea_id: 'id_1', status: 'approved', target_duration_s: 32,
        hook: 'No gym. No treadmill. Same burn — in your hallway.',
        body: 'The viral 12-3-30 needs a treadmill. You don\'t. March in place, knees high, 12 minutes, RPE 3, every morning for 30 days. Keep your arms swinging and your core tight — that\'s where the burn hides.',
        cta: 'Save this for tomorrow morning — and follow for the 30-day check-in.',
        alt_hooks: [
          'Everyone\'s doing 12-3-30 wrong at home. Do this instead.',
          'Your hallway is a treadmill. Let me prove it in 30 seconds.'
        ],
        captions: {
          instagram: 'No treadmill? No problem. The hallway 12-3-30. Save for tomorrow.',
          tiktok: 'the hallway 12-3-30 nobody told you about #fyp',
          youtube: 'The 12-3-30 alternative that needs zero equipment.'
        },
        hashtags: {
          instagram: ['#homeworkout', '#12330', '#fitnessreels', '#noexcuses', '#cardioathome'],
          tiktok: ['#fyp', '#homeworkout', '#12330challenge', '#fitnesstok'],
          youtube: ['#shorts', '#homeworkout', '#cardio']
        }
      },
      {
        id: 'sc_2', workspace_id: 'ws_fit', idea_id: 'id_2', status: 'approved', target_duration_s: 28,
        hook: 'You don\'t need protein in 30 minutes. Here\'s what actually matters.',
        body: 'The "anabolic window" is mostly a myth — a 2013 meta-analysis found total daily protein beats timing. Hit 1.6g per kg across the day and your post-workout shake is convenience, not magic.',
        cta: 'Follow for one nutrition myth busted every week.',
        alt_hooks: ['Stop racing the anabolic window. It\'s not real.', 'Your protein shake timer is lying to you.'],
        captions: {
          instagram: 'The anabolic window, busted in 28 seconds.',
          tiktok: 'protein timing is a myth and i can prove it #gymtok',
          youtube: 'Protein timing myth — what the research actually says.'
        },
        hashtags: {
          instagram: ['#nutritionmyths', '#proteintiming', '#evidencebased', '#fitnessfacts'],
          tiktok: ['#gymtok', '#nutrition', '#proteinmyth', '#fyp'],
          youtube: ['#shorts', '#nutrition', '#protein']
        }
      },
      {
        id: 'sc_3', workspace_id: 'ws_fit', idea_id: 'id_3', status: 'draft', target_duration_s: 38,
        hook: 'I did wall pilates for 7 days. Day 3 almost broke me.',
        body: 'Day 1: felt easy, was not. Day 3: my core filed a complaint. Day 5: first time my lower back didn\'t ache at my desk. Day 7: posture check — visibly straighter. It\'s free, it\'s quiet, and your wall is right there.',
        cta: 'Comment "WALL" and I\'ll send you the 7-day plan.',
        alt_hooks: ['7 days of wall pilates. Honest results, no hype.'],
        captions: {
          instagram: 'Wall pilates, 7 days, zero equipment. Honest results.',
          tiktok: 'day 3 of wall pilates humbled me fr #wallpilates',
          youtube: '' // intentionally missing -> Post Preview missing-data warning
        },
        hashtags: {
          instagram: ['#wallpilates', '#pilateschallenge', '#homefitness'],
          tiktok: ['#wallpilates', '#7daychallenge', '#fyp'],
          youtube: ['#shorts', '#pilates']
        }
      },
      {
        id: 'sc_4', workspace_id: 'ws_calm', idea_id: 'id_5', status: 'approved', target_duration_s: 24,
        hook: 'Before you open that app — try this for 20 seconds.',
        body: 'Five things you can see. Four you can touch. Three you can hear. Two you can smell. One deep breath. That\'s 5-4-3-2-1 grounding — and it works faster than your feed loads.',
        cta: 'Save this for tomorrow morning.',
        alt_hooks: ['Your phone can wait 20 seconds. Your brain can\'t.'],
        captions: {
          instagram: 'The 20-second reset before the scroll.',
          tiktok: 'try this before you open tiktok tomorrow (yes really)',
          youtube: '5-4-3-2-1 grounding — the 20-second morning reset.'
        },
        hashtags: {
          instagram: ['#morningcalm', '#grounding', '#mindfulness', '#54321method'],
          tiktok: ['#mentalhealthtok', '#grounding', '#morningroutine'],
          youtube: ['#shorts', '#mindfulness', '#calm']
        }
      },
      {
        id: 'sc_5', workspace_id: 'ws_calm', idea_id: 'id_6', status: 'approved', target_duration_s: 22,
        hook: 'A Roman emperor hated mornings too. His fix still works.',
        body: '"At dawn, when you have trouble getting out of bed, tell yourself: I am rising to do the work of a human being." Marcus Aurelius wrote that to himself — two thousand years before your snooze button.',
        cta: 'Follow for one stoic idea every morning.',
        alt_hooks: ['Your snooze button vs a 2,000-year-old emperor.'],
        captions: {
          instagram: 'Marcus Aurelius on mornings — still undefeated.',
          tiktok: 'stoicism beats the snooze button #stoicism',
          youtube: 'Marcus Aurelius vs your alarm clock.'
        },
        hashtags: {
          instagram: ['#stoicism', '#marcusaurelius', '#morningmotivation'],
          tiktok: ['#stoicism', '#philosophy', '#motivation'],
          youtube: ['#shorts', '#stoicism', '#motivation']
        }
      }
    ],

    briefs: [
      { id: 'br_1', workspace_id: 'ws_fit', script_id: 'sc_1', recorded: true,
        what_to_record: 'You marching in place in a hallway, knees high, phone propped at hip height. One take walking toward camera, one static side angle.',
        duration: 'Record ~60s raw for a 32s edit', framing: 'Vertical 9:16, hip-height tripod, full body in frame',
        hook_timing: 'Say the hook in the first 2 seconds, already moving — never start static.' },
      { id: 'br_2', workspace_id: 'ws_fit', script_id: 'sc_3', recorded: false,
        what_to_record: 'Three 15s wall-pilates clips (day 1 / day 3 / day 7 poses) + one 10s posture comparison against a doorframe.',
        duration: 'Record ~90s raw for a 38s edit', framing: 'Vertical 9:16, camera at chest height, 2m from wall',
        hook_timing: 'Hook over the day-3 struggle clip — lead with the hardest moment.' }
    ],

    assets: [
      { id: 'as_1', workspace_id: 'ws_fit', type: 'face', title: 'Hallway 12-3-30 raw take',
        duration_s: 61, aspect: '9:16', status: 'ready', thumb: 1, used_in: ['rn_1'],
        tags: ['hallway', 'cardio', 'raw'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_2', workspace_id: 'ws_fit', type: 'face', title: 'Desk mobility demo (side angle)',
        duration_s: 44, aspect: '9:16', status: 'ready', thumb: 2,
        tags: ['mobility', 'desk'], platform_fit: ['instagram', 'tiktok'] },
      { id: 'as_3', workspace_id: 'ws_fit', type: 'stock', title: 'Treadmill close-up (Pexels mock)',
        duration_s: 18, aspect: '9:16', status: 'ready', thumb: 3,
        tags: ['gym', 'stock', 'b-roll'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_4', workspace_id: 'ws_fit', type: 'stock', title: 'Protein shake pour (Pexels mock)',
        duration_s: 12, aspect: '9:16', status: 'ready', thumb: 4, used_in: ['rn_2', 'rn_3', 'rn_4'],
        tags: ['nutrition', 'stock'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_5', workspace_id: 'ws_fit', type: 'own', title: 'Kitchen meal-prep overhead',
        duration_s: 95, aspect: '16:9', status: 'ready', thumb: 5, format_warn: true,
        tags: ['nutrition', 'own'], platform_fit: ['youtube'] },
      { id: 'as_6', workspace_id: 'ws_fit', type: 'ai', title: 'AI: anatomy core highlight (image-to-video)',
        duration_s: 8, aspect: '9:16', status: 'ready', thumb: 6, ai_label_required: true,
        tags: ['ai', 'anatomy', 'overlay'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_7', workspace_id: 'ws_calm', type: 'stock', title: 'Sunrise over fog (Pexels mock)',
        duration_s: 22, aspect: '9:16', status: 'ready', thumb: 7, used_in: ['rn_5'],
        tags: ['nature', 'sunrise', 'b-roll'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_8', workspace_id: 'ws_calm', type: 'stock', title: 'Slow waves at dawn (Pexels mock)',
        duration_s: 30, aspect: '9:16', status: 'ready', thumb: 8, used_in: ['rn_6', 'rn_7'],
        tags: ['nature', 'water', 'b-roll'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_9', workspace_id: 'ws_calm', type: 'ai', title: 'AI: marble bust timelapse (image-to-video)',
        duration_s: 10, aspect: '9:16', status: 'processing', thumb: 9, ai_label_required: true,
        tags: ['ai', 'stoic'], platform_fit: ['instagram', 'tiktok', 'youtube'] },
      { id: 'as_10', workspace_id: 'ws_calm', type: 'own', title: 'Handwritten quote close-up',
        duration_s: 16, aspect: '9:16', status: 'ready', thumb: 10,
        tags: ['quote', 'own'], platform_fit: ['instagram', 'tiktok', 'youtube'] }
    ],

    /* checks_run: per-check health score 0–100 (higher = cleaner); `result`
       interprets it (pass | warn | fail). Drawer renders these as bars.
       timeline: mock of the Phase 4 append-only pipeline event log. */
    renders: [
      { id: 'rn_1', workspace_id: 'ws_fit', script_id: 'sc_1', title: 'Hallway 12-3-30 — no treadmill needed',
        status: 'awaiting_approval', risk: 'low', duration_s: 32, thumb: 1, ai_label: false,
        timeline: [
          { state: 'queued', at: { day: 'today', time: '08:32' } },
          { state: 'processing', at: { day: 'today', time: '08:35' } },
          { state: 'ready', at: { day: 'today', time: '08:49' } }
        ],
        compliance: { result: 'passed', slop_score: 18, policy_version: 'v1',
          checks_run: [
            { key: 'ai_media_detection', score: 100, max: 100, result: 'pass' },
            { key: 'slop_similarity', score: 82, max: 100, result: 'pass' },
            { key: 'duration_format', score: 100, max: 100, result: 'pass' },
            { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
          ],
          note_key: 'comp.passed', note_params: { dur: 32 } } },
      { id: 'rn_2', workspace_id: 'ws_fit', script_id: 'sc_2', title: 'Protein timing myth, 30-second verdict',
        status: 'awaiting_approval', risk: 'low', duration_s: 28, thumb: 4, ai_label: true,
        timeline: [
          { state: 'queued', at: { day: 'today', time: '08:36' } },
          { state: 'processing', at: { day: 'today', time: '08:40' } },
          { state: 'ready', at: { day: 'today', time: '08:51' } }
        ],
        compliance: { result: 'ai_label_applied', slop_score: 24, policy_version: 'v1',
          checks_run: [
            { key: 'ai_media_detection', score: 96, max: 100, result: 'pass' },
            { key: 'slop_similarity', score: 76, max: 100, result: 'pass' },
            { key: 'duration_format', score: 100, max: 100, result: 'pass' },
            { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
          ],
          note_key: 'comp.voiceLabel' } },
      { id: 'rn_3', workspace_id: 'ws_fit', script_id: 'sc_2', title: 'Protein myth (variation B)',
        status: 'awaiting_approval', risk: 'elevated', duration_s: 28, thumb: 4, ai_label: true,
        timeline: [
          { state: 'queued', at: { day: 'today', time: '08:41' } },
          { state: 'processing', at: { day: 'today', time: '08:45' } },
          { state: 'ready', at: { day: 'today', time: '08:54' } }
        ],
        compliance: { result: 'warn', slop_score: 71, policy_version: 'v1',
          checks_run: [
            { key: 'ai_media_detection', score: 96, max: 100, result: 'pass' },
            { key: 'slop_similarity', score: 29, max: 100, result: 'warn' },
            { key: 'duration_format', score: 100, max: 100, result: 'pass' },
            { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
          ],
          note_key: 'comp.warnSimilar', note_params: { slop: 71, title: 'Protein timing myth' } } },
      { id: 'rn_4', workspace_id: 'ws_fit', script_id: 'sc_2', title: 'Protein myth (variation C)',
        status: 'blocked', risk: 'blocked', duration_s: 28, thumb: 4, ai_label: true,
        timeline: [
          { state: 'queued', at: { day: 'today', time: '08:44' } },
          { state: 'processing', at: { day: 'today', time: '08:48' } },
          { state: 'ready', at: { day: 'today', time: '08:53' } }
        ],
        compliance: { result: 'blocked', slop_score: 93, policy_version: 'v1',
          checks_run: [
            { key: 'ai_media_detection', score: 96, max: 100, result: 'pass' },
            { key: 'slop_similarity', score: 7, max: 100, result: 'fail' },
            { key: 'duration_format', score: 100, max: 100, result: 'pass' },
            { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
          ],
          note_key: 'comp.blockedTpl', note_params: { slop: 93 } } },
      { id: 'rn_5', workspace_id: 'ws_calm', script_id: 'sc_4', title: 'Ground yourself before the scroll',
        status: 'scheduled', risk: 'low', duration_s: 24, thumb: 7, ai_label: true,
        publish_at: { day: 'tomorrow', time: '07:30' },
        timeline: [
          { state: 'queued', at: { day: 'today', time: '08:55' } },
          { state: 'processing', at: { day: 'today', time: '08:58' } },
          { state: 'ready', at: { day: 'today', time: '09:09' } }
        ],
        approval: { mode: 'manual', label_key: 'badge.approvedByYou', by: 'demo@kuyash.example', at: { day: 'today', time: '09:12' } },
        compliance: { result: 'ai_label_applied', slop_score: 12, policy_version: 'v1',
          checks_run: [
            { key: 'ai_media_detection', score: 97, max: 100, result: 'pass' },
            { key: 'slop_similarity', score: 88, max: 100, result: 'pass' },
            { key: 'duration_format', score: 100, max: 100, result: 'pass' },
            { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
          ],
          note_key: 'comp.voiceClean' } },
      { id: 'rn_6', workspace_id: 'ws_calm', script_id: 'sc_5', title: 'Marcus Aurelius vs your alarm clock',
        status: 'published', risk: 'low', duration_s: 22, thumb: 8, ai_label: true,
        published_at: { day: 'yesterday', time: '07:30' },
        timeline: [
          { state: 'queued', at: { day: 'yesterday', time: '06:40' } },
          { state: 'processing', at: { day: 'yesterday', time: '06:45' } },
          { state: 'ready', at: { day: 'yesterday', time: '06:58' } },
          { state: 'published', at: { day: 'yesterday', time: '07:31' } }
        ],
        approval: { mode: 'auto', label_key: 'badge.autoApproved', at: { day: 'yesterday', time: '07:02' } },
        compliance: { result: 'ai_label_applied', slop_score: 15, policy_version: 'v1',
          checks_run: [
            { key: 'ai_media_detection', score: 97, max: 100, result: 'pass' },
            { key: 'slop_similarity', score: 85, max: 100, result: 'pass' },
            { key: 'duration_format', score: 100, max: 100, result: 'pass' },
            { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
          ],
          note_key: 'comp.voicePublished' } },
      { id: 'rn_7', workspace_id: 'ws_calm', script_id: 'sc_4', title: 'Grounding (alt cut, waves b-roll)',
        status: 'processing', risk: 'low', duration_s: 24, thumb: 8, ai_label: true, progress: 0.62,
        timeline: [
          { state: 'queued', at: { day: 'today', time: '10:15' } },
          { state: 'processing', at: { day: 'today', time: '10:21' } }
        ],
        compliance: null }
    ],

    jobs: [
      { id: 'jb_101', workspace_id: 'ws_fit', type: 'trend_fetch', entity: 'Home Workouts',
        status: 'ready', retry_count: 0, max_retries: 3,
        started: { day: 'today', time: '06:00' }, finished: { day: 'today', time: '06:02' }, cost_cents: 0 },
      { id: 'jb_102', workspace_id: 'ws_fit', type: 'idea_generation', entity: 'tr_1 → 3 ideas',
        status: 'ready', retry_count: 0, max_retries: 3,
        started: { day: 'today', time: '06:05' }, finished: { day: 'today', time: '06:06' }, cost_cents: 2 },
      { id: 'jb_103', workspace_id: 'ws_fit', type: 'script_draft', entity: 'sc_3 (wall pilates)',
        status: 'awaiting_approval', retry_count: 0, max_retries: 3,
        started: { day: 'today', time: '08:11' }, cost_cents: 3, note_key: 'job.note_script_waiting' },
      { id: 'jb_104', workspace_id: 'ws_fit', type: 'script_draft', entity: 'sc_3 shooting brief',
        status: 'awaiting_recording', retry_count: 0, max_retries: 3,
        started: { day: 'today', time: '08:11' }, note_key: 'job.note_face_paused' },
      { id: 'jb_105', workspace_id: 'ws_fit', type: 'tts', entity: 'sc_2 voiceover',
        status: 'failed', retry_count: 2, max_retries: 3,
        started: { day: 'today', time: '09:40' }, error_key: 'job.err_tts_timeout' },
      { id: 'jb_106', workspace_id: 'ws_fit', type: 'asset_fetch', entity: 'sc_2 b-roll (STOCK)',
        status: 'queued', retry_count: 0, max_retries: 3 },
      { id: 'jb_107', workspace_id: 'ws_calm', type: 'assembly', entity: 'rn_7 (grounding alt cut)',
        status: 'processing', progress: 0.62, retry_count: 0, max_retries: 2,
        started: { day: 'today', time: '10:21' }, note_key: 'job.note_ffmpeg' },
      { id: 'jb_108', workspace_id: 'ws_calm', type: 'compliance_check', entity: 'rn_5',
        status: 'ready', retry_count: 0, max_retries: 1,
        started: { day: 'today', time: '09:10' }, finished: { day: 'today', time: '09:10' },
        note_key: 'job.note_result_label' },
      { id: 'jb_109', workspace_id: 'ws_calm', type: 'render_review', entity: 'rn_5',
        status: 'ready', retry_count: 0, max_retries: 1, finished: { day: 'today', time: '09:12' },
        note_key: 'job.note_manual_approved', note_params: { by: 'demo@kuyash.example' } },
      { id: 'jb_110', workspace_id: 'ws_calm', type: 'publish', entity: 'rn_6 → 3 platforms (Zernio mock)',
        status: 'published', retry_count: 0, max_retries: 3,
        started: { day: 'yesterday', time: '07:28' }, finished: { day: 'yesterday', time: '07:31' },
        cost_cents: 6, idempotency_key: 'pub_rn6_2026-06-10' },
      { id: 'jb_111', workspace_id: 'ws_calm', type: 'ai_video_generation', entity: 'as_9 (marble bust)',
        status: 'processing', progress: 0.35, retry_count: 0, max_retries: 1,
        started: { day: 'today', time: '10:05' }, cost_cents: 120, idempotency_key: 'aivid_as9_001',
        note_key: 'job.note_i2v', note_params: { n: 12 } },
      { id: 'jb_112', workspace_id: 'ws_fit', type: 'tts', entity: 'sc_1 voiceover',
        status: 'ready', retry_count: 0, max_retries: 3,
        started: { day: 'today', time: '07:02' }, finished: { day: 'today', time: '07:03' }, cost_cents: 4 }
    ],

    /* system logs: key + params (the future PHP backend returns keys, not prose) */
    logs: [
      { id: 'lg_1', workspace_id: 'ws_calm', at: { day: 'today', time: '10:21' }, level: 'info', kind: 'transition',
        job_id: 'jb_107', key: 'log.assembly_started', params: { job: 'jb_107' } },
      { id: 'lg_2', workspace_id: 'ws_fit', at: { day: 'today', time: '09:41' }, level: 'error', kind: 'transition',
        job_id: 'jb_105', key: 'log.tts_timeout', params: { job: 'jb_105', r: 2, max: 3 } },
      { id: 'lg_3', workspace_id: 'ws_calm', at: { day: 'today', time: '09:12' }, level: 'info', kind: 'transition',
        job_id: 'jb_109', key: 'log.render_approved', params: { job: 'jb_109', render: 'rn_5', by: 'demo@kuyash.example' } },
      { id: 'lg_4', workspace_id: 'ws_calm', at: { day: 'today', time: '09:10' }, level: 'info', kind: 'compliance',
        job_id: 'jb_108', key: 'log.compliance_label', params: { render: 'rn_5', slop: 12 } },
      { id: 'lg_5', workspace_id: 'ws_fit', at: { day: 'today', time: '08:55' }, level: 'warn', kind: 'compliance',
        key: 'log.compliance_warn', params: { render: 'rn_3', slop: 71 } },
      { id: 'lg_6', workspace_id: 'ws_fit', at: { day: 'today', time: '08:54' }, level: 'error', kind: 'compliance',
        key: 'log.compliance_blocked', params: { render: 'rn_4', slop: 93 } },
      { id: 'lg_7', workspace_id: 'ws_fit', at: { day: 'today', time: '08:11' }, level: 'info', kind: 'transition',
        job_id: 'jb_104', key: 'log.brief_awaiting', params: { job: 'jb_104' } },
      { id: 'lg_8', workspace_id: 'ws_fit', at: { day: 'today', time: '07:03' }, level: 'info', kind: 'transition',
        job_id: 'jb_112', key: 'log.tts_ready', params: { job: 'jb_112', cost: 4 } },
      { id: 'lg_9', workspace_id: 'ws_fit', at: { day: 'today', time: '06:02' }, level: 'info', kind: 'transition',
        job_id: 'jb_101', key: 'log.trend_ready', params: { job: 'jb_101', count: 8, sources: 2 } },
      { id: 'lg_10', workspace_id: 'ws_calm', at: { day: 'yesterday', time: '07:31' }, level: 'info', kind: 'transition',
        job_id: 'jb_110', key: 'log.published', params: { job: 'jb_110', n: 3 } },
      { id: 'lg_11', workspace_id: 'ws_calm', at: { day: 'yesterday', time: '07:02' }, level: 'info', kind: 'compliance',
        key: 'log.auto_approved', params: { render: 'rn_6', slop: 15 } },
      { id: 'lg_12', workspace_id: 'ws_calm', at: { day: 'yesterday', time: '06:58' }, level: 'warn', kind: 'guardrail',
        key: 'log.budget_warn', params: { ws: 'CalmClips', pct: 82 } },
      { id: 'lg_13', workspace_id: 'ws_fit', at: { day: 'yesterday', time: '19:12' }, level: 'warn', kind: 'guardrail',
        key: 'log.cap_warn', params: { handle: '@fitpulse (TikTok)', used: 3, cap: 4 } },
      { id: 'lg_14', workspace_id: 'ws_calm', at: { day: 'd2', time: '11:30' }, level: 'error', kind: 'transition',
        key: 'log.publish_failed_session', params: { job: 'jb_097' } }
    ],

    /* checks_run mirrors renders[].compliance.checks_run (scored objects) */
    compliance_decisions: [
      { id: 'cd_1', workspace_id: 'ws_fit', render_id: 'rn_1', result: 'passed', slop_score: 18,
        policy_version: 'v1', at: { day: 'today', time: '08:50' },
        checks_run: [
          { key: 'ai_media_detection', score: 100, max: 100, result: 'pass' },
          { key: 'slop_similarity', score: 82, max: 100, result: 'pass' },
          { key: 'duration_format', score: 100, max: 100, result: 'pass' },
          { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
        ] },
      { id: 'cd_2', workspace_id: 'ws_fit', render_id: 'rn_2', result: 'ai_label_applied', slop_score: 24,
        policy_version: 'v1', at: { day: 'today', time: '08:52' },
        checks_run: [
          { key: 'ai_media_detection', score: 96, max: 100, result: 'pass' },
          { key: 'slop_similarity', score: 76, max: 100, result: 'pass' },
          { key: 'duration_format', score: 100, max: 100, result: 'pass' },
          { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
        ] },
      { id: 'cd_3', workspace_id: 'ws_fit', render_id: 'rn_3', result: 'warn', slop_score: 71,
        policy_version: 'v1', at: { day: 'today', time: '08:55' },
        checks_run: [
          { key: 'ai_media_detection', score: 96, max: 100, result: 'pass' },
          { key: 'slop_similarity', score: 29, max: 100, result: 'warn' },
          { key: 'duration_format', score: 100, max: 100, result: 'pass' },
          { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
        ] },
      { id: 'cd_4', workspace_id: 'ws_fit', render_id: 'rn_4', result: 'blocked', slop_score: 93,
        policy_version: 'v1', at: { day: 'today', time: '08:54' },
        checks_run: [
          { key: 'ai_media_detection', score: 96, max: 100, result: 'pass' },
          { key: 'slop_similarity', score: 7, max: 100, result: 'fail' },
          { key: 'duration_format', score: 100, max: 100, result: 'pass' },
          { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
        ] },
      { id: 'cd_5', workspace_id: 'ws_calm', render_id: 'rn_5', result: 'ai_label_applied', slop_score: 12,
        policy_version: 'v1', at: { day: 'today', time: '09:10' },
        checks_run: [
          { key: 'ai_media_detection', score: 97, max: 100, result: 'pass' },
          { key: 'slop_similarity', score: 88, max: 100, result: 'pass' },
          { key: 'duration_format', score: 100, max: 100, result: 'pass' },
          { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
        ] },
      { id: 'cd_6', workspace_id: 'ws_calm', render_id: 'rn_6', result: 'ai_label_applied', slop_score: 15,
        policy_version: 'v1', at: { day: 'yesterday', time: '07:00' },
        checks_run: [
          { key: 'ai_media_detection', score: 97, max: 100, result: 'pass' },
          { key: 'slop_similarity', score: 85, max: 100, result: 'pass' },
          { key: 'duration_format', score: 100, max: 100, result: 'pass' },
          { key: 'platform_rules', score: 100, max: 100, result: 'pass' }
        ] }
    ],

    credits: {
      ws_fit: {
        balance: 420, monthly_allowance: 600, used_this_month: 180, used_today: 18, budget_cap: 500,
        breakdown: { ai_text: 62, tts: 48, ai_video: 60, publishing: 10 },
        history: [
          { at: { day: 'today', time: '10:05' }, label_key: 'usage.hVideo', label_params: { what: 'anatomy core overlay' }, amount: -12 },
          { at: { day: 'today', time: '07:03' }, label_key: 'usage.hTts', label_params: { what: 'sc_1' }, amount: -4 },
          { at: { day: 'today', time: '06:06' }, label_key: 'usage.hText', label_params: { what: '3 ideas from tr_1' }, amount: -2 },
          { at: { day: 'yesterday', time: '18:00' }, label_key: 'usage.hText', label_params: { what: 'captions sc_2' }, amount: -2 },
          { at: { day: 'jun01' }, label_key: 'usage.hMonthly', amount: 600 }
        ]
      },
      ws_calm: {
        /* balance kept LOW on purpose: a full composer run (~79 credits) must
           hit the credit gate somewhere in the demo */
        balance: 40, monthly_allowance: 400, used_this_month: 328, used_today: 16, budget_cap: 400,
        breakdown: { ai_text: 70, tts: 96, ai_video: 144, publishing: 18 },
        history: [
          { at: { day: 'today', time: '10:05' }, label_key: 'usage.hVideo', label_params: { what: 'marble bust timelapse' }, amount: -12 },
          { at: { day: 'today', time: '09:00' }, label_key: 'usage.hTts', label_params: { what: 'sc_4' }, amount: -4 },
          { at: { day: 'yesterday', time: '07:30' }, label_key: 'usage.hPub', label_params: { what: 'rn_6 (3 platforms)' }, amount: -6 },
          { at: { day: 'yesterday', time: '06:40' }, label_key: 'usage.hText', label_params: { what: 'hashtags sc_5' }, amount: -1 },
          { at: { day: 'jun01' }, label_key: 'usage.hMonthly', amount: 400 }
        ]
      }
    },

    plan: {
      name_key: 'plan.name', badge: 'V1',
      note_key: 'plan.note',
      includes: ['plan.inc1', 'plan.inc2', 'plan.inc3', 'plan.inc4']
    },

    quick_create: {
      cost_credits: 12, /* legacy flat estimate (Studio pointer card) */
      /* composer pre-flight cost model — MOCK ONLY; real estimation lands in Phase 11 */
      cost_model: { base: 2, tts_per_100chars: 1, video_per_sec: 1.5, publish_per_platform: 1 },
      /* canned refinement template for the mock prompt assistant (user-content, stays EN);
         {intent} and {dur} are filled by the composer */
      assist_template: 'Vertical 9:16, 24fps. Subject: {intent}. Warm practical lighting, handheld feel, one continuous slow move. Open mid-action in the first second; end on a 2s clean hold for the caption overlay. Target duration: {dur}s.'
    },

    workflow: {
      /* Canonical node names — single source of truth, never renamed.
         Node descriptions are i18n keys; settings values are mock config data. */
      nodes: [
        { id: 'TREND', dkey: 'wf.d_TREND',
          settings: { niche: 'Home Workouts', sources: 'Google + YouTube (official)', min_velocity: 50 } },
        { id: 'IDEA', dkey: 'wf.d_IDEA',
          settings: { ideas_per_trend: 3, tone: 'Confident, evidence-based' } },
        { id: 'SCRIPT', dkey: 'wf.d_SCRIPT',
          settings: { target_duration: '15–45s', approval: 'Human approval required (script_draft)' } },
        { id: 'VOICE', dkey: 'wf.d_VOICE',
          settings: { provider: 'OpenAI TTS (mock)', voice: 'alloy', speed: '1.0×' } },
        { id: 'VISUALS', dkey: 'wf.d_VISUALS',
          settings: { source: 'STOCK' },
          source_options: ['LIBRARY', 'STOCK', 'AI'] },
        { id: 'ASSEMBLE', dkey: 'wf.d_ASSEMBLE',
          settings: { resolution: '1080×1920 (9:16)', captions: 'Burn-in ON', max_duration: '45s' } },
        { id: 'CAPTION', dkey: 'wf.d_CAPTION',
          settings: { variations: 'Instagram / TikTok / YouTube' } },
        { id: 'HASHTAGS', dkey: 'wf.d_HASHTAGS',
          settings: { instagram: '5 tags', tiktok: '4 tags', youtube: '3 tags' } },
        { id: 'MUSIC NOTE / STYLE', dkey: 'wf.d_MUSIC',
          settings: { mood: 'Upbeat, 120–130 BPM' } },
        { id: 'PREVIEW', dkey: 'wf.d_PREVIEW',
          settings: { required: 'Yes' } },
        { id: 'COMPLIANCE', dkey: 'wf.d_COMPLIANCE', locked: true,
          settings: { policy: 'v1', checks: 'AI media · slop similarity · 15–45s · 9:16 · platform rules' } },
        { id: 'PUBLISH', dkey: 'wf.d_PUBLISH',
          settings: { provider: 'Zernio (mock until Phase 10)', schedule: 'Manual / scheduled' } }
      ],
      templates: {
        full: ['TREND', 'IDEA', 'SCRIPT', 'VOICE', 'VISUALS', 'ASSEMBLE', 'CAPTION', 'HASHTAGS', 'MUSIC NOTE / STYLE', 'PREVIEW', 'COMPLIANCE', 'PUBLISH'],
        distribution: ['LIBRARY', 'CAPTION', 'HASHTAGS', 'MUSIC NOTE / STYLE', 'PREVIEW', 'COMPLIANCE', 'PUBLISH']
      },
      library_node: { id: 'LIBRARY', dkey: 'wf.d_LIBRARY',
        settings: { asset: 'Hallway 12-3-30 raw take', source: 'Content Library' } }
    },

    onboarding: {
      steps: [
        { id: 'workspace', t: 'ob.s1_t', d: 'ob.s1_d' },
        { id: 'account', t: 'ob.s2_t', d: 'ob.s2_d' },
        { id: 'niche', t: 'ob.s3_t', d: 'ob.s3_d' },
        { id: 'trend', t: 'ob.s4_t', d: 'ob.s4_d' },
        { id: 'content', t: 'ob.s5_t', d: 'ob.s5_d' },
        { id: 'testpost', t: 'ob.s6_t', d: 'ob.s6_d' }
      ],
      niche_options: ['Fitness & Home Workouts', 'Calm & Motivation', 'Personal Finance Basics', 'Cooking in 30 Seconds', 'Tech Tips & AI Tools', 'Travel Micro-Guides']
    },

    settings_integrations: [
      { id: 'openai', name_key: 'conn.openai', status_key: 'conn.s_phase5' },
      { id: 'pexels', name_key: 'conn.pexels', status_key: 'conn.s_phase7' },
      { id: 'r2', name_key: 'conn.r2', status_key: 'conn.s_phase8' },
      { id: 'zernio', name_key: 'conn.zernio', status_key: 'conn.s_phase10' },
      { id: 'aivideo', name_key: 'conn.aivideo', status_key: 'conn.s_phase12' }
    ],

    analytics: {
      ws_fit: {
        posts_published: 23, success_rate: 96, failed: 1,
        platform_split: { instagram: 9, tiktok: 10, youtube: 4 },
        weekly_posts: [2, 4, 3, 5, 3, 4, 2],
        accounts: [
          { handle: '@fitpulse.daily', platform: 'instagram', posts: 9, avg_views: '12.4K', trend: 'up' },
          { handle: '@fitpulse', platform: 'tiktok', posts: 10, avg_views: '31.2K', trend: 'up' },
          { handle: 'FitPulse Shorts', platform: 'youtube', posts: 4, avg_views: '4.8K', trend: 'flat' }
        ]
      },
      ws_calm: {
        posts_published: 41, success_rate: 89, failed: 5,
        platform_split: { instagram: 14, tiktok: 12, youtube: 15 },
        weekly_posts: [5, 6, 7, 5, 6, 7, 5],
        accounts: [
          { handle: '@calmclips.zen', platform: 'instagram', posts: 14, avg_views: '8.1K', trend: 'up' },
          { handle: '@calmclips', platform: 'tiktok', posts: 12, avg_views: '19.7K', trend: 'down' },
          { handle: 'CalmClips Daily', platform: 'youtube', posts: 15, avg_views: '6.3K', trend: 'up' }
        ]
      }
    },

    guardrails: {
      ws_fit: { daily_cap_default: 3, budget_cap: 500, kill_switch: false, digest: 'daily_email' },
      ws_calm: { daily_cap_default: 2, budget_cap: 400, kill_switch: false, digest: 'daily_email' }
    }
  };
})();
