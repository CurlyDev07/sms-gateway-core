#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h1"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

echo "[H1] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H1] config snapshot"
docker compose exec sms-app php artisan tinker --execute='dump(["python_api_url" => config("sms.python_api_url"), "discover_path" => config("sms.python_api_discover_path"), "timeout" => config("sms.python_api_timeout_seconds")]);' \
  | tee "$OUT_DIR/config_snapshot.txt"

DASHBOARD_RUNTIME_URL="${H1_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H1] 10 discovery runs (3m interval)"
for i in $(seq 1 10); do
  ts=$(date +%Y%m%dT%H%M%S)
  echo "RUN $i $ts" | tee -a "$OUT_DIR/runs.log"

  dashboard_status=$(curl -sS -m 120 -o "$OUT_DIR/dashboard_runtime_${ts}.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
  if [[ -z "$dashboard_status" ]]; then
    dashboard_status="curl_failed"
  fi
  echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

  docker compose exec -T sms-app sh -lc 'curl -sS -m 120 -H "X-Gateway-Token: $SMS_PYTHON_API_TOKEN" "$SMS_PYTHON_API_URL/modems/discover"' \
    > "$OUT_DIR/python_discover_${ts}.json"

  sleep 180
done

echo "[H1] app logs snapshot"
docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"

echo "[H1] done. Artifacts in $OUT_DIR"
