#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h2"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

H2_UNREACHABLE_URL="${H2_UNREACHABLE_URL:-http://127.0.0.1:65534}"
DASHBOARD_RUNTIME_URL="${H2_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H2] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H2] baseline config snapshot"
docker compose exec -T sms-app php artisan tinker --execute='dump([
  "python_api_url" => config("sms.python_api_url"),
  "discover_path" => config("sms.python_api_discover_path"),
  "timeout" => config("sms.python_api_timeout_seconds"),
]);' | tee "$OUT_DIR/config_snapshot.txt"

echo "[H2] runtime client unreachable simulation (in-process config override only)"
if docker compose exec -T sms-app php artisan tinker --execute='config(["sms.python_api_url" => "'"$H2_UNREACHABLE_URL"'"]); dump([
  "override_python_api_url" => config("sms.python_api_url"),
  "health" => app(\App\Services\PythonRuntimeClient::class)->health(),
  "discover" => app(\App\Services\PythonRuntimeClient::class)->discover(),
]);' > "$OUT_DIR/runtime_client_unreachable.txt"; then
  echo "runtime_client_unreachable_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "runtime_client_unreachable_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H2] dashboard API response snapshot (may be 302 without session cookie)"
dashboard_status=$(curl -sS -m 60 -o "$OUT_DIR/dashboard_api_response.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
if [[ -z "$dashboard_status" ]]; then
  dashboard_status="curl_failed"
fi
echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

echo "[H2] outbound metadata sample (runtime_unreachable markers if present)"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "runtime_unreachable_rows" => \App\Models\OutboundMessage::query()
    ->where("metadata", "like", "%runtime_unreachable%")
    ->latest("id")
    ->limit(10)
    ->get(["id","status","failure_reason","updated_at","metadata"])
    ->toArray(),
  "latest_rows" => \App\Models\OutboundMessage::query()
    ->latest("id")
    ->limit(10)
    ->get(["id","status","failure_reason","updated_at","metadata"])
    ->toArray(),
]);' > "$OUT_DIR/outbound_metadata_sample.txt"; then
  echo "outbound_metadata_sample_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "outbound_metadata_sample_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H2] sms-app logs snapshot"
if docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"; then
  echo "sms_app_log_capture=ok file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "sms_app_log_capture=failed exit_code=$rc file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H2] done. Artifacts in $OUT_DIR"
