#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_DIR"

PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

pass() {
  printf "[PASS] %s\n" "$1"
  PASS_COUNT=$((PASS_COUNT + 1))
}

warn() {
  printf "[WARN] %s\n" "$1"
  WARN_COUNT=$((WARN_COUNT + 1))
}

fail() {
  printf "[FAIL] %s\n" "$1"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    printf "Missing required command: %s\n" "$1" >&2
    exit 1
  fi
}

require_cmd docker
require_cmd curl
require_cmd awk
require_cmd grep
require_cmd sed

printf "== Reboot Survival Health Check ==\n"
printf "Project: %s\n" "$PROJECT_DIR"
printf "Time   : %s\n\n" "$(date)"

if docker compose ps >/dev/null 2>&1; then
  pass "docker compose reachable for sms-gateway-core"
else
  fail "docker compose not reachable in this directory"
fi

GATEWAY_CONTAINERS=(sms-app sms-worker sms-scheduler sms-sim-supervisor sms-db sms-redis)

for name in "${GATEWAY_CONTAINERS[@]}"; do
  running="$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null || true)"
  policy="$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$name" 2>/dev/null || true)"

  if [[ "$running" == "true" ]]; then
    pass "$name is running"
  else
    fail "$name is not running"
  fi

  if [[ "$policy" == "unless-stopped" || "$policy" == "always" ]]; then
    pass "$name restart policy is $policy"
  else
    fail "$name restart policy is '$policy' (expected unless-stopped or always)"
  fi
done

OPS_CODE="$(curl -sS -m 5 -o /tmp/gw_ops_page.out -w '%{http_code}' http://127.0.0.1:8081/ops || true)"
if [[ "$OPS_CODE" == "200" ]]; then
  pass "GET /ops returned 200"
else
  fail "GET /ops returned HTTP $OPS_CODE"
fi

DATA_CODE="$(curl -sS -m 5 -o /tmp/gw_ops_data.out -w '%{http_code}' http://127.0.0.1:8081/ops/data || true)"
if [[ "$DATA_CODE" == "200" ]]; then
  pass "GET /ops/data returned 200"
else
  fail "GET /ops/data returned HTTP $DATA_CODE"
fi

if grep -q '"tables"' /tmp/gw_ops_data.out 2>/dev/null; then
  pass "/ops/data payload includes tables block"
else
  warn "/ops/data payload missing tables block (check payload shape)"
fi

METRICS_RAW="$(docker compose exec -T sms-app php artisan tinker --execute='
$queuedOld=\App\Models\OutboundMessage::query()
  ->where("status","queued")
  ->where("updated_at","<",now()->subMinutes(10))
  ->count();
$sendingStale=\App\Models\OutboundMessage::query()
  ->where("status","sending")
  ->whereNotNull("locked_at")
  ->where("locked_at","<",now()->subMinutes(10))
  ->count();
$pendingDue=\App\Models\OutboundMessage::query()
  ->where("status","pending")
  ->where(function($q){ $q->whereNull("scheduled_at")->orWhere("scheduled_at","<=",now()); })
  ->count();
$relayPending=\App\Models\InboundMessage::query()->where("relay_status","pending")->count();
$relayFailed=\App\Models\InboundMessage::query()->where("relay_status","failed")->count();
echo "queued_old=".$queuedOld.PHP_EOL;
echo "sending_stale=".$sendingStale.PHP_EOL;
echo "pending_due=".$pendingDue.PHP_EOL;
echo "relay_pending=".$relayPending.PHP_EOL;
echo "relay_failed=".$relayFailed.PHP_EOL;
' 2>/dev/null || true)"

queued_old="$(printf '%s\n' "$METRICS_RAW" | sed -n 's/^queued_old=//p' | tail -n1)"
sending_stale="$(printf '%s\n' "$METRICS_RAW" | sed -n 's/^sending_stale=//p' | tail -n1)"
pending_due="$(printf '%s\n' "$METRICS_RAW" | sed -n 's/^pending_due=//p' | tail -n1)"
relay_pending="$(printf '%s\n' "$METRICS_RAW" | sed -n 's/^relay_pending=//p' | tail -n1)"
relay_failed="$(printf '%s\n' "$METRICS_RAW" | sed -n 's/^relay_failed=//p' | tail -n1)"

printf "\n== Queue/Relay Metrics ==\n"
printf "queued_old=%s\n" "${queued_old:-n/a}"
printf "sending_stale=%s\n" "${sending_stale:-n/a}"
printf "pending_due=%s\n" "${pending_due:-n/a}"
printf "relay_pending=%s\n" "${relay_pending:-n/a}"
printf "relay_failed=%s\n" "${relay_failed:-n/a}"

if [[ "${queued_old:-0}" =~ ^[0-9]+$ ]] && (( queued_old == 0 )); then
  pass "No old queued outbound rows (auto-resume healthy)"
else
  fail "Old queued outbound rows detected (${queued_old:-unknown})"
fi

if [[ "${sending_stale:-0}" =~ ^[0-9]+$ ]] && (( sending_stale == 0 )); then
  pass "No stale sending locks"
else
  fail "Stale sending locks detected (${sending_stale:-unknown})"
fi

if [[ "${pending_due:-0}" =~ ^[0-9]+$ ]] && (( pending_due > 0 )); then
  warn "Pending due outbound rows exist (${pending_due}) - workers may still be draining"
else
  pass "No pending due backlog"
fi

if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
  if systemctl is-enabled docker >/dev/null 2>&1; then
    pass "docker systemd service is enabled"
  else
    fail "docker systemd service is not enabled"
  fi

  if systemctl is-enabled sms-engine >/dev/null 2>&1; then
    pass "sms-engine systemd service is enabled"
  else
    fail "sms-engine systemd service is not enabled"
  fi

  if systemctl is-active sms-engine >/dev/null 2>&1; then
    pass "sms-engine is active"
  else
    fail "sms-engine is not active"
  fi
else
  warn "systemd checks skipped (systemctl unavailable)"
fi

printf "\n== Summary ==\n"
printf "PASS=%d WARN=%d FAIL=%d\n" "$PASS_COUNT" "$WARN_COUNT" "$FAIL_COUNT"

if (( FAIL_COUNT > 0 )); then
  exit 1
fi

exit 0
