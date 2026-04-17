# LARAVEL FAIL-TO-SEND CHECKLIST (SMS GATEWAY CORE ONLY)

Last Updated: 2026-04-17

---

Absolutely. If we focus only on Laravel `sms-gateway-core` (ignore ChatApp + Python), here’s the full fail-to-send checklist.

Use this when outbound/inbound relay behavior looks stuck from Laravel queue/worker/Redis perspective.

Scope:
- Included: Laravel app, worker, scheduler, Redis, MySQL, queue status, retries, stuck rows.
- Excluded: ChatApp webhook debugging, Python runtime/modem troubleshooting, carrier/network deliverability.

---

## 13-Point Checklist (Laravel-Only)

Always start in project root:

```bash
cd ~/Documents/WebDev/sms-gateway-core
```

L01 — Core containers are up
```bash
docker compose ps
```
Expected:
- `sms-app`, `sms-worker`, `sms-scheduler`, `sms-redis`, `sms-db` are `Up`.

L02 — Laravel app responds
```bash
curl -I http://127.0.0.1:8081
```
Expected:
- HTTP `200`/`302` (service reachable).

L03 — Redis is reachable
```bash
docker compose exec -T sms-redis redis-cli ping
```
Expected:
- `PONG`.

L04 — Queue driver and Redis settings are loaded
```bash
docker compose exec -T sms-app php artisan tinker --execute='dump([
  "queue_default" => config("queue.default"),
  "redis_client" => config("database.redis.client"),
  "redis_host" => config("database.redis.default.host"),
]);'
```
Expected:
- `queue_default` is `redis`; host is not empty.

L05 — Worker is alive and not crash-looping
```bash
docker compose logs --since=10m sms-worker | tail -n 120
```
Expected:
- normal processing logs; no continuous fatal loop.

L06 — Scheduler heartbeat is active
```bash
docker compose logs --since=10m sms-scheduler | tail -n 120
```
Expected:
- periodic executions visible (including `gateway:check-sim-health` / `gateway:retry-scheduler` cadence).

L07 — Outbound status summary
```bash
docker compose exec -T sms-app php artisan tinker --execute='
dump(
  \App\Models\OutboundMessage::query()
    ->selectRaw("status, count(*) c")
    ->groupBy("status")
    ->orderBy("status")
    ->get()
    ->toArray()
);'
```
Expected:
- status distribution is visible and not dominated by unexpected stuck state.

L08 — Failed jobs queue is empty/understood
```bash
docker compose exec -T sms-app php artisan queue:failed
```
Expected:
- either no failed jobs or known/retriable jobs.

L09 — Detect stuck `sending` rows
```bash
docker compose exec -T sms-app php artisan tinker --execute='
dump(
  \App\Models\OutboundMessage::query()
    ->where("status","sending")
    ->where("updated_at","<",now()->subMinutes(10))
    ->count()
);'
```
Expected:
- near zero; any non-zero needs recovery.

L10 — Retry-dispatch backlog
```bash
docker compose exec -T sms-app php artisan gateway:retry-scheduler
docker compose exec -T sms-app php artisan gateway:retry-inbound-relays --limit=500
```
Expected:
- due retries dispatched without fatal errors.

L11 — Drain queue intentionally
```bash
docker compose exec -T sms-worker php artisan queue:work --queue=default --stop-when-empty --tries=3 --timeout=120
```
Expected:
- worker drains current backlog and exits cleanly.

L12 — SIM gates (Laravel DB policy side) are valid
```bash
docker compose exec -T sms-app php artisan tinker --execute='
dump(
  \App\Models\Sim::query()
    ->orderBy("company_id")->orderBy("id")
    ->get(["id","company_id","phone_number","imsi","operator_status","accept_new_assignments","disabled_for_new_assignments"])
    ->toArray()
);'
```
Expected:
- target SIM rows are active/allowed per current policy.

L13 — Post-recovery verification snapshot
```bash
docker compose exec -T sms-app php artisan tinker --execute='
dump([
  "outbound_latest" => \App\Models\OutboundMessage::query()->latest("id")->limit(5)->get(["id","sim_id","status","failure_reason","retry_count","updated_at"])->toArray(),
  "inbound_latest" => \App\Models\InboundMessage::query()->latest("id")->limit(5)->get(["id","sim_id","runtime_sim_id","relay_status","relay_error","updated_at"])->toArray(),
]);'
```
Expected:
- latest rows show forward movement (new successes or deterministic failures with reason).

---

## Recovery Command Packs

### A) One-shot combined recovery (do not run unless incident response is needed)
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose restart sms-redis
sleep 5
docker compose exec -T sms-redis redis-cli ping
docker compose restart sms-worker sms-scheduler sms-app
sleep 3
docker compose exec -T sms-app php artisan gateway:retry-scheduler
docker compose exec -T sms-app php artisan gateway:retry-inbound-relays --limit=500
docker compose exec -T sms-worker php artisan queue:work --queue=default --stop-when-empty --tries=3 --timeout=120
```

### B) Split actions (targeted)
1. Worker stuck recovery
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose restart sms-worker sms-scheduler sms-app
```
2. Redis reconnect recovery
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose restart sms-redis
sleep 5
docker compose exec -T sms-redis redis-cli ping
```
3. Pending jobs drain
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-app php artisan gateway:retry-scheduler
docker compose exec -T sms-app php artisan gateway:retry-inbound-relays --limit=500
docker compose exec -T sms-worker php artisan queue:work --queue=default --stop-when-empty --tries=3 --timeout=120
```

---

## Admin Dashboard Task-List Spec (Planned)

Display these 13 checklist items as task cards/rows with fixed IDs:
- `L01` to `L13` (same order as this doc)

Each task item should include:
- `id`
- `title`
- `last_checked_at`
- `status` (`pass | warning | fail | unknown`)
- `summary`
- `recommended_action`

### Recovery Controls

Provide both:
- one-click button: `Run Quick Recovery`
- separate buttons:
  - `Restart Workers/App/Scheduler`
  - `Reconnect Redis`
  - `Dispatch + Drain Pending Jobs`

### Required behavior
- Server-side execution only (no browser shell execution).
- Role-gated write actions (`owner/admin` only; support role read-only).
- Confirmation dialog for write actions.
- Structured action result panel:
  - `step`
  - `status`
  - `stdout/stderr summary`
  - `started_at`, `finished_at`
- Audit log entry for each action with operator ID, tenant ID, and action payload.

### Suggested action IDs
- `ops.quick_recovery_all`
- `ops.restart_workers`
- `ops.redis_reconnect`
- `ops.dispatch_and_drain`

---

## Notes

- This document intentionally excludes Python/modem and ChatApp root-cause analysis.
- If Laravel checklist is green and sends still fail, escalate outside Laravel scope.
