# Ops Health Policy Settings (Dashboard-Configurable)

Last Updated: 2026-04-19

## Summary

Health and runtime auto-toggle thresholds are now configurable from the no-auth Ops panel instead of being hardcoded.

Endpoint:
- `POST /ops/settings/health-policy`

UI:
- `GET /ops` -> **Health Policy Settings** card

Storage:
- `gateway_settings` table (`key`, `value`)

## Why This Was Added

Previous behavior was too aggressive in low-traffic or transient modem conditions:
- `gateway:check-sim-health` used a fixed 30-minute threshold.
- `gateway:sync-runtime-readiness` toggled assignment flags from single probe observations.

This caused visible `disabled_for_new_assignments` flapping when runtime readiness briefly changed.

## New Configurable Keys

1. `sim_health_unhealthy_threshold_minutes`
- Purpose: threshold used by `SimHealthService::isUnhealthy()`
- Default: `30`
- Range: `5..1440`

2. `sim_health_runtime_failure_window_minutes`
- Purpose: rolling window for runtime failure counting
- Default: `15`
- Range: `1..240`

3. `sim_health_runtime_failure_threshold`
- Purpose: number of failures inside window before suppression logic applies
- Default: `3`
- Range: `1..20`

4. `sim_health_runtime_suppression_minutes`
- Purpose: cooldown duration when suppression activates
- Default: `15`
- Range: `1..240`

5. `runtime_sync_disable_after_not_ready_checks`
- Purpose: consecutive runtime not-ready checks required before auto-disable
- Default: `1` (current behavior)
- Range: `1..10`

6. `runtime_sync_enable_after_ready_checks`
- Purpose: consecutive runtime ready checks required before re-enable
- Default: `1` (current behavior)
- Range: `1..10`

## Runtime Streak Logic

`RuntimeSimSyncService` now tracks per-SIM readiness streaks in cache:
- key pattern: `gateway:runtime_sync:sim:<sim_id>:ready_streak`
- key pattern: `gateway:runtime_sync:sim:<sim_id>:not_ready_streak`
- TTL: 24 hours

Behavior:
- SIM is auto-disabled only when `not_ready_streak >= runtime_sync_disable_after_not_ready_checks`
- SIM is auto-enabled only when `ready_streak >= runtime_sync_enable_after_ready_checks`

Guardrail remains:
- system does not disable the last assignment-enabled SIM in a company.

## Ops Panel Data Contract

`GET /ops/data` now includes:
- `settings.health_policy`

This lets the UI render live current values and stay in sync after saves.

## Deployment Steps

1. Pull latest code:
```bash
cd ~/Documents/WebDev/sms-gateway-core
git pull --rebase origin main
```

2. Run migration:
```bash
docker compose exec -T sms-app php artisan migrate --force
```

3. Restart app services:
```bash
docker compose restart sms-app sms-worker sms-scheduler
```

4. Open Ops panel and set values:
- `http://<host>:8081/ops`

## Recommended Initial Tuning (Reduced Flap)

Use these first, then observe for 24h:
- `sim_health_unhealthy_threshold_minutes = 120`
- `sim_health_runtime_failure_window_minutes = 15`
- `sim_health_runtime_failure_threshold = 3`
- `sim_health_runtime_suppression_minutes = 15`
- `runtime_sync_disable_after_not_ready_checks = 3`
- `runtime_sync_enable_after_ready_checks = 2`

## Verification Commands

Check scheduler and current toggles:
```bash
docker compose exec -T sms-app php artisan schedule:list | grep -E 'sync-runtime-readiness|check-sim-health'
docker compose exec -T sms-app php artisan gateway:sync-runtime-readiness --company-id=<company_id>
docker compose exec -T sms-app php artisan gateway:check-sim-health --company-id=<company_id>
```

Confirm DB settings:
```bash
docker compose exec -T sms-app php artisan tinker --execute='dump(\App\Models\GatewaySetting::query()->orderBy("key")->get(["key","value"])->toArray());'
```

## Notes

- This is an ops-control feature; it changes behavior without code deploy once migrated.
- Existing defaults preserve old behavior until you change values.
- Ops panel is intentionally no-auth. Keep it restricted to trusted network/VPN.
