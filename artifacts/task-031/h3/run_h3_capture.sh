#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h3"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

H3_TIMEOUT_URL="${H3_TIMEOUT_URL:-http://10.255.255.1:9000}"
H3_TIMEOUT_SECONDS="${H3_TIMEOUT_SECONDS:-2}"
DASHBOARD_RUNTIME_URL="${H3_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H3] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H3] baseline config snapshot"
docker compose exec -T sms-app php artisan tinker --execute='dump([
  "python_api_url" => config("sms.python_api_url"),
  "send_path" => config("sms.python_api_send_path"),
  "timeout" => config("sms.python_api_timeout_seconds"),
]);' | tee "$OUT_DIR/config_snapshot.txt"

echo "[H3] runtime send timeout simulation (in-process override only)"
if docker compose exec -T sms-app php artisan tinker --execute='config([
  "sms.python_api_url" => "'"$H3_TIMEOUT_URL"'",
  "sms.python_api_timeout_seconds" => '"$H3_TIMEOUT_SECONDS"',
]); $result = app(\App\Services\PythonRuntimeClient::class)->send([
  "sim_id" => "timeout-test-sim",
  "to" => "639000000000",
  "phone" => "639000000000",
  "message" => "H3 timeout simulation",
  "client_message_id" => "h3-timeout-check",
  "meta" => ["source" => "task-031-h3"],
]); dump([
  "override_python_api_url" => config("sms.python_api_url"),
  "override_timeout_seconds" => config("sms.python_api_timeout_seconds"),
  "send_result" => $result,
  "is_runtime_timeout" => (($result["error"] ?? null) === "runtime_timeout"),
]);' > "$OUT_DIR/runtime_send_timeout.txt"; then
  echo "runtime_send_timeout_status=ok url=$H3_TIMEOUT_URL timeout=$H3_TIMEOUT_SECONDS" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "runtime_send_timeout_status=failed exit_code=$rc url=$H3_TIMEOUT_URL timeout=$H3_TIMEOUT_SECONDS" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H3] retry classifier snapshot for runtime timeout"
if docker compose exec -T sms-app php artisan tinker --execute='$service = app(\App\Services\OutboundRetryService::class); dump([
  "runtime_timeout_transport" => $service->classifyFailure("RUNTIME_TIMEOUT", "transport"),
  "runtime_unreachable_transport" => $service->classifyFailure("RUNTIME_UNREACHABLE", "transport"),
  "invalid_response_python_api" => $service->classifyFailure("INVALID_RESPONSE", "python_api"),
]);' > "$OUT_DIR/retry_classification.txt"; then
  echo "retry_classification_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "retry_classification_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H3] outbound retry fields sample (existing rows only, no writes)"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "runtime_timeout_rows" => \App\Models\OutboundMessage::query()
    ->where(function ($q) {
      $q->where("failure_reason", "like", "%RUNTIME_TIMEOUT%")
        ->orWhere("metadata", "like", "%runtime_timeout%");
    })
    ->latest("id")
    ->limit(10)
    ->get(["id","status","retry_count","scheduled_at","failure_reason","updated_at","metadata"])
    ->toArray(),
  "latest_rows" => \App\Models\OutboundMessage::query()
    ->latest("id")
    ->limit(10)
    ->get(["id","status","retry_count","scheduled_at","failure_reason","updated_at","metadata"])
    ->toArray(),
]);' > "$OUT_DIR/retry_fields_sample.txt"; then
  echo "retry_fields_sample_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "retry_fields_sample_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H3] dashboard API response snapshot (may be 302 without session cookie)"
dashboard_status=$(curl -sS -m 60 -o "$OUT_DIR/dashboard_api_response.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
if [[ -z "$dashboard_status" ]]; then
  dashboard_status="curl_failed"
fi
echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

echo "[H3] sms-app logs snapshot"
if docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"; then
  echo "sms_app_log_capture=ok file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "sms_app_log_capture=failed exit_code=$rc file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H3] done. Artifacts in $OUT_DIR"
