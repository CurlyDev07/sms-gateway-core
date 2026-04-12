#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT_DIR"

DURATION_SECONDS="${DURATION_SECONDS:-600}"
TOTAL_MESSAGES="${TOTAL_MESSAGES:-400}"
REFRESH_SECONDS="${REFRESH_SECONDS:-2}"
SAFE_MODE="${SAFE_MODE:-1}"
RUNTIME_TIMEOUT_URL="${RUNTIME_TIMEOUT_URL:-http://10.255.255.1:9000}"
RUNTIME_TIMEOUT_SECONDS="${RUNTIME_TIMEOUT_SECONDS:-2}"

RUN_TAG="task023_live_$(date +%Y%m%d%H%M%S)"
OUT_DIR="artifacts/task-023/live/${RUN_TAG}"
mkdir -p "$OUT_DIR"

WORKER_PIDS=()
SIM_IDS_CSV=""

cleanup() {
  for pid in "${WORKER_PIDS[@]:-}"; do
    if kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
    fi
  done
}
trap cleanup EXIT INT TERM

capture_active_sims() {
  docker compose exec -T sms-app php artisan tinker --execute='
$sims = \App\Models\Sim::query()
  ->where("status", "active")
  ->where("operator_status", "active")
  ->orderBy("id")
  ->get(["id","company_id","status","operator_status","mode"]);

if ($sims->isEmpty()) {
  echo "ERROR=NO_ACTIVE_SIMS\n";
  return;
}

$ids = $sims->pluck("id")->map(fn($v) => (int) $v)->toArray();
$companies = $sims->pluck("company_id")->unique()->values()->toArray();

echo "SIM_IDS=".implode(",", $ids)."\n";
echo "SIM_COUNT=".count($ids)."\n";
echo "COMPANY_IDS=".implode(",", $companies)."\n";
' > "$OUT_DIR/sims.txt"
}

seed_messages() {
  docker compose exec -T -e RUN_TAG="$RUN_TAG" -e TOTAL_MESSAGES="$TOTAL_MESSAGES" sms-app php artisan tinker --execute='
use Illuminate\Support\Str;

$runTag = getenv("RUN_TAG");
$total = max(1, (int) getenv("TOTAL_MESSAGES"));

$sims = \App\Models\Sim::query()
  ->where("status", "active")
  ->where("operator_status", "active")
  ->orderBy("id")
  ->get(["id", "company_id"]);

if ($sims->isEmpty()) {
  echo "ERROR=NO_ACTIVE_SIMS\n";
  return;
}

$redis = app(\App\Services\RedisQueueService::class);
$firstId = null;
$lastId = null;
$start = microtime(true);

for ($i = 1; $i <= $total; $i++) {
  $sim = $sims[($i - 1) % $sims->count()];

  $m = \App\Models\OutboundMessage::query()->create([
    "uuid" => (string) Str::uuid(),
    "company_id" => (int) $sim->company_id,
    "sim_id" => (int) $sim->id,
    "customer_phone" => "09333".str_pad((string) $i, 6, "0", STR_PAD_LEFT),
    "message" => "TASK023 live monitor probe ".$i,
    "message_type" => "CHAT",
    "priority" => 10,
    "status" => "queued",
    "queued_at" => now(),
    "retry_count" => 0,
    "metadata" => [
      "task023_live_probe" => true,
      "run_tag" => $runTag,
      "probe_index" => $i,
    ],
  ]);

  $redis->enqueue((int) $sim->id, (int) $m->id, "CHAT");

  if ($firstId === null) {
    $firstId = (int) $m->id;
  }
  $lastId = (int) $m->id;
}

$elapsed = microtime(true) - $start;

echo "RUN_TAG=".$runTag."\n";
echo "TOTAL_CREATED=".$total."\n";
echo "FIRST_ID=".$firstId."\n";
echo "LAST_ID=".$lastId."\n";
echo "ELAPSED_SECONDS=".number_format($elapsed, 3, ".", "")."\n";
echo "RATE_MSG_PER_SEC=".number_format($total / max($elapsed, 0.001), 2, ".", "")."\n";
' > "$OUT_DIR/seed.txt"
}

start_workers() {
  IFS=',' read -r -a sim_ids <<< "$SIM_IDS_CSV"

  for sim_id in "${sim_ids[@]}"; do
    log_file="$OUT_DIR/worker_sim_${sim_id}.log"

    if [ "$SAFE_MODE" = "1" ]; then
      nohup timeout "$((DURATION_SECONDS + 30))s" docker compose exec -T \
        -e SMS_PYTHON_API_URL="$RUNTIME_TIMEOUT_URL" \
        -e SMS_PYTHON_API_TIMEOUT_SECONDS="$RUNTIME_TIMEOUT_SECONDS" \
        sms-app php artisan gateway:process-sim "$sim_id" \
        > "$log_file" 2>&1 &
    else
      nohup timeout "$((DURATION_SECONDS + 30))s" docker compose exec -T \
        sms-app php artisan gateway:process-sim "$sim_id" \
        > "$log_file" 2>&1 &
    fi

    WORKER_PIDS+=("$!")
  done
}

fetch_stats() {
  docker compose exec -T -e RUN_TAG="$RUN_TAG" -e SIM_IDS_CSV="$SIM_IDS_CSV" sms-app php artisan tinker --execute='
$runTag = getenv("RUN_TAG");
$simIdsCsv = (string) getenv("SIM_IDS_CSV");
$simIds = array_values(array_filter(array_map("intval", explode(",", $simIdsCsv)), fn($v) => $v > 0));

$base = \App\Models\OutboundMessage::query()->where("metadata", "like", "%".$runTag."%");
$total = (clone $base)->count();
$queued = (clone $base)->where("status", "queued")->count();
$pending = (clone $base)->where("status", "pending")->count();
$sent = (clone $base)->where("status", "sent")->count();
$failed = (clone $base)->where("status", "failed")->count();
$processed = $pending + $sent + $failed;

$redis = app(\App\Services\RedisQueueService::class);
$depth = 0;
foreach ($simIds as $sid) {
  $depth += (int) $redis->depth($sid, "chat");
}

echo "TOTAL=".$total."\n";
echo "QUEUED=".$queued."\n";
echo "PENDING=".$pending."\n";
echo "SENT=".$sent."\n";
echo "FAILED=".$failed."\n";
echo "PROCESSED=".$processed."\n";
echo "CHAT_DEPTH=".$depth."\n";
'
}

print_ui() {
  local elapsed="$1"
  local remaining="$2"
  local total="$3"
  local queued="$4"
  local pending="$5"
  local sent="$6"
  local failed="$7"
  local processed="$8"
  local depth="$9"

  local left_to_process
  left_to_process=$((total - processed))
  if [ "$left_to_process" -lt 0 ]; then
    left_to_process=0
  fi

  local pct=0
  if [ "$total" -gt 0 ]; then
    pct=$((processed * 100 / total))
  fi

  local rate="0.00"
  if [ "$elapsed" -gt 0 ]; then
    rate=$(awk -v p="$processed" -v e="$elapsed" 'BEGIN { printf "%.2f", p / e }')
  fi

  local width=40
  local fill=$((pct * width / 100))
  local empty=$((width - fill))
  local bar
  bar="$(printf '%*s' "$fill" '' | tr ' ' '#')$(printf '%*s' "$empty" '' | tr ' ' '-')"

  if command -v tput >/dev/null 2>&1; then
    tput clear
  fi

  printf "TASK023 LIVE 10-MIN TEST DASHBOARD\n"
  printf "Run Tag        : %s\n" "$RUN_TAG"
  printf "Mode           : %s\n" "$( [ "$SAFE_MODE" = "1" ] && echo "SAFE (runtime timeout simulation)" || echo "LIVE" )"
  printf "SIM IDs        : %s\n" "$SIM_IDS_CSV"
  printf "Duration       : %ss\n" "$DURATION_SECONDS"
  printf "Elapsed        : %ss\n" "$elapsed"
  printf "Remaining      : %ss\n" "$remaining"
  printf "Progress       : [%s] %3d%%\n" "$bar" "$pct"
  printf "\n"
  printf "Total Rows     : %s\n" "$total"
  printf "Processed      : %s\n" "$processed"
  printf "Sent           : %s\n" "$sent"
  printf "Pending        : %s\n" "$pending"
  printf "Failed         : %s\n" "$failed"
  printf "Queued         : %s\n" "$queued"
  printf "Left to Process: %s\n" "$left_to_process"
  printf "Queue Depth    : %s\n" "$depth"
  printf "Proc Rate/s    : %s\n" "$rate"
  printf "\n"
  printf "Artifacts      : %s\n" "$OUT_DIR"
  printf "Press Ctrl+C to stop early (artifacts kept).\n"
}

capture_active_sims
if grep -q '^ERROR=' "$OUT_DIR/sims.txt"; then
  cat "$OUT_DIR/sims.txt"
  exit 1
fi

SIM_IDS_CSV="$(sed -n 's/^SIM_IDS=//p' "$OUT_DIR/sims.txt" | tail -n1)"
if [ -z "$SIM_IDS_CSV" ]; then
  echo "ERROR: failed to resolve active SIM IDs"
  exit 1
fi

seed_messages
if grep -q '^ERROR=' "$OUT_DIR/seed.txt"; then
  cat "$OUT_DIR/seed.txt"
  exit 1
fi

start_workers

START_TS="$(date +%s)"
END_TS=$((START_TS + DURATION_SECONDS))

: > "$OUT_DIR/live_metrics.log"

while true; do
  now="$(date +%s)"
  elapsed=$((now - START_TS))
  remaining=$((END_TS - now))
  if [ "$remaining" -lt 0 ]; then
    remaining=0
  fi

  stats_output="$(fetch_stats)"
  total="$(printf '%s\n' "$stats_output" | sed -n 's/^TOTAL=//p' | tail -n1)"
  queued="$(printf '%s\n' "$stats_output" | sed -n 's/^QUEUED=//p' | tail -n1)"
  pending="$(printf '%s\n' "$stats_output" | sed -n 's/^PENDING=//p' | tail -n1)"
  sent="$(printf '%s\n' "$stats_output" | sed -n 's/^SENT=//p' | tail -n1)"
  failed="$(printf '%s\n' "$stats_output" | sed -n 's/^FAILED=//p' | tail -n1)"
  processed="$(printf '%s\n' "$stats_output" | sed -n 's/^PROCESSED=//p' | tail -n1)"
  depth="$(printf '%s\n' "$stats_output" | sed -n 's/^CHAT_DEPTH=//p' | tail -n1)"

  total="${total:-0}"
  queued="${queued:-0}"
  pending="${pending:-0}"
  sent="${sent:-0}"
  failed="${failed:-0}"
  processed="${processed:-0}"
  depth="${depth:-0}"

  print_ui "$elapsed" "$remaining" "$total" "$queued" "$pending" "$sent" "$failed" "$processed" "$depth"

  printf "%s elapsed=%s remaining=%s total=%s processed=%s sent=%s pending=%s failed=%s queued=%s depth=%s\n" \
    "$(date -Iseconds)" "$elapsed" "$remaining" "$total" "$processed" "$sent" "$pending" "$failed" "$queued" "$depth" \
    >> "$OUT_DIR/live_metrics.log"

  if [ "$now" -ge "$END_TS" ]; then
    break
  fi

  sleep "$REFRESH_SECONDS"
done

# final snapshots
fetch_stats > "$OUT_DIR/final_stats.txt"

for pid in "${WORKER_PIDS[@]:-}"; do
  wait "$pid" 2>/dev/null || true
done

if command -v tput >/dev/null 2>&1; then
  tput clear
fi

echo "TASK023 live test completed."
echo "Run tag: $RUN_TAG"
echo "Artifacts: $OUT_DIR"
echo "Final stats:"
cat "$OUT_DIR/final_stats.txt"
