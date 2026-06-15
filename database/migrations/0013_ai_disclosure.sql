-- Phase 10 (Zernio real adapter): per-workspace, per-platform AI-disclosure
-- toggles. Forward-only, additive (the Migrator wraps each file; no BEGIN/COMMIT).
-- Default 1 (ON) preserves Kuyash's compliance-first behavior: realistic AI media
-- is disclosed everywhere it can be.
--
-- The MECHANISM differs per platform (verified against the live Zernio
-- openapi.yaml — see .claude/docs/zernio-notes.md + ADR-021):
--   YouTube  → native flag platformSpecificData.containsSyntheticMedia
--   TikTok   → native flag platformSpecificData.videoMadeWithAi
--   Instagram→ NO native field exists → Kuyash appends an AI-disclosure caption line
--
-- Each operator can turn a platform's disclosure OFF; the publish path then records
-- a truthful `compliance.ai_disclosure_suppressed` audit event (never silently).
ALTER TABLE workspaces ADD COLUMN ai_disclose_instagram INTEGER NOT NULL DEFAULT 1 CHECK (ai_disclose_instagram IN (0, 1));
ALTER TABLE workspaces ADD COLUMN ai_disclose_youtube INTEGER NOT NULL DEFAULT 1 CHECK (ai_disclose_youtube IN (0, 1));
ALTER TABLE workspaces ADD COLUMN ai_disclose_tiktok INTEGER NOT NULL DEFAULT 1 CHECK (ai_disclose_tiktok IN (0, 1));
