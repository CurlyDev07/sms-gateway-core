# Deferred Cleanup Backlog

Date: 2026-04-19
Status: Deferred (do not execute in current hotfix/stabilization window)
Owner: Gateway Core

## Purpose
Capture non-critical cleanup items that are currently safe or desirable but intentionally postponed to avoid destabilizing active inbound/outbound operations.

## Scope To Execute Later

### A) Safe technical debt cleanup (low-risk, code/data noise)
1. Remove dead stub service:
- `app/Services/ModemCommandService.php`

2. Remove unused `api_clients` field:
- Column: `api_clients.allowed_ips`
- Model fillable: `App\\Models\\ApiClient::$fillable`

3. Remove unused `outbound_messages` fields:
- Columns: `outbound_messages.campaign_id`, `outbound_messages.conversation_ref`
- Model fillable: `App\\Models\\OutboundMessage::$fillable`

4. Remove unused `sims` fields:
- Columns: `sims.last_received_at`, `sims.notes`
- Model fillable/casts: `App\\Models\\Sim`

5. Remove unused `sim_health_logs` fields:
- Columns: `sim_health_logs.signal_strength`, `sim_health_logs.network_name`
- Model fillable: `App\\Models\\SimHealthLog::$fillable`

### B) Config cleanup (currently misleading)
1. Reconcile outbound retry config vs implementation:
- Config keys currently defined but not fully enforced by logic:
  - `services.gateway.outbound_retry_max_attempts`
  - `services.gateway.outbound_retry_max_delay_seconds`
- Align implementation (`OutboundRetryService`) or remove unused keys.

### C) Policy refactor (higher impact, requires controlled rollout)
1. Review/remove automatic SIM assignment toggling based on health/discovery:
- Scheduler entries:
  - `gateway:sync-runtime-readiness` (every minute)
  - `gateway:check-sim-health` (every 5 minutes)
- Services:
  - `App\\Services\\RuntimeSimSyncService`
  - `App\\Services\\SimHealthService`

2. Keep manual operator controls authoritative:
- `accept_new_assignments`
- explicit operator/admin enable/disable paths

3. Preserve queue+retry-first strategy without aggressive auto-disable flapping.

### D) Optional legacy surface cleanup (only if confirmed unused)
1. Evaluate retaining/removing legacy dashboard runtime pages and related endpoints if Ops panel is the single operational UI.
2. Evaluate retaining/removing `queue` SMS driver path if deployment is permanently Python-only.

## Execution Guardrails (for later cleanup PR)
1. One phase at a time:
- Phase 1: remove dead/unused columns + model references
- Phase 2: config/logic alignment
- Phase 3: policy refactor (scheduler + services)

2. Require before/after verification for each phase:
- Inbound webhook ingest
- Inbound relay to ChatApp
- Outbound enqueue, retry, send
- Sticky assignment behavior
- Ops panel visibility

3. No data-destructive migration without backup and rollback plan.

## Not Included in This Deferred Cleanup
- No immediate schema deletion in production.
- No immediate route/view removals.
- No runtime behavior changes in this documentation-only update.
