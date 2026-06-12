# Rule: SQLite

- WAL mode enabled; busy_timeout set (e.g. 5000ms).
- Short transactions only. Never hold a transaction during ffmpeg, OpenAI, TTS, Pexels, R2, Zernio, Stripe, or any external call.
- Queue jobs are idempotent; jobs carry status, retry_count, max_retries, error_message, timestamps, and idempotency keys where needed. Failed/dead jobs have explicit states.
- Workers update job status in separate short transactions.
- workspace_id on ALL tenant tables; every query filters by workspace (tenant isolation at query level).
- Clear logging for job transitions.
