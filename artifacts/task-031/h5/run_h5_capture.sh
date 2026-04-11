#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h5"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

H5_TEST_COMPANY_ID="${H5_TEST_COMPANY_ID:-}"
DASHBOARD_RUNTIME_URL="${H5_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H5] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H5] suppression/cooldown simulation on isolated test SIM"
if docker compose exec -T sms-app php artisan tinker --execute='$companyId = '"${H5_TEST_COMPANY_ID:-null}"'; if ($companyId === null || $companyId === "") { $companyId = \App\Models\User::query()->where("email", "owner@test.local")->value("company_id") ?? \App\Models\Company::query()->value("id"); } $companyId = (int) $companyId; $phone = "h5-".time()."-".random_int(100, 999); $imsi = "99999".str_pad((string) random_int(1, 9999999999), 10, "0", STR_PAD_LEFT); $sim = \App\Models\Sim::query()->create(["company_id" => $companyId, "phone_number" => $phone, "imsi" => $imsi, "carrier" => "H5_TEST", "sim_label" => "TASK031-H5-".now()->format("Ymd-His"), "status" => "active", "mode" => "NORMAL", "operator_status" => "active", "accept_new_assignments" => true, "disabled_for_new_assignments" => false, "daily_limit" => 4000, "recommended_limit" => 3000, "burst_limit" => 30, "burst_interval_min_seconds" => 2, "burst_interval_max_seconds" => 3, "normal_interval_min_seconds" => 5, "normal_interval_max_seconds" => 8, "cooldown_min_seconds" => 60, "cooldown_max_seconds" => 120, "burst_count" => 0, "cooldown_until" => null, "last_success_at" => now(),]); $healthService = app(\App\Services\SimHealthService::class); $retryService = app(\App\Services\OutboundRetryService::class); $before = $healthService->checkHealth($sim->fresh()); $outcomes = []; for ($i = 1; $i <= 3; $i++) { $decision = $retryService->classifyFailure("RUNTIME_TIMEOUT", "transport"); $outcomes[] = $healthService->recordRuntimeFailure($sim->fresh(), "RUNTIME_TIMEOUT", "transport", $decision, 930000 + $i, "task031_h5_capture"); } $freshSim = $sim->fresh(); $after = $healthService->checkHealth($freshSim); $logs = \App\Models\SimHealthLog::query()->where("sim_id", $freshSim->id)->orderBy("id")->get(["id","status","error_message","logged_at"])->toArray(); dump(["test_company_id" => $companyId, "test_sim_id" => (int) $freshSim->id, "test_sim_phone" => $freshSim->phone_number, "test_sim_imsi" => $freshSim->imsi, "before_runtime_control" => $before["runtime_control"] ?? [], "record_outcomes" => $outcomes, "after_runtime_control" => $after["runtime_control"] ?? [], "sim_mode" => $freshSim->mode, "cooldown_until" => $freshSim->cooldown_until ? $freshSim->cooldown_until->toIso8601String() : null, "health_logs" => $logs]);' > "$OUT_DIR/runtime_suppression_simulation.txt"; then
  echo "runtime_suppression_simulation_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "runtime_suppression_simulation_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H5] latest runtime-control rows sample"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "latest_task031_h5_sims" => \App\Models\Sim::query()
    ->where("sim_label", "like", "TASK031-H5-%")
    ->latest("id")
    ->limit(5)
    ->get(["id","company_id","phone_number","imsi","status","mode","operator_status","cooldown_until","last_error_at","updated_at"])
    ->toArray(),
  "latest_cooldown_logs" => \App\Models\SimHealthLog::query()
    ->where("status", "cooldown")
    ->latest("id")
    ->limit(10)
    ->get(["id","sim_id","status","error_message","logged_at"])
    ->toArray(),
]);' > "$OUT_DIR/runtime_control_rows_sample.txt"; then
  echo "runtime_control_rows_sample_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "runtime_control_rows_sample_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H5] dashboard API response snapshot (may be 302 without session cookie)"
dashboard_status=$(curl -sS -m 60 -o "$OUT_DIR/dashboard_api_response.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
if [[ -z "$dashboard_status" ]]; then
  dashboard_status="curl_failed"
fi
echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

echo "[H5] sms-app logs snapshot"
if docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"; then
  echo "sms_app_log_capture=ok file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "sms_app_log_capture=failed exit_code=$rc file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H5] done. Artifacts in $OUT_DIR"
