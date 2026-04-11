#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h4"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

DASHBOARD_RUNTIME_URL="${H4_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H4] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H4] baseline config snapshot"
docker compose exec -T sms-app php artisan tinker --execute='dump([
  "python_api_url" => config("sms.python_api_url"),
  "send_path" => config("sms.python_api_send_path"),
  "timeout" => config("sms.python_api_timeout_seconds"),
]);' | tee "$OUT_DIR/config_snapshot.txt"

echo "[H4] runtime invalid-response simulation (Http fake, in-process only)"
if docker compose exec -T sms-app php artisan tinker --execute='use Illuminate\Support\Facades\Http; Http::fake([
  "*" => Http::response(["status" => "ok", "note" => "missing_success_field"], 200),
]); $result = app(\App\Services\PythonRuntimeClient::class)->send([
  "sim_id" => "invalid-response-test-sim",
  "to" => "639000000000",
  "phone" => "639000000000",
  "message" => "H4 invalid response simulation",
  "client_message_id" => "h4-invalid-response-check",
  "meta" => ["source" => "task-031-h4"],
]); dump([
  "send_result" => $result,
  "is_invalid_response" => (($result["error"] ?? null) === "invalid_response"),
  "error_layer" => ($result["error_layer"] ?? null),
]);' > "$OUT_DIR/runtime_invalid_response.txt"; then
  echo "runtime_invalid_response_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "runtime_invalid_response_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H4] retry classifier snapshot for invalid response"
if docker compose exec -T sms-app php artisan tinker --execute='$service = app(\App\Services\OutboundRetryService::class); dump([
  "invalid_response_python_api" => $service->classifyFailure("INVALID_RESPONSE", "python_api"),
  "runtime_timeout_transport" => $service->classifyFailure("RUNTIME_TIMEOUT", "transport"),
]);' > "$OUT_DIR/retry_classification.txt"; then
  echo "retry_classification_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "retry_classification_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H4] invalid-response rows sample (existing rows only, no writes)"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "invalid_response_rows" => \App\Models\OutboundMessage::query()
    ->where(function ($q) {
      $q->where("failure_reason", "like", "%INVALID_RESPONSE%")
        ->orWhere("metadata", "like", "%invalid_response%");
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
]);' > "$OUT_DIR/invalid_response_rows_sample.txt"; then
  echo "invalid_response_rows_sample_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "invalid_response_rows_sample_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H4] dashboard API response snapshot (may be 302 without session cookie)"
dashboard_status=$(curl -sS -m 60 -o "$OUT_DIR/dashboard_api_response.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
if [[ -z "$dashboard_status" ]]; then
  dashboard_status="curl_failed"
fi
echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

echo "[H4] sms-app logs snapshot"
if docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"; then
  echo "sms_app_log_capture=ok file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "sms_app_log_capture=failed exit_code=$rc file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H4] done. Artifacts in $OUT_DIR"
