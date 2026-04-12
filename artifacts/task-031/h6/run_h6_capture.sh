#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h6"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

H6_TEST_COMPANY_ID="${H6_TEST_COMPANY_ID:-}"
DASHBOARD_RUNTIME_URL="${H6_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H6] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H6] suppression then recovery simulation on isolated test SIM"
if docker compose exec -T sms-app php artisan tinker --execute='$companyId = '"${H6_TEST_COMPANY_ID:-null}"'; if ($companyId === null || $companyId === "") { $companyId = \App\Models\User::query()->where("email", "owner@test.local")->value("company_id") ?? \App\Models\Company::query()->value("id"); } $companyId = (int) $companyId; $phone = "h6-".time()."-".random_int(100, 999); $imsi = "88888".str_pad((string) random_int(1, 9999999999), 10, "0", STR_PAD_LEFT); $sim = \App\Models\Sim::query()->create(["company_id" => $companyId, "phone_number" => $phone, "imsi" => $imsi, "carrier" => "H6_TEST", "sim_label" => "TASK031-H6-".now()->format("Ymd-His"), "status" => "active", "mode" => "NORMAL", "operator_status" => "active", "accept_new_assignments" => true, "disabled_for_new_assignments" => false, "daily_limit" => 4000, "recommended_limit" => 3000, "burst_limit" => 30, "burst_interval_min_seconds" => 2, "burst_interval_max_seconds" => 3, "normal_interval_min_seconds" => 5, "normal_interval_max_seconds" => 8, "cooldown_min_seconds" => 60, "cooldown_max_seconds" => 120, "burst_count" => 0, "cooldown_until" => null, "last_success_at" => now(),]); $healthService = app(\App\Services\SimHealthService::class); $retryService = app(\App\Services\OutboundRetryService::class); $stateService = app(\App\Services\SimStateService::class); for ($i = 1; $i <= 3; $i++) { $decision = $retryService->classifyFailure("RUNTIME_TIMEOUT", "transport"); $healthService->recordRuntimeFailure($sim->fresh(), "RUNTIME_TIMEOUT", "transport", $decision, 940000 + $i, "task031_h6_capture"); } $suppressedSnapshot = $healthService->checkHealth($sim->fresh()); \App\Models\SimHealthLog::query()->where("sim_id", $sim->id)->where("status", "error")->update(["logged_at" => now()->subMinutes(20)]); $sim->refresh(); $sim->cooldown_until = now()->subMinute(); $sim->save(); $postWindowSnapshot = $healthService->checkHealth($sim->fresh()); $canSend = $stateService->canSend($sim->fresh()); $finalSim = $sim->fresh(); $finalSnapshot = $healthService->checkHealth($finalSim); $logs = \App\Models\SimHealthLog::query()->where("sim_id", $finalSim->id)->orderBy("id")->get(["id","status","error_message","logged_at"])->toArray(); dump(["test_company_id" => $companyId, "test_sim_id" => (int) $finalSim->id, "test_sim_phone" => $finalSim->phone_number, "test_sim_imsi" => $finalSim->imsi, "suppressed_snapshot" => $suppressedSnapshot["runtime_control"] ?? [], "post_window_snapshot" => $postWindowSnapshot["runtime_control"] ?? [], "can_send_after_recovery" => (bool) $canSend, "final_snapshot" => $finalSnapshot["runtime_control"] ?? [], "final_sim_mode" => $finalSim->mode, "final_cooldown_until" => $finalSim->cooldown_until ? $finalSim->cooldown_until->toIso8601String() : null, "final_status" => $finalSim->status, "final_operator_status" => $finalSim->operator_status, "health_logs" => $logs]);' > "$OUT_DIR/recovery_simulation.txt"; then
  echo "recovery_simulation_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "recovery_simulation_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H6] latest recovery rows sample"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "latest_task031_h6_sims" => \App\Models\Sim::query()
    ->where("sim_label", "like", "TASK031-H6-%")
    ->latest("id")
    ->limit(5)
    ->get(["id","company_id","phone_number","imsi","status","mode","operator_status","cooldown_until","last_error_at","updated_at"])
    ->toArray(),
  "latest_h6_logs" => \App\Models\SimHealthLog::query()
    ->where("error_message", "like", "%task031_h6_capture%")
    ->latest("id")
    ->limit(20)
    ->get(["id","sim_id","status","error_message","logged_at"])
    ->toArray(),
]);' > "$OUT_DIR/recovery_rows_sample.txt"; then
  echo "recovery_rows_sample_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "recovery_rows_sample_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H6] dashboard API response snapshot (may be 302 without session cookie)"
dashboard_status=$(curl -sS -m 60 -o "$OUT_DIR/dashboard_api_response.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
if [[ -z "$dashboard_status" ]]; then
  dashboard_status="curl_failed"
fi
echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

echo "[H6] sms-app logs snapshot"
if docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"; then
  echo "sms_app_log_capture=ok file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "sms_app_log_capture=failed exit_code=$rc file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H6] done. Artifacts in $OUT_DIR"
