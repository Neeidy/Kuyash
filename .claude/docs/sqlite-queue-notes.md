# Kuyash — SQLite & Queue Notes

## SQLite setup (Phase 2)
- PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON
- Short transactions; one writer discipline; readers unaffected (WAL)

## Queue design (Phase 4)
- jobs table: see content-pipeline.md state fields (+ worker_id, nullable)
- Worker loop: claim (single short tx, status→processing, set started_at) → do work OUTSIDE tx → finalize (single short tx)
- **Atomic claim (multi-worker safety):** a job is claimed with ONE atomic UPDATE so two workers can never grab the same job:
  `UPDATE jobs SET status='processing', worker_id=:wid, started_at=:now WHERE id = (SELECT id FROM jobs WHERE status='queued' AND run_after <= :now ORDER BY priority, id LIMIT 1) RETURNING *;`
  If RETURNING is unavailable, claim by `UPDATE ... WHERE id=? AND status='queued'` and treat changes()=0 as "lost the race — pick next". Required even while V1 runs a single worker.
- Never hold a transaction during ffmpeg/OpenAI/TTS/Pexels/R2/Zernio/AI-video calls
- Idempotent jobs; idempotency_key unique index where duplicates are dangerous (publish, ai_video)
- retry_count < max_retries → requeue with backoff; else status=failed with error_message
- Dead/failed jobs visible in Render Queue / Logs UI with retry action
- Job transitions append to job_logs (job_id, from, to, message, created_at)

## Migration discipline (Phase 2)
- Sequential numbered SQL files: `migrations/001_init.sql`, `002_...sql` — append-only, never edit an applied migration.
- Applied migrations tracked in `schema_migrations` (version, applied_at); runner applies pending ones in order inside short transactions.
- Forward-fix only (no down-migrations): mistakes are corrected by a new migration.
- Mind SQLite ALTER limits (no DROP COLUMN pre-3.35-style patterns): prefer create-new → copy → rename for structural changes.

## Backup (Phase 13)
- WAL-aware backup: sqlite3 .backup or checkpoint + copy; never raw-copy a hot WAL db
