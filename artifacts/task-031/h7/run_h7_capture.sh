#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT_DIR="$ROOT_DIR/artifacts/task-031/h7"
mkdir -p "$OUT_DIR"

cd "$ROOT_DIR"

H7_OPERATOR_PRIMARY="${H7_OPERATOR_PRIMARY:-primary_operator}"
H7_OPERATOR_SECONDARY="${H7_OPERATOR_SECONDARY:-second_operator}"
DASHBOARD_RUNTIME_URL="${H7_DASHBOARD_RUNTIME_URL:-http://127.0.0.1:8081/dashboard/api/runtime/python}"

echo "[H7] commit + timestamp"
git rev-parse --short HEAD | tee "$OUT_DIR/commit.txt"
date -Iseconds | tee -a "$OUT_DIR/timestamps.txt"

echo "[H7] baseline runtime config snapshot"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "python_api_url" => config("sms.python_api_url"),
  "health_path" => config("sms.python_api_health_path"),
  "discover_path" => config("sms.python_api_discover_path"),
  "send_path" => config("sms.python_api_send_path"),
  "timeout" => config("sms.python_api_timeout_seconds"),
]);' > "$OUT_DIR/config_snapshot.txt"; then
  echo "config_snapshot_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "config_snapshot_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H7] current runtime health/discovery snapshot"
if docker compose exec -T sms-app php artisan tinker --execute='dump([
  "health" => app(\App\Services\PythonRuntimeClient::class)->health(),
  "discover" => app(\App\Services\PythonRuntimeClient::class)->discover(),
]);' > "$OUT_DIR/runtime_snapshot.txt"; then
  echo "runtime_snapshot_status=ok" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "runtime_snapshot_status=failed exit_code=$rc" | tee -a "$OUT_DIR/runs.log"
fi

echo "[H7] dashboard API endpoint status (may be 302 without session cookie)"
dashboard_status=$(curl -sS -m 60 -o "$OUT_DIR/dashboard_api_response.json" -w "%{http_code}" "$DASHBOARD_RUNTIME_URL" || true)
if [[ -z "$dashboard_status" ]]; then
  dashboard_status="curl_failed"
fi
echo "dashboard_api_status=$dashboard_status url=$DASHBOARD_RUNTIME_URL" | tee -a "$OUT_DIR/runs.log"

echo "[H7] sms-app logs snapshot"
if docker compose logs --since=60m sms-app > "$OUT_DIR/sms-app.log"; then
  echo "sms_app_log_capture=ok file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
else
  rc=$?
  echo "sms_app_log_capture=failed exit_code=$rc file=sms-app.log" | tee -a "$OUT_DIR/runs.log"
fi

cat > "$OUT_DIR/primary_operator_dry_run.md" <<EOF
# TASK 031-H7 Primary Operator Dry Run

- Operator: ${H7_OPERATOR_PRIMARY}
- Date/Time (start): $(date -Iseconds)
- Commit Under Test: $(cat "$OUT_DIR/commit.txt")

## Required Scenario Checks

1. Runtime unreachable handling
   - Procedure artifact reference: artifacts/task-031/h2/*
   - Outcome (PASS/FAIL):
   - Notes:

2. Runtime timeout handling
   - Procedure artifact reference: artifacts/task-031/h3/*
   - Outcome (PASS/FAIL):
   - Notes:

3. Invalid response handling
   - Procedure artifact reference: artifacts/task-031/h4/*
   - Outcome (PASS/FAIL):
   - Notes:

4. Suppression/cooldown activation
   - Procedure artifact reference: artifacts/task-031/h5/*
   - Outcome (PASS/FAIL):
   - Notes:

5. Recovery/normalization after cooldown window
   - Procedure artifact reference: artifacts/task-031/h6/*
   - Outcome (PASS/FAIL):
   - Notes:

## Summary
- Any undocumented manual knowledge required? (YES/NO):
- If YES, list missing runbook details:
- Final Primary Dry Run Result (PASS/FAIL):
- Date/Time (end):
EOF

cat > "$OUT_DIR/second_operator_dry_run.md" <<EOF
# TASK 031-H7 Second Operator Dry Run

- Operator: ${H7_OPERATOR_SECONDARY}
- Date/Time (start):
- Commit Under Test: $(cat "$OUT_DIR/commit.txt")

## Independent Scenario Checks

1. Runtime unreachable handling
   - Used artifacts/procedure:
   - Outcome (PASS/FAIL):
   - Notes:

2. Runtime timeout handling
   - Used artifacts/procedure:
   - Outcome (PASS/FAIL):
   - Notes:

3. Invalid response handling
   - Used artifacts/procedure:
   - Outcome (PASS/FAIL):
   - Notes:

4. Suppression/cooldown activation
   - Used artifacts/procedure:
   - Outcome (PASS/FAIL):
   - Notes:

5. Recovery/normalization after cooldown window
   - Used artifacts/procedure:
   - Outcome (PASS/FAIL):
   - Notes:

## Summary
- Could procedure be executed without undocumented tribal knowledge? (YES/NO):
- If NO, list gaps:
- Final Second Operator Dry Run Result (PASS/FAIL):
- Date/Time (end):
EOF

cat > "$OUT_DIR/h7_completion_checklist.txt" <<EOF
TASK 031-H7 completion checklist
--------------------------------
[ ] primary_operator_dry_run.md completed and PASS
[ ] second_operator_dry_run.md completed and PASS
[ ] no undocumented tribal knowledge required
[ ] any identified gaps captured and corrected
[ ] attach references to H2/H3/H4/H5/H6 artifacts
EOF

echo "h7_templates_created=ok files=primary_operator_dry_run.md,second_operator_dry_run.md,h7_completion_checklist.txt" | tee -a "$OUT_DIR/runs.log"
echo "[H7] done. Artifacts in $OUT_DIR"
