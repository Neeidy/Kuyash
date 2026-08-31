# Phase 14 — i18n (TR/EN) — APPROVED PLAN

> Approved via `/next-phase` (Plan Mode) on 2026-06-13. **Build begins ONLY on the exact
> token `START PHASE 14`.** NEW phase extending the plan (documented Phases 0–13 complete).
> Approval here accepted scope; it did not unlock code.

## Context

TR/EN bilingual UI was an original product requirement that slipped: the real PHP backend
ships **English-only** (even the removed Phase 0 demo carried `i18n/tr.js`+`en.js`). The
codebase was DESIGNED for this pass: `Messages.php` = "the future TR i18n pass replaces
exactly one class"; the DB stores **message KEYS not localized text** (events carry
`key`+`params`, resolved at render); exceptions carry i18n-ready keys. So i18n is a pure
**presentation-layer** concern — no stored-text migration, truthfulness preserved (DB holds keys).

**Two locked decisions (2026-06-13):** (1) **EN = default + source language**, TR selectable
(missing TR key → EN fallback). (2) **Per-user locale** via migration **0012** (`users.locale`),
session-cached — SaaS-ready.

## Scope (precisely)

1. **Translator + helpers.** New `src/Core/I18n.php` (static — matches `View::e`/`Format`/
   `Messages`, no global functions): active locale + `t($key, $params = [])` over
   `lang/{locale}.php`, `{name}` interpolation (Messages' style), **fallback `locale → en →
   key`** (missing key visible, never fatal). Add `View::t($key, $params)` = auto-escaped
   `View::e(I18n::t(...))` — the short template form (`<?= View::t('...') ?>`).
2. **Lang files.** `lang/en.php` + `lang/tr.php` flat `['key' => 'text']`. Fold `Messages::MAP`
   (~101 keys) into `lang/en.php` + TR in `lang/tr.php`; `Messages::status/event/resolveFlashes`
   delegate to `I18n::t()` (the "swap one class" design; public API + call sites unchanged).
3. **Migration 0012.** `ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'
   CHECK (locale IN ('en','tr'))`. Additive, forward-only.
4. **Locale resolution.** Logged-in → user's `locale` (via Auth, session-cached); anonymous →
   `config('app.locale')` default `'en'` (new `APP_LOCALE`). `I18n::setLocale()` before render.
5. **Switcher + persistence.** EN/TR toggle in `templates/layout/app.php` → POST `/locale`
   (CSRF-protected) → updates `users.locale` + session, redirect back. `<html lang>` in
   `templates/layout/base.php`.
6. **Template extraction.** ~250 hardcoded UI literals across the **21 templates** → `View::t('...')`,
   keyed by area (`queue.title`, `settings.kill_switch`, …). Dynamic data stays `View::e($var)`.
7. **Update `phase-plan.md`** to append the Phase 14 entry (0–13 unchanged).

## Non-goals

- No third language / RTL / plural-rules engine (simple `{n}` only).
- No translation of DB content (captions/scripts stay authored); no stored localized text.
- No date/number locale reformatting (`Format` unchanged).
- No gettext/.po, no auto-translation; plain PHP arrays.
- Per-USER not per-workspace; EN string meanings unchanged; no new features/integration flips.

## Verification / acceptance criteria

- Suite green (**693** + new tests); `php tests/run.php`.
- New tests: I18n fallback (tr→en→key) + interpolation; migration 0012 (column/CHECK/default);
  locale resolution (user honored; anon→default); `/locale` route (CSRF, updates column+session);
  TR-render smoke ≥2 screens; **compliance-string truthfulness in BOTH languages**; no-bare-literal scan.
- Manual: TR → every screen Turkish, no leftover chrome, no overflow at 375/768/1280; EN identical to today.
- `users.locale` persists across logout/login; missing-key fallback visible.
- `ux-reviewer` (TR-length layout) + **`compliance-reviewer` (TR truthfulness — gate)** + focused
  `security-auditor` (`/locale` CSRF, locale CHECK-constrained). Secret grep clean; no scope creep.

## Risks

- Volume/mechanical error across 21 templates → scan-check test + reviewer.
- TR truthfulness on compliance/approval wording → compliance hard gate.
- TR-length layout overflow → ux responsive check.
- Escaping consistency → standardize on `View::t()` (escaped) in templates.
- Migration 0012 on dev DB = operator step (additive) → back up first with `bin/backup.php`.

## Mandatory reviewers before close

`ux-reviewer` + `compliance-reviewer` (TR truthfulness) + focused `security-auditor` (`/locale`).

## Approval token

Build begins ONLY on the exact token: **`START PHASE 14`**.
