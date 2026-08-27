#!/usr/bin/env bash
# Phase 15.9 — visual gate entry point (DEV-ONLY).
#
# One command the loop's VISUAL gate runs: seed an ISOLATED visual DB, serve the
# app, wait for health, screenshot every screen, tear down. Exits with the
# harness's code (0 = all green; 1 = a page had a console error / overflow;
# 2 = setup/harness failure). Any extra args are forwarded to shot.mjs, e.g.:
#   tools/visual/gate.sh --only /dashboard        # self-test → 6 PNGs
#   tools/visual/gate.sh --out storage/visual/baseline
#
# VISUAL_DEMO=1 additionally runs bin/demo-seed.php against the isolated DB, so
# the screenshots cover the case-study showcase (a full library, a used calendar,
# a busy approval queue) instead of the sparse fixture. Same isolation: the demo
# seed writes into the visual DB, never the real one.
#
# Never touches the real dev DB: a dedicated DB_PATH + APP_ENV=dev keeps it
# isolated, and the session cookie non-Secure so headless http login works.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

PHP_BIN="${PHP_BIN:-/opt/homebrew/opt/php@8.3/bin/php}"
NODE_BIN="${NODE_BIN:-node}"
PORT="${VISUAL_PORT:-8099}"
DB_FILE="storage/database/kuyash-visual.sqlite"

# Isolated, mock, dev session (non-Secure cookie). Real env wins over .env.
export DB_PATH="$DB_FILE"
export APP_ENV="dev"
export APP_DEBUG="false"
export OPENAI_MOCK="true"
# The gate mocked every other provider but inherited ZERNIO_MOCK from .env — so
# an isolated, throwaway visual run was one publish away from the real provider.
# Caught by bin/demo-seed.php's live-publish precondition refusing to seed here.
export ZERNIO_MOCK="true"
export STORAGE_DRIVER="local"
# The demo seed's media path. bin/demo-seed.php already documented fixtures as
# "what the visual gate uses" — it wasn't: the gate never exported this, so every
# run went to the LIVE Pexels API. That made the gate non-deterministic (a
# different clip per run means a screenshot diff means nothing), dependent on a
# network and a key, and it spent real quota on a throwaway database. The
# committed fixtures are real portrait stock footage, so nothing about "posters
# must show real frames" is given up by using them.
export DEMO_MEDIA="fixture"
export APP_URL="http://127.0.0.1:${PORT}"
export VISUAL_TEST_EMAIL="${VISUAL_TEST_EMAIL:-visual@kuyash.local}"
export VISUAL_TEST_PASSWORD="${VISUAL_TEST_PASSWORD:-visual-dev-only-password}"

echo "[gate] fresh isolated visual DB → $DB_FILE"
rm -f "$DB_FILE" "$DB_FILE-wal" "$DB_FILE-shm"

echo "[gate] migrate + seed"
"$PHP_BIN" bin/migrate.php >/dev/null
"$PHP_BIN" bin/visual-seed.php
if [ "${VISUAL_DEMO:-}" = "1" ]; then
  echo "[gate] + showcase demo seed (isolated DB)"
  # the isolated DB is a throwaway fixture: its workspace is Manual and its
  # publishing is mocked, so both of the seed's preconditions are satisfied
  # honestly rather than waived.
  "$PHP_BIN" bin/demo-seed.php --yes
  export VISUAL_DEMO   # shot.mjs reads it to include "demo": true screens
fi

echo "[gate] start dev server on 127.0.0.1:${PORT}"
"$PHP_BIN" -S "127.0.0.1:${PORT}" -t public public/index.php >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

# wait for /health (≤ ~15s)
ready=""
for _ in $(seq 1 75); do
  if curl -fsS "http://127.0.0.1:${PORT}/health" >/dev/null 2>&1; then ready=1; break; fi
  sleep 0.2
done
if [ -z "$ready" ]; then
  echo "[gate] server never became healthy on ${PORT}" >&2
  exit 2
fi
echo "[gate] healthy → running screenshot harness"

set +e
"$NODE_BIN" tools/visual/shot.mjs --base-url "http://127.0.0.1:${PORT}" "$@"
CODE=$?
set -e

echo "[gate] harness exit code: $CODE"
exit "$CODE"
