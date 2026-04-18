# SIM WORKER ID ALIGNMENT RUNBOOK

Last Updated: 2026-04-19

---

Purpose:
- document the exact failure pattern where outbound rows stay `queued`/`sending` after tenant/SIM remap
- provide the deterministic recovery sequence for `sms-gateway-core`

Scope:
- Laravel `sms-gateway-core` only
- queue/worker/SIM-ID alignment only
- excludes ChatApp-side queue bugs and Python modem hardware diagnosis

---

## Permanent Fix (No Manual Re-Launch After Remap)

`sms-gateway-core` now includes a SIM worker supervisor:

- command: `php artisan gateway:supervise-sim-workers`
- compose service: `sms-sim-supervisor`

What it does:
- continuously reconciles active mapped SIM IDs (`sims.id` with IMSI mapped)
- starts missing `gateway:process-sim <sim_id>` workers
- stops workers for SIM IDs no longer active/mapped
- auto-recovers if a SIM worker process exits unexpectedly

After pull:

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose up -d sms-sim-supervisor
docker compose logs --since=2m sms-sim-supervisor
docker top sms-sim-supervisor
```

Expected:
- no manual per-SIM `docker compose exec -d ... gateway:process-sim <id>` after remap/reinsert
- supervisor keeps worker set aligned automatically

---

## Incident Signature

Typical symptoms:
- new outbound rows are created, but some remain `queued` or stuck in `sending`
- one row may send successfully while another remains stuck
- `gateway:process-sim` workers are running, but for old SIM IDs

Confirmed live example:
- rows `116` and `117` were created
- after restarting workers for current SIM IDs:
  - `116` moved to `sent`
  - `117` stayed `queued` until correct worker alignment was completed

---

## Root Cause

`gateway:process-sim <sim_id>` is a long-running worker bound to a specific SIM ID at process start.

When SIMs are remapped, recreated, or moved between companies, DB SIM IDs can change. Old `gateway:process-sim` processes keep running for old IDs and do not automatically follow new IDs.

Result:
- queue rows assigned to new SIM IDs do not get consumed by the intended worker set.

---

## Recovery Procedure

Always start in project root:

```bash
cd ~/Documents/WebDev/sms-gateway-core
```

1) Restart worker container to clear old `process-sim` sessions:

```bash
docker compose restart sms-worker
```

2) Start workers for current SIM IDs (example: `237 238 239`):

```bash
docker compose exec -d sms-worker php artisan gateway:process-sim 237
docker compose exec -d sms-worker php artisan gateway:process-sim 238
docker compose exec -d sms-worker php artisan gateway:process-sim 239
```

3) Verify active worker bindings:

```bash
docker top sms-worker | grep 'gateway:process-sim'
```

Expected:
- only current SIM IDs are present

4) Verify affected rows move forward:

```bash
docker compose exec -T sms-app php artisan tinker --execute='
dump(\App\Models\OutboundMessage::query()->whereIn("id",[116,117])->get([
  "id","sim_id","status","failure_reason","retry_count","sent_at","updated_at"
])->toArray());
'
```

Expected:
- rows progress from `queued`/`sending` to `sent` (or deterministic failure reason)

---

## Pre-Flight Rule After Any SIM Remap

Run this every time you:
- remap IMSI to different SIM rows
- create a new company and remap active SIMs
- restore DB/seed data that can change SIM IDs

Mandatory action:
- restart `sms-worker`
- relaunch `gateway:process-sim` for the current SIM IDs

If skipped:
- intermittent outbound behavior appears (some sends succeed, others stay queued)

---

## Quick Validation Pack

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker top sms-worker | grep 'gateway:process-sim'
docker compose exec -T sms-app php artisan tinker --execute='
dump(\App\Models\OutboundMessage::query()->latest("id")->limit(10)->get([
  "id","sim_id","customer_phone","status","failure_reason","retry_count","sent_at","updated_at"
])->toArray());
'
```

Pass condition:
- worker SIM IDs match active mapped SIM IDs in DB
- new outbound rows do not remain indefinitely in `queued`/`sending`
