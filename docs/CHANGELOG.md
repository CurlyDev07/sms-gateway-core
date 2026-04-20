# CHANGELOG

Last Updated: 2026-04-20

---

## [2026-04-20] InfoTxt Status Poll Compatibility Endpoint (`/v2/status.php`)

### Summary
Added the missing InfoTxt-compatible status poll endpoint so ChatApp can transition outbound rows from `queued` to `sent/failed`.

### What Changed
- Added:
  - `app/Http/Controllers/InfotxtStatusController.php`
    - accepts `smsid` (or `SMSID`) query param
    - returns InfoTxt-compatible payload with `status` mapped as:
      - `0` => queued/in-progress (`pending|queued|sending`)
      - `1` => sent (`sent`)
      - `2` => failed (`failed|cancelled|not_found`)
    - response includes `smsid`
- Updated:
  - `routes/api.php`
    - new route `GET /api/v2/status.php`
  - `routes/web.php`
    - compatibility alias route `GET /v2/status.php` (for callers without `/api` base path)
- Added:
  - `tests/Feature/Http/InfotxtStatusControllerTest.php`
    - contract coverage for `0|1|2` mapping and both route paths

### Status
- application/runtime behavior change (ChatApp delivery status polling fixed)

---

## [2026-04-20] Retry-All Failed Sends + Ops Cooldown Controls

### Summary
Enabled retry-all failure policy for outbound sends (including carrier/network rejections) and added Ops controls to inspect settings and manually clear SIM cooldown.

### What Changed
- Updated:
  - `app/Services/OutboundRetryService.php`
    - new operator policy switch:
      - `services.gateway.outbound_retry_all_failures`
    - when enabled, all send failures are classified retryable (`operator_policy_retry_all_failures`)
- Updated:
  - `config/services.php`
    - added gateway runtime control keys:
      - `GATEWAY_OUTBOUND_RETRY_ALL_FAILURES` (default `true`)
      - `GATEWAY_RUNTIME_FAILURE_WINDOW_MINUTES` (default `15`)
      - `GATEWAY_RUNTIME_FAILURE_THRESHOLD` (default `3`)
      - `GATEWAY_RUNTIME_SUPPRESSION_MINUTES` (default `15`)
- Updated:
  - `app/Services/SimHealthService.php`
    - runtime suppression window/threshold/minutes are now config-driven
- Updated:
  - `app/Http/Controllers/OpsPanelController.php`
    - includes effective gateway settings in `/ops/data`
    - adds SIM cooldown reset endpoint:
      - `POST /ops/sims/{id}/clear-cooldown`
- Updated:
  - `routes/web.php`
    - registers `ops.sims.clear_cooldown`
- Updated:
  - `resources/views/ops/index.blade.php`
    - SIM mapping table now includes `Cooldown` status and `Clear` action button
    - new `Gateway Settings` section showing active retry/cooldown/hysteresis parameters
- Updated tests:
  - `tests/Feature/Web/OpsPanelTest.php`
  - `tests/Unit/Services/OutboundRetryServiceTest.php`
  - `tests/Feature/Worker/SimQueueWorkerServiceRedisTest.php`

### Status
- application/runtime behavior change (aggressive retry + operator cooldown control)

---

## [2026-04-19] Queue-First Fallback Admission (Avoid no_sim_available Under Runtime Flaps)

### Summary
Adjusted SIM fallback selection to keep accepting outbound intake even when SIMs are temporarily cooldown/auto-disabled by runtime health automation.

### What Changed
- Updated:
  - `app/Services/SimSelectionService.php`
    - fallback selection now ignores `cooldown_until` and `disabled_for_new_assignments`
    - fallback still enforces `status=active`, `operator_status!=blocked`, and `accept_new_assignments=true`
    - intent: avoid transient `no_sim_available` during modem/runtime turbulence and let queue+retry own delivery
- Updated:
  - `tests/Unit/Services/SimSelectionServiceTest.php`
    - added regression test proving new-assignment fallback can admit auto-disabled/cooldown SIM when strict selector returns null

### Status
- application/runtime behavior change (queue-first admission hardening)

---

## [2026-04-19] Non-Sticky SIM Routing Hysteresis (Soft Deprioritization)

### Summary
Added non-sticky assignment hysteresis so traffic does not flap between SIMs every minute when one modem is unstable.

### What Changed
- Updated:
  - `app/Services/SimSelectionService.php`
    - new-assignment selection now applies soft hold windows for SIMs with high recent failure counts or queue pressure
    - held SIMs are skipped for new assignments until hold TTL expires
    - safety valve retained: if all candidates are held, selection still returns the best-ranked SIM instead of hard-failing
- Updated:
  - `config/services.php`
    - new tuning keys:
      - `GATEWAY_SIM_SELECTION_HYSTERESIS_HOLD_SECONDS` (default `300`)
      - `GATEWAY_SIM_SELECTION_FAILURE_WINDOW_MINUTES` (default `15`)
      - `GATEWAY_SIM_SELECTION_FAILURE_HOLD_THRESHOLD` (default `3`)
      - `GATEWAY_SIM_SELECTION_QUEUE_HOLD_THRESHOLD` (default `100`)
- Updated:
  - `tests/Unit/Services/SimSelectionServiceTest.php`
    - added coverage for hold-skip behavior, all-held safety fallback, and failure-threshold-triggered hold

### Status
- application/runtime behavior change (stable non-sticky assignment under modem instability)

---

## [2026-04-19] Permanent SIM Worker Auto-Alignment + Runtime Readiness Sync

### Summary
Added automated controls so outbound worker alignment survives SIM remap/reinsert events without manual process relaunch.

### What Changed
- Added:
  - `app/Console/Commands/SuperviseSimWorkersCommand.php`
    - long-running supervisor that auto-starts/stops/restarts `gateway:process-sim <sim_id>` workers to match active mapped SIM IDs
  - `app/Console/Commands/SyncRuntimeReadinessCommand.php`
    - syncs `disabled_for_new_assignments` using live runtime IMSI readiness
  - `app/Services/RuntimeSimSyncService.php`
    - runtime discovery + readiness evaluation + assignment-enable guardrail logic
  - `sms-sim-supervisor` service in `docker-compose.yml`
    - runs `php artisan gateway:supervise-sim-workers --poll=5`
- Updated:
  - `app/Console/Kernel.php`
    - schedules `gateway:sync-runtime-readiness` every minute (`withoutOverlapping`)
  - `docs/SIM_WORKER_ID_ALIGNMENT_RUNBOOK.md`
    - added permanent/no-manual-relaunch operating mode

### Status
- application/runtime behavior change (automation added)

---

## [2026-04-19] Test DB Safety Guardrails (Prevent Runtime DB Wipe During Tests)

### Summary
Added hard protections so PHPUnit/`php artisan test` cannot run against runtime DB (`sms_gateway_core`).

### What Changed
- Updated:
  - `phpunit.xml`
    - force testing DB to sqlite in-memory by default
- Updated:
  - `tests/TestCase.php`
    - pre-test safety guard:
      - requires `APP_ENV=testing`
      - blocks `DB_DATABASE=sms_gateway_core`
      - requires non-sqlite test DB names to include `test`
- Added:
  - `.env.testing.example`
    - safe baseline testing env values
  - `docs/TESTING_DB_SAFETY.md`
    - operator/developer runbook for safe test execution

### Status
- safety hardening update

---

## [2026-04-19] Inbound Relay De-Dup Hardening (UUID Webhook ID)

### Summary
Updated ChatApp inbound relay payload IDs to use immutable inbound UUIDs so DB id reuse cannot cause false duplicate suppression in ChatApp.

### What Changed
- Updated:
  - `app/Services/InboundRelayService.php`
    - relay payload now sends `ID=GW-IN-<inbound_message_uuid>` instead of numeric row id
- Updated:
  - `tests/Unit/Services/InboundRelayServiceTest.php`
    - added regression coverage asserting relay `ID` is UUID-based

### Status
- application/runtime behavior change (inbound relay contract hardening)

---

## [2026-04-19] Inbound Webhook Compatibility (Legacy Python Payload Aliases)

### Summary
Made inbound ingest accept legacy Python field aliases so webhook posts remain compatible during mixed-runtime rollouts.

### What Changed
- Updated:
  - `app/Http/Controllers/GatewayInboundController.php`
    - normalizes `from|mobile|phone` into `customer_phone`
    - accepts 15-digit IMSI sent in `sim_id` and maps it to `runtime_sim_id`
    - continues to accept numeric DB `sim_id` where provided
- Updated:
  - `tests/Feature/Http/GatewayInboundControllerTest.php`
    - added alias/compatibility behavior assertions

### Status
- application/runtime behavior change (broader inbound payload compatibility)

---

## [2026-04-19] Inbound Relay Retry Scheduler Cadence Increase

### Summary
Reduced inbound relay lag by running retry dispatch every minute.

### What Changed
- Updated:
  - `app/Console/Kernel.php`
    - schedules `gateway:retry-inbound-relays --limit=500` every minute with overlap protection
- Updated:
  - `tests/Feature/Smoke/Phase0SmokeTest.php`
    - schedule assertions updated for new retry cadence

### Status
- application/runtime behavior change (faster inbound relay recovery path)

---

## [2026-04-18] Ops Monitoring Dashboard (No-Auth Tailwind View)

### Summary
Added an operator-facing dashboard page to visualize outbound/inbound pipeline state without requiring API credentials in the browser.

### What Changed
- Added:
  - `app/Http/Controllers/OpsPanelController.php`
    - aggregates API client mapping, SIM runtime readiness, outbound progression, inbound relay status, queue depth, and recent failures
  - `resources/views/ops/index.blade.php`
    - Tailwind-styled monitoring table/cards for live troubleshooting
  - `tests/Feature/Web/OpsPanelTest.php`
    - feature coverage for page rendering/data sections
- Updated:
  - `routes/web.php`
    - registers ops panel route

### Status
- application/runtime behavior change (new monitoring surface)

---

## [2026-04-18] Inbound Relay Contract Hardening for ChatApp

### Summary
Hardened Laravel-to-ChatApp inbound relay rules so success is only recorded on explicit `{"ok":true}` acknowledgment, with consistent mobile normalization.

### What Changed
- Updated:
  - `app/Services/InboundRelayService.php`
    - treats HTTP 200 with `ok=false` as failure (not success)
    - normalizes `+63` mobile format to `09` before posting to ChatApp InfoTxt inbox
- Added:
  - `tests/Unit/Services/InboundRelayServiceTest.php`
    - success/failure and mobile-normalization coverage
- Updated:
  - `docs/GATEWAY_CHATAPP_DAILY_HEALTH_CHECK.md`
    - operator checks aligned with strict relay-ack behavior

### Status
- application/runtime behavior change (strict relay acknowledgment semantics)

---

## [2026-04-18] SIM Health Guardrail Against All-SIM Assignment Lockout

### Summary
Added a safety valve in SIM health auto-disable logic to prevent a company from ending up with zero assignment-eligible SIMs (`no_sim_available` lockout).

### What Changed
- Updated:
  - `app/Services/SimHealthService.php`
    - guardrail re-enables one SIM when all would otherwise be disabled for new assignments
    - preserves disable behavior when alternative assignment-eligible SIMs exist
- Updated:
  - `tests/Unit/Services/SimHealthServiceTest.php`
    - coverage for lockout-prevention and healthy/unhealthy toggle behavior

### Status
- application/runtime behavior change (assignment safety hardening)

---

## [2026-04-18] SIM Worker ID Alignment Runbook Added (Post-Remap Outbound Queue Incident)

### Summary
Added a dedicated runbook documenting the outbound queue issue caused by `gateway:process-sim` workers still running on old SIM IDs after SIM/company remap.

### What Changed
- Added:
  - `docs/SIM_WORKER_ID_ALIGNMENT_RUNBOOK.md`
    - incident signature (`queued`/`sending` drift with mixed send outcomes)
    - root cause (long-running workers bound to startup SIM ID)
    - deterministic recovery sequence:
      - restart `sms-worker`
      - relaunch workers for current SIM IDs
      - verify with `docker top`
      - validate outbound row progression
    - pre-flight rule after any remap/seed/restore operation
    - quick validation command pack

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-17] ChatApp Fast-Path Outbound Adapter (InfoTxt-Compatible)

### Summary
Added an InfoTxt-style outbound adapter endpoint so ChatApp can send through `sms-gateway-core` using existing `INFOTXT_*` contract fields.

### What Changed
- Added:
  - `app/Http/Controllers/InfotxtOutboundController.php`
    - accepts `UserID`, `ApiKey`, `Mobile`, `SMS` (+ optional `Type`/`MessageType`)
    - authenticates tenant, assigns SIM, creates outbound row, enqueues per-SIM queue, returns provider-style response
  - `app/Http/Middleware/AuthenticateInfotxtClient.php`
    - validates body credentials against `api_clients` (`api_key` + hashed `api_secret`)
  - `tests/Feature/Http/InfotxtOutboundControllerTest.php`
    - compatibility + auth + error-path coverage
- Updated:
  - `routes/api.php`
    - added `POST /api/v2/send.php` under `infotxt.client` + `tenant.resolve`
  - `app/Http/Kernel.php`
    - registered `infotxt.client` middleware alias

### Status
- application/runtime/API behavior change (new outbound integration surface)

---

## [2026-04-17] Laravel-Only Fail-to-Send Runbook + Admin Dashboard Task Spec Docs

### Summary
Added documentation for Laravel `sms-gateway-core` fail-to-send troubleshooting only, plus a new task specification for Admin Dashboard recovery controls.

### What Changed
- Added:
  - `docs/LARAVEL_FAIL_TO_SEND_CHECKLIST.md`
    - exact 13-point Laravel-only fail-to-send checklist
    - terminal command pack (with `cd` context)
    - one-click recovery bundle command (worker/Redis/pending-queue focused)
- Updated:
  - `docs/TASKS.md`
    - added `TASK 037 — Laravel Fail-to-Send Checklist + Admin Dashboard Recovery Controls` (OPEN)
    - defines dashboard task-list visibility for all 13 checks
    - defines one-click bundled action and separate per-action buttons
    - defines RBAC + audit requirements and closure gate

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-15] Operations Runbook — Daily Gateway ↔ ChatApp Health Check Added

### Summary
A separate daily operator checklist was added to verify Gateway inbound relay health to ChatApp in about one minute.

### What Changed
- Added:
  - `docs/GATEWAY_CHATAPP_DAILY_HEALTH_CHECK.md`
- Includes:
  - container/service health checks
  - worker-to-webhook reachability probe
  - relay status summary SQL checks
  - quick failure drilldown
  - optional live probe + pass criteria
  - incident-oriented log snapshot commands

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-14] TASK 036 Closure Checkpoint — Inbound Push Listener Reliability Proven Live

### Summary
`TASK 036` is now closed with live modem-to-Laravel inbound proof and artifact-backed closure evidence.

### What Changed
- `docs/TASKS.md` updated:
  - `TASK 036` moved from `OPEN` to `DONE`
  - evidence ledger block added with W1..W7 pass outcomes and artifact links
  - scope explicitly keeps telco/system inbound messages (no blanket sender filtering patch)
- Added closure artifacts under `artifacts/task-036/`:
  - `w2/laravel_resolution_snapshot.txt`
  - `w3/spool_retry_summary.txt`
  - `w4/ack_gate_summary.txt`
  - `w5/idempotency_snapshot.txt`
  - `w6/runtime_proof_snapshot.txt`
  - `w7/evidence_ledger.md`
- Added `docs/INBOUND_QUICK_VERIFY.md` for operator rapid validation workflow.
- `docs/DECISIONS.md` updated with sender-retention policy:
  - no blanket inbound sender filtering; preserve telco/system advisories as inbound truth

### Notes
- Live proof includes persisted inbound row for:
  - `idempotency_key=adc3bc55-9745-4d6e-ab4a-6c7d892dec0d`
  - runtime SIM identity `515039219149367`
- Startup SIM-drain ingestion of previously stored carrier/system messages is expected and retained by policy.

### Status
- documentation + artifact closure update
- no additional runtime/API behavior change in this commit

---

## [2026-04-13] Inbound Architecture Planning Checkpoint — Laravel-Owned Resolution + Durable Ingest

### Summary
Inbound reply architecture direction is now explicitly documented: Python stays standalone/transport-generic, while Laravel owns runtime-identity-to-tenant-SIM resolution and durable idempotent ingest semantics.

### What Changed
- `docs/DECISIONS.md` updated with two new decisions:
  - inbound runtime identity is Laravel-resolved (`runtime_sim_id`/`imsi` -> `sims.id`)
  - inbound reliability policy uses ACK-gated delete + durable spool + retry/backoff + idempotency key
- `docs/TASKS.md` updated:
  - added `TASK 036` (OPEN): inbound push-listener reliability and Laravel runtime-ID resolution
  - updated `Last Updated` metadata to include `TASK 036`

### Notes
- This is planning/documentation only; no runtime/API behavior changed in this commit.
- Laravel DB remains the inbound system-of-record; spool is temporary reliability buffering.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-13] Backlog Planning Checkpoint — TASK 033/034 Added

### Summary
Two explicit follow-on backlog tasks were added for future execution: realistic per-SIM physical send validation and dashboard manual runtime-SIM mapping workflow.

### What Changed
- `docs/TASKS.md` updated:
  - added `TASK 033` (OPEN): real modem daily-capacity validation (`4,000/day` profile per SIM), including `CHAT`, `FOLLOW_UP`, `BLASTING`, plus invalid/dummy-number failure-path validation
  - added `TASK 034` (OPEN): admin UI manual mapping workflow (`company/tenant -> SIM`) with API, RBAC, validation/conflict guards, audit logging, and runtime/send-test integration checks
  - updated `Last Updated` header metadata to `2026-04-13`

### Notes
- `TASK 033` is explicitly scoped to real execution path validation (`Laravel -> Python -> modem -> carrier`) and separates physical send capability from synthetic backend throughput.
- `TASK 034` closes the current mapping-write gap by defining a UI/API-backed manual mapping flow instead of CLI-only updates.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-13] Backlog Planning Checkpoint — TASK 035 OTP Priority Lane Added

### Summary
An explicit future backlog task was added for `OTP` as a first-class message type with priority-lane behavior and deterministic policy coverage.

### What Changed
- `docs/TASKS.md` updated:
  - added `TASK 035` (OPEN): OTP message type + priority lane
  - scope includes schema/validation, queue pop-priority, throttle/state policy, sticky-safe assignment behavior, and observability coverage
  - updated `Last Updated` metadata to include `TASK 035`

### Notes
- This is planning/documentation only; no runtime behavior changed in this commit.
- Python runtime remains transport-focused; OTP behavior is planned in Laravel control/routing layers.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 023 Closure Checkpoint — Load-Testing Readiness Evidence Recorded

### Summary
`TASK 023` readiness checkpoint is now documented as closed with linked W1..W6 evidence artifacts.

### What Changed
- `docs/TASKS.md` now marks `TASK 023` as `DONE` and records evidence outcomes for:
  - W1 baseline capacity snapshot
  - W2 intake/enqueue burst
  - W3 controlled short worker drain
  - W4 extended drain consistency + contradiction drilldown
  - W5 throughput/readiness interpretation
  - W6 closure ledger
- `docs/ROADMAP.md` now reflects Phase 5B as complete with `TASK 021/022/023` closure checkpoints done.
- `IMPLEMENTATION_PLAN.md` now reflects Phase 5B completion status and TASK 023 closure.

### Notes
- W2 captured `400` queued rows in ~`5.8s` (~`69 msg/s`) balanced across `4` SIMs.
- W3/W4 worker drain snapshots confirmed state-safe processing behavior under timeout simulation.
- W4 contradiction follow-up showed zero contradictions under corrected lifecycle criteria.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 022 Closure Checkpoint — Python Execution Scale-Out Readiness Recorded

### Summary
`TASK 022` closure evidence is now documented. Python execution-node readiness validation was completed for the current single-runtime deployment path.

### What Changed
- `docs/TASKS.md` now marks `TASK 022` as `DONE` and records `W1`..`W6` evidence outcomes:
  - W1 baseline endpoint readiness (`health`/`discover` baseline)
  - W2 moderate concurrent probe burst
  - W3 high concurrent probe burst
  - W4 runtime restart recovery
  - W5 soak stability
  - W6 closure ledger consolidation
- `docs/ROADMAP.md` now reflects `TASK 021` and `TASK 022` as complete, with `TASK 023` as the remaining Phase 5B open item.
- `IMPLEMENTATION_PLAN.md` now reflects `TASK 022` closure and `TASK 023` pending status.

### Notes
- W2 evidence: health/discover `60/60` status `200`
- W3 evidence: health/discover `200/200` status `200`
- W4 evidence: runtime restart path validated with post-restart probe health/discover `12/12` status `200`
- W5 evidence: in-container soak run health/discover `60/60` status `200` with no `000` failures

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Checkpoint — W7 Runbook Dry-Run Validation Recorded

### Summary
Evidence for `021-W7` was captured and ledgered with primary and secondary operator dry runs completed.

### What Changed
- `docs/TASKS.md` TASK 021 evidence ledger now records `W7` artifacts and marks `W7` as `PASS`.
- W7 artifact set references:
  - `artifacts/task-021/w7/primary_operator_dry_run.md`
  - `artifacts/task-021/w7/second_operator_dry_run.md`
  - `artifacts/task-021/w7/w7_completion_checklist.txt`

### Notes
- Both operator dry runs reported PASS across W2/W3/W4/W5/W6 procedures.
- Second-operator report confirmed execution without undocumented tribal knowledge.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Closure Checkpoint — W8 Evidence Ledger Gate Satisfied

### Summary
`TASK 021` closure review (`021-W8`) is completed. The evidence ledger is complete and acceptance criteria are satisfied.

### What Changed
- `docs/TASKS.md` now marks:
  - `TASK 021` status as `DONE`
  - `W8` as `PASS`
  - `W7` as `PASS` with linked runbook artifacts
- `docs/ROADMAP.md` updated Phase 5B active-path wording to reflect:
  - `TASK 021` closed
  - `TASK 022/023` remaining
- `IMPLEMENTATION_PLAN.md` updated to reflect `TASK 021` closure and remaining Phase 5B sequence.

### Notes
- `W1`..`W8` are all PASS in the TASK 021 ledger.
- `AC-021-01`..`AC-021-08` are satisfied by linked artifacts.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Checkpoint — W6 Worker Crash/Restart Recovery Semantics Recorded

### Summary
Evidence for `021-W6` was captured and ledgered for worker interruption recovery via stale-lock cleanup and restart-safe message-state reconciliation.

### What Changed
- `docs/TASKS.md` TASK 021 evidence ledger now records `W6` artifacts and marks `W6` as `PASS`.
- W6 artifact set references:
  - `artifacts/task-021/w6/run_tag.txt`
  - `artifacts/task-021/w6/target_sim.txt`
  - `artifacts/task-021/w6/seed.txt`
  - `artifacts/task-021/w6/worker_before_interrupt.log`
  - `artifacts/task-021/w6/forced_stale.txt`
  - `artifacts/task-021/w6/before_recovery_snapshot.txt`
  - `artifacts/task-021/w6/recover_command.txt`
  - `artifacts/task-021/w6/after_recovery_snapshot.txt`
  - `artifacts/task-021/w6/worker_after_restart.log`

### Notes
- Recovery command reported `Recovered stale outbound messages: 3`.
- Run-tag rows reconciled into pending retry-safe state with `locked_at` cleared and no contradiction rows.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Checkpoint — W5 Retry Scheduler + Worker Interaction Recorded

### Summary
Evidence for `021-W5` was captured and ledgered for retry scheduler behavior while concurrent workers were active on the same SIM queue scope.

### What Changed
- `docs/TASKS.md` TASK 021 evidence ledger now records `W5` artifacts and marks `W5` as `PASS`.
- W5 artifact set references:
  - `artifacts/task-021/w5/run_tag.txt`
  - `artifacts/task-021/w5/target_sim.txt`
  - `artifacts/task-021/w5/seed.txt`
  - `artifacts/task-021/w5/before_snapshot.txt`
  - `artifacts/task-021/w5/worker_1.log`
  - `artifacts/task-021/w5/worker_2.log`
  - `artifacts/task-021/w5/retry_scheduler.txt`
  - `artifacts/task-021/w5/after_snapshot.txt`
  - `artifacts/task-021/w5/sms_app_15m.log`
  - `artifacts/task-021/w5/scheduler_log_lines.txt`

### Notes
- Retry scheduler completed with deterministic counters (`due=6`, `claimed=6`, `enqueued=6`, `failures=0`) during active worker processing.
- Probe rows showed coherent retry-state progression with updated retry count and next scheduled retry timestamp.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Checkpoint — W4 Rebuild-Lock Interaction Under Active Workers Recorded

### Summary
Evidence for `021-W4` was captured and ledgered for queue rebuild-lock behavior while concurrent workers were active on the same SIM scope.

### What Changed
- `docs/TASKS.md` TASK 021 evidence ledger now records `W4` artifacts and marks `W4` as `PASS`.
- W4 artifact set references:
  - `artifacts/task-021/w4/target_sim.txt`
  - `artifacts/task-021/w4/run_tag.txt`
  - `artifacts/task-021/w4/seed.txt`
  - `artifacts/task-021/w4/worker_1.log`
  - `artifacts/task-021/w4/worker_2.log`
  - `artifacts/task-021/w4/rebuild_command.txt`
  - `artifacts/task-021/w4/after_snapshot.txt`

### Notes
- Rebuild command completed during active worker window and reported deterministic lock-scoped completion for SIM `225`.
- Post-run snapshot showed no contradiction rows and no persistent rebuild lock after completion.
- Queue depth remained consistent with one pending row re-enqueued by rebuild.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Checkpoint — W3 SIM/Tenant Isolation Under Worker Scale-Out Recorded

### Summary
Evidence for `021-W3` was captured and ledgered from concurrent mixed-SIM worker execution using run-tagged probe rows.

### What Changed
- `docs/TASKS.md` TASK 021 evidence ledger now records `W3` artifacts and marks `W3` as `PASS`.
- W3 artifact set references:
  - `artifacts/task-021/w3/precheck_sims.txt`
  - `artifacts/task-021/w3/precondition_override.txt`
  - `artifacts/task-021/w3/precheck_normals.txt`
  - `artifacts/task-021/w3/run_tag.txt`
  - `artifacts/task-021/w3/seed.txt`
  - `artifacts/task-021/w3/worker_sim1.log`
  - `artifacts/task-021/w3/worker_sim2.log`
  - `artifacts/task-021/w3/outcome_snapshot.txt`

### Notes
- Two active NORMAL SIM scopes were validated in the same run (`225`, `228`) with 8 probe rows.
- Row-level snapshots preserved `company_id` and `sim_id` parity with probe metadata.
- `contradictions` remained empty in the captured run-tag evidence.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] TASK 021 Checkpoint — W2 Concurrent Worker Claim/Pop Integrity Recorded

### Summary
Evidence for `021-W2` was captured and ledgered from concurrent same-SIM worker execution with forced runtime timeout behavior.

### What Changed
- `docs/TASKS.md` TASK 021 evidence ledger now records `W2` artifacts and marks `W2` as `PASS`.
- W2 artifact set references:
  - `artifacts/task-021/w2/run_tag.txt`
  - `artifacts/task-021/w2/seed.txt`
  - `artifacts/task-021/w2/worker_1.log`
  - `artifacts/task-021/w2/worker_2.log`
  - `artifacts/task-021/w2/claimed_lines.txt`
  - `artifacts/task-021/w2/claimed_counts.txt`
  - `artifacts/task-021/w2/outcome_snapshot.txt`

### Notes
- Two workers were launched concurrently against overlapping SIM scope in a bounded timeout window.
- Row-state evidence showed deterministic lifecycle transitions with no contradictory/duplicate completion.
- Claim-line grep output remained empty for this run due to emitted log pattern mismatch; full worker logs and row-state snapshots were used for integrity conclusions.

### Status
- documentation update only
- no application/runtime/API behavior changes

---

## [2026-04-12] Docs Planning Checkpoint — TASK 021 Worker Scale-Out Checklist Formalized

### Summary
Documentation-only planning update. TASK 021 is now formalized as a strict execution checklist and activated as the current Phase 5B entry point.

### What Changed
- `docs/TASKS.md` now defines TASK 021 with:
  - objective
  - scope in / scope out
  - worker scale-out checklist items `021-W1`..`021-W8`
  - evidence required per item
  - pass/failure follow-up conditions
  - acceptance criteria `AC-021-01`..`AC-021-08`
  - done/closure gate + evidence ledger location
- `docs/TASKS.md` Phase 6 status text now reflects closure of TASK 031 and TASK 032.
- `docs/ROADMAP.md` now reflects:
  - Phase 6 follow-up closure (`TASK 031` + `TASK 032` done)
  - Phase 5B as active path with `TASK 021` in progress and `TASK 022/023` queued.
- `IMPLEMENTATION_PLAN.md` now reflects Phase 6 closure and TASK 021 kickoff under Phase 5B.

### Boundary Preserved
- TASK 021 remains Laravel worker scale-out only.
- TASK 022 remains Python execution scale-out only.
- TASK 023 remains load/throughput validation only.
- No runtime page UI/operator maturity scope was reopened.

### Status
- docs/planning alignment update only
- no product/runtime/API/auth behavior changes

---

## [2026-04-12] Docs Planning Checkpoint — TASK 032 Send-Path Maturity Checklist Formalized

### Summary
Documentation-only planning update. TASK 032 is now formalized as a strict execution checklist with evidence requirements, pass/fail conditions, acceptance criteria, and an explicit closure gate.

### What Changed
- `docs/TASKS.md` now defines TASK 032 with:
  - objective
  - scope in / scope out
  - send-path maturity checklist items `032-S1`..`032-S8`
  - evidence required per item
  - pass/failure follow-up conditions
  - acceptance criteria `AC-032-01`..`AC-032-08`
  - done/closure gate + evidence ledger location
- `docs/ROADMAP.md` now reflects:
  - TASK 031 marked DONE (artifact-backed closure)
  - TASK 032 moved to IN PROGRESS under strict checklist governance
- `IMPLEMENTATION_PLAN.md` now reflects TASK 032 strict-checklist governance and explicit Phase 5B handoff-boundary responsibility.

### Boundary Preserved
- TASK 032 remains deeper send-path maturity only.
- Runtime page UI/operator maturity slices remain implemented (6.4/6.5/6.6) and are not reopened.
- TASK 021/022/023 remain deferred under Phase 5B scale/load work.
- Runtime SIM ID and Tenant SIM DB ID boundaries remain unchanged.

### Status
- docs/planning alignment update only
- no product/runtime/API/auth behavior changes

---

## [2026-04-11] Docs Planning Checkpoint — TASK 031 Hardening Checklist Formalized

### Summary
Documentation-only planning update. TASK 031 is now formalized as a strict execution checklist with evidence requirements, pass/fail conditions, acceptance criteria, and an explicit closure gate.

### What Changed
- `docs/TASKS.md` now defines TASK 031 with:
  - objective
  - scope in / scope out
  - hardening checklist items `031-H1`..`031-H8`
  - evidence required per item
  - pass/failure follow-up conditions
  - acceptance criteria `AC-031-01`..`AC-031-07`
  - done/closure gate + evidence ledger location
- `docs/ROADMAP.md` now points Phase 6 follow-up to the strict TASK 031 checklist and AC-based closure gate.
- `IMPLEMENTATION_PLAN.md` now states TASK 031 is governed by the strict checklist and artifact-linked closure gate.

### Boundary Preserved
- TASK 031 remains hardening/reliability only.
- TASK 032 remains deeper send-path maturity only.
- TASK 021/022/023 remain deferred under Phase 5B scale/load work.
- Implemented 6.4/6.5/6.6 runtime/operator UI maturity remains documented as completed and is not reopened.

### Status
- docs/planning alignment update only
- no product/runtime/API/auth behavior changes

---

## [2026-04-11] Phase 6 Realignment Checkpoint — Runtime/UI Maturity Reflected (In Progress)

### Summary
Docs are realigned to current repo reality. Phase 6 remains IN PROGRESS, and implemented scope now explicitly includes runtime/operator maturity work delivered after the 6.1/6.2 foundation and send bridge.

### Implemented Phase 6 Scope (Current Reality)
- Phase 6.1: Laravel↔Python runtime contract foundation (`/health`, `/modems/discover`)
- Phase 6.2: Structured Laravel→Python send execution bridge with normalized runtime failures
- Phase 6.3: Retry reliability + SIM runtime suppression/control behavior
- Phase 6.4.a–6.4.d: Runtime fleet observability, row safety semantics, diagnostics drilldown, empty/failure/refresh clarity
- Phase 6.5.a–6.5.d: Action intent clarity, selected-target clarity, reset context UX, lightweight operator guidance
- Phase 6.6.a–6.6.b: Mapping review visibility + reconciliation context explanation (read-only)

### Scope Boundary Clarification
- Implemented 6.4/6.5/6.6 slices are operator/runtime UI maturity and reconciliation visibility.
- These slices are not final runtime hardening completion and do not introduce mapping-write workflows.
- Runtime SIM ID and Tenant SIM DB ID remain explicitly distinct.
- Send-test and Laravel-side actions continue to use Tenant SIM DB ID (`sims.id`) only.

### Remaining Open Scope
- TASK 031 (IN PROGRESS): live-fleet reliability hardening follow-ups
- TASK 032 (OPEN): deeper send-path maturity + later scale handoff
- TASK 021/022/023 remain deferred under Phase 5B scale/load path

### Validation
- current documented full-suite baseline remains: 286 passed

### Status
- Phase 4 COMPLETE (Locked)
- Phase 5A COMPLETE (Locked; TASK 028 closure/docs boundary finalized)
- Phase 5B NOT STARTED (future scale path)
- Phase 6 IN PROGRESS (implemented through 6.6.b; hardening/maturity work remains open)
- Python runtime remains external to this Laravel repo

---

## [2026-04-11] Phase 6 Runtime Validation Milestone — Real End-to-End Send Verified (In Progress)

### Summary
Phase 6 remains active. This milestone records real-world runtime validation in the live environment: Laravel triggered Python runtime execution through a real modem path and SMS delivery was physically confirmed.

### Milestone Proven (Current Reality)
- Laravel runtime page successfully reached Python runtime health/discovery surfaces
- Python runtime discovery returned live modem rows and runtime state
- Laravel dashboard send-test triggered real Python `/send` execution against modem hardware
- Python send execution path completed successfully with structured response
- destination device received the SMS message (physical delivery validated)
- runtime UI safety guardrails are in place:
  - full discovery row visibility
  - send-test disabled for non-send-ready rows
  - clear row-level disabled reasons for operator safety
- runtime identity mapping lesson is now explicit and operationally validated:
  - Runtime SIM ID (Python discovery value: IMSI/fallback runtime identifier) is not the same as Laravel SIM DB ID
  - dashboard send-test and Laravel-side actions must use tenant `sims.id` (Tenant SIM DB ID)
  - mixing identifiers produced `sim_not_found`; mapping and UI clarification resolved this
- runtime UI now distinguishes runtime SIM identity from tenant SIM DB identity for safer operator use
- operational troubleshooting lesson captured:
  - send-test failures with `error=SEND_FAILED` + `error_layer=network` may indicate SIM load/balance/carrier issue
  - runtime send-test UI now includes a direct operator tip for this case

### Validation
- Laravel full-suite baseline remains as last documented checkpoint: 286 passed
- additional live runtime validation completed in real environment (hardware send path proven)

### Status
- Phase 4 COMPLETE (Locked)
- Phase 5A IN PROGRESS (near completion)
- Phase 5B NOT STARTED (future scale path)
- Phase 6 IN PROGRESS (6.1 foundation + 6.2 send bridge + real end-to-end runtime send now validated)
- Python runtime remains external to this Laravel repo
- broader runtime hardening/reliability/scaling maturity remains open

---

## [2026-04-10] Phase 6.2 Checkpoint — Structured Laravel→Python Send Execution Bridge (In Progress)

### Summary
Phase 6 remains active as the runtime/hardware integration track. This checkpoint records Phase 6.2 progress: the first structured Laravel→Python send execution bridge built on top of the Phase 6.1 runtime foundation.

### Implemented Phase 6.2 Progress (Current Reality)
- Python send contract was reused from existing runtime integration (`/send`)
- Laravel now has a structured runtime send path through the runtime client/service layer
- `PythonRuntimeClient` now normalizes send-path runtime failures
- `PythonApiSmsSender` now routes send execution through `PythonRuntimeClient`
- runtime diagnostics are persisted into `outbound_messages.metadata` for execution traceability
- controlled dashboard/manual verification surface added for runtime send-test execution
- explicit runtime failure classes now include:
  - `runtime_unreachable`
  - `runtime_timeout`
  - `invalid_response`

### Validation
- full suite green: 286 passed

### Status
- Phase 4 COMPLETE (Locked)
- Phase 5A IN PROGRESS (near completion)
- Phase 5B NOT STARTED (future scale path)
- Phase 6 IN PROGRESS (Phase 6.1 + 6.2 checkpoints implemented)
- Python runtime remains external to this Laravel repo
- deeper runtime hardening, broader real-world fleet validation, and scale/perf work remain open

---

## [2026-04-09] Phase 6.1 Checkpoint — Python Runtime Health/Discovery Foundation (In Progress)

### Summary
Phase 6 is now active as the runtime/hardware integration track. This checkpoint records the Phase 6.1 foundation slice only: Laravel runtime connectivity to the external Python service for health/discovery visibility.

### Implemented Phase 6.1 Foundation (Current Reality)
- Python runtime remains external to this Laravel repo (no Python runtime implementation moved into Laravel)
- Laravel now has a dedicated Python runtime client/service for contract-based runtime calls
- current Laravel↔Python runtime contract foundation uses:
  - `GET /health`
  - `GET /modems/discover`
- read-only runtime inspection surface added in Laravel:
  - dashboard page
  - dashboard API endpoint
- discovery visibility is tenant-filtered in Laravel via tenant SIM IMSI matching

### Validation
- full suite green: 276 passed

### Status
- Phase 4 COMPLETE (Locked)
- Phase 5A IN PROGRESS (near completion)
- Phase 5B NOT STARTED (future scale path)
- Phase 6 IN PROGRESS (Phase 6.1 foundation checkpoint only)
- Phase 6 real send-execution runtime work is not complete in this slice

---

## [2026-04-09] Phase 5A Checkpoint — Dashboard/Auth/Operator System Realigned (In Progress)

### Summary
Phase 5 is now split into two explicit tracks for accuracy:
- Phase 5A: Dashboard/Auth/Operator System (current execution track)
- Phase 5B: Scale/Infrastructure/Throughput (future track)

This checkpoint records current repo reality for Phase 5A without marking lock/complete yet.

### Implemented Phase 5A Scope (Current Reality)
- dashboard session authentication:
  - `/login`, `/logout`
  - login-protected `/dashboard*` routes
- session-based server-side dashboard bridge:
  - `/dashboard/api/*` (no browser-side raw API secret dependency)
- explicit tenant binding via `users.company_id`
- dashboard RBAC with `owner|admin|support`
- operator management:
  - tenant-local operator listing
  - owner-only operator creation
  - owner-only role update
  - owner-only temporary-password reset/regeneration
  - owner-only activation/deactivation
- temporary password safety flows:
  - forced first-login password change (`must_change_password`)
  - self-service password change for authenticated operators
- read-only My Account page
- tenant-local operator audit trail:
  - write action logging for dashboard/session control paths
  - audit log page + API
  - filters (`action`, `actor_user_id`, `date_from`, `date_to`) + text search
- shared dashboard layout + UX hardening:
  - shared layout/nav/page-title conventions
  - tenant/operator identity context banner
  - operator list filter/sort/search

### Validation
- full suite green: 267 passed

### Status
- Phase 4 COMPLETE (Locked)
- Phase 5A IN PROGRESS (near completion)
- Phase 5B NOT STARTED (future scale path)
- Phase 2 remains locked
- Phase 3 scope remains absorbed into Phase 2

---

## [2026-04-08] Phase 4 Complete and Locked

### Summary
Phase 4 core scope is complete and locked. Tenant-safe operator API surfaces and core dashboard/operator pages are implemented, tested, and aligned with the final Phase 4 boundary.

### Lock Scope Completed
- tenant-safe operator/read APIs:
  - `/api/messages/send`
  - `/api/messages/bulk`
  - `/api/messages/status`
  - `/api/sims`
  - `/api/assignments`
  - `/api/assignments/set`
  - `/api/assignments/mark-safe`
  - `/api/admin/sim/{id}/status`
  - `/api/admin/sim/{id}/enable-assignments`
  - `/api/admin/sim/{id}/disable-assignments`
  - `/api/admin/sim/{id}/rebuild-queue`
  - `/api/admin/migrate-single-customer`
  - `/api/admin/migrate-bulk`
  - `/api/admin/rebalance`
- operator dashboard pages:
  - `/dashboard`
  - `/dashboard/sims`
  - `/dashboard/assignments`
  - `/dashboard/sims/{id}`
  - `/dashboard/migration`
  - `/dashboard/messages/status`
- UX baseline polish:
  - shared credential persistence
  - cross-page navigation
  - improved action/status visibility
  - useful deep links

### Validation
- full suite green: 205 passed

### Deferred Beyond Phase 4
- advanced monitoring analytics
- deeper error-tracking stack
- non-essential future UI polish iterations
- scale-oriented operator tooling

### Status
- Phase 4 COMPLETE (Locked)
- Phase 5 not started
- Phase 2 remains locked
- Phase 3 scope remains absorbed into Phase 2

---

## [2026-04-08] Phase 4 Checkpoint — Rebalance + Bulk Send API Closure (In Progress)

### Summary
Phase 4 remains IN PROGRESS. This checkpoint closes the remaining active API stubs by implementing tenant-safe rebalance and minimal bulk/blasting intake while preserving existing transport and tenancy rules.

### Implemented This Checkpoint

#### API Surface Completion
- `POST /api/admin/rebalance` implemented via conservative tenant-scoped rebalance flow
  - requires explicit `from_sim_id` and `to_sim_id`
  - moves only eligible/safe assignment state through existing migration service logic
- `POST /api/messages/bulk` implemented as minimal tenant-authenticated bulk intake
  - accepts `messages[]` payload
  - reuses existing single-send intake semantics per item
  - returns per-item result rows (success/failure, message id, error details)

#### Focused Validation
- feature coverage updated for `/api/messages/bulk` mixed outcomes and per-item validation behavior
- full suite: 205 passed

### Status
- Phase 4 IN PROGRESS
- Phase 4 backend/API surfaces: complete for current scope
- Core dashboard/operator UI surfaces: implemented
- Phase 4 remains open for broader monitoring/analytics/error-tracking depth
- Phase 2 remains locked
- Phase 3 scope remains absorbed into Phase 2 (already locked)

---

## [2026-04-07] Phase 4 Checkpoint — Dashboard Surfaces + UX Polish (In Progress)

### Summary
Phase 4 is now in a backend+frontend checkpoint state. Backend/operator APIs remain complete, and the first operator dashboard surfaces are now implemented and tested. Phase 4 remains IN PROGRESS.

### Implemented This Checkpoint

#### Dashboard Pages (Blade + inline JS)
- `/dashboard` — dashboard home/navigation landing page
- `/dashboard/sims` — SIM fleet read-only visibility powered by `GET /api/sims`
- `/dashboard/assignments` — assignment visibility powered by `GET /api/assignments`
- `/dashboard/sims/{id}` — SIM detail/control page using existing admin/control APIs
- `/dashboard/migration` — migration workflow UI using existing assignment/migration APIs
- `/dashboard/messages/status` — message status lookup powered by `GET /api/messages/status`

#### UX Polish Pass
- shared local credential persistence (`X-API-KEY`, `X-API-SECRET`) across dashboard pages
- consistent cross-page dashboard navigation links
- clearer action-status messaging (action results preserved across post-action refresh)
- direct SIM-detail links from fleet/assignment/migration tables
- no backend API redesign and no schema changes

### Validation
- full suite: 196 passed

### Status
- Phase 4 IN PROGRESS
- Backend API control surfaces: complete
- Core dashboard/operator UI surfaces: implemented
- Remaining: broader monitoring/analytics/error-tracking depth and later scale-oriented operator tooling
- Phase 2 remains locked
- Phase 3 not started

---

## [2026-04-06] Phase 4 Checkpoint — Backend API Control Surfaces (In Progress)

### Summary
Phase 4 backend/API slices are now implemented and tested. These are backend-only additions: no frontend dashboard, no UI, no schema changes. Phase 4 is IN PROGRESS — backend complete at this checkpoint, dashboard/frontend not yet started.

### Implemented This Checkpoint

#### Read-Only Visibility APIs
- `GET /api/sims` — per-SIM list with health status, queue depth, and assignment flags; `SimController` + `SimHealthService` + `RedisQueueService`
- `GET /api/messages/status` — message status lookup by `client_message_id` (required); optional `sim_id` filter; tenant-scoped; `MessageStatusController`
- `GET /api/assignments` — customer-SIM assignment list with nested SIM object; optional `customer_phone` and `sim_id` filters; tenant-scoped; `AssignmentController`

#### Admin/Control APIs
- `POST /api/admin/sim/{id}/status` — set operator status (`active`/`paused`/`blocked`) via existing `SimOperatorStatusService`; `SimAdminController`
- `POST /api/admin/sim/{id}/enable-assignments` — set `accept_new_assignments=true`; `SimAdminController`
- `POST /api/admin/sim/{id}/disable-assignments` — set `accept_new_assignments=false`; `SimAdminController`
- `POST /api/admin/migrate-single-customer` — migrate one customer's assignment + pending/queued messages via existing `SimMigrationService`; `MigrationController`
- `POST /api/admin/migrate-bulk` — bulk migrate all assignments + pending/queued messages via existing `SimMigrationService`; `MigrationController`
- `POST /api/admin/sim/{id}/rebuild-queue` — trigger per-SIM Redis queue rebuild from DB truth via existing `QueueRebuildService`; returns 409 Conflict if lock already held; `SimAdminController`

#### Intentional Exclusions This Checkpoint
- `StaleLockRecoveryService` not exposed as tenant API: it is system-scoped (no `company_id`), queries all tenants globally — wrong blast radius and wrong scope for tenant control surface; remains scheduled-maintenance-only
- Dashboard/frontend: not started; this checkpoint is backend API only

### Validation
- full suite: 175 passed

### Status
- Phase 4 IN PROGRESS
- Backend API control surfaces: complete
- Frontend dashboard: NOT STARTED
- Per-modem send lock: deferred (Python-owned, outside Phase 4 scope)

---

## [2026-04-06] Phase 2 Complete and Locked

### Summary
Phase 2 is now formally complete and locked. All Phase 2 scope items are implemented, tested, and live-proven. One item (Python-side per-modem send lock) is explicitly deferred as Python-owned hardware-safe execution behavior outside Phase 2 lock scope.

### Lock Result
- full suite green: 120 passed
- all Phase 2 tasks complete: 012A, 012B, 012C, 012D, 012E, 012F, 013, 014, 015, 016
- transport architecture (Redis per-SIM queues, rebuild lock, retry policy) live-proven
- authenticated Laravel↔Python↔modem end-to-end send live-proven
- all success / retry / terminal failure / stale lock paths confirmed live
- `sims.last_success_at` correctly populated and health check validated
- bootstrap seeders in place for fresh-clone dev setup

### Explicit Deferral
- **per-modem send lock** — Python-owned concurrency guard for serial port access; deferred outside Phase 2 lock scope
  - does not affect correctness of current single-modem live setup
  - Python may implement as hardware-safe execution behavior per locked layer ownership rules
  - to be addressed as a Python-side item before multi-modem concurrent load

### Phase 3 / Phase 4
- Phase 3 items were absorbed into Phase 2 (see ROADMAP.md)
- Phase 4 (monitoring, stuck-age, dashboard, admin APIs) is NOT STARTED

---

## [2026-04-06] Phase 2 Slice Checkpoint — SimHealthService Validation Complete (In Progress)

### Summary
Validated `SimHealthService` and `CheckSimHealthCommand` behavior now that `sims.last_success_at` is correctly populated by the live system. Three additive tests added to `SimHealthServiceTest`. No service or command code changed.

### Completed In This Slice
- 3 tests added to `SimHealthServiceTest`:
  - `compute_stuck_age_returns_all_true_when_last_success_at_is_null` — pins the null case (all stuck flags true), which was the universal pre-fix production state
  - `check_health_returns_correct_full_shape_for_healthy_sim_with_recent_last_success_at` — pins the healthy result shape: `status=healthy`, `minutes_since_last_success` is a real integer, all `stuck.*` false, `reason=null`
  - `is_unhealthy_returns_true_at_exactly_30_minute_boundary` — pins `>=` boundary: exactly 30 minutes is unhealthy
- existing `CheckSimHealthCommandTest` tests remain green against real-timestamp scenarios
- `SimHealthService` and `CheckSimHealthCommand` are now fully validated for the corrected `last_success_at` data path

### Validation
- full suite currently green: 120 passed

### Status
- Phase 2 IN PROGRESS
- SimHealthService / CheckSimHealthCommand validation item is now closed
- Remaining open Phase 2 hardening item: per-modem send lock on Python side

---

## [2026-04-06] Phase 2 Slice Checkpoint — Python API Authentication Complete (In Progress)

### Summary
Python API shared-secret authentication is now complete and live-proven on both sides. Laravel sends `X-Gateway-Token`; Python validates it. Authenticated end-to-end send confirmed with physical SMS received.

### Completed In This Slice
- `SMS_PYTHON_API_TOKEN` config key added (`config/sms.php`, `env('SMS_PYTHON_API_TOKEN', '')`)
- `PythonApiSmsSender` now sends `X-Gateway-Token` header when token is configured; omits header when empty (backward-safe default for deployments that have not yet configured auth)
- Python engine `/send` (and other protected endpoints) now validates `X-Gateway-Token`; rejects missing or wrong token
- `/health` endpoint intentionally left open (no auth required)
- two tests added to `PythonApiSmsSenderTest`:
  - `it_sends_auth_header_when_token_is_configured`
  - `it_does_not_send_auth_header_when_token_is_not_configured`
- `.env.example` updated with `SMS_PYTHON_API_TOKEN=` entry
- authenticated live send proven end-to-end: Laravel → Python (with token) → modem → physical SMS received
- `outbound_messages.status=sent`, `sims.last_success_at` and `last_sent_at` updated correctly on authenticated send

### Validation
- full suite currently green: 117 passed

### Status
- Phase 2 IN PROGRESS
- Task 012A Python API authentication is now complete
- Remaining open items: per-modem send lock on Python side; `SimHealthService`/`CheckSimHealthCommand` live validation against populated `last_success_at`

---

## [2026-04-06] Phase 2 Slice Checkpoint — Live Smoke Test Proven + last_success_at Fix + Bootstrap Seeders (In Progress)

### Summary
Full Laravel↔Python↔modem integration smoke test completed and proven live. Bug fix: `sims.last_success_at` was not being persisted on successful sends. Bootstrap seeders added for fresh-clone dev setup.

### Completed In This Slice

#### Live Integration Smoke Test — Proven End-to-End
All checklist items confirmed in Docker runtime against real hardware:
- Python health/discover/modems health endpoints pass
- Laravel config/Redis/DB connectivity verified in Docker
- IMSI cross-reference passes (Laravel SIM record → Python routing)
- transport failure path proven (PythonApiSmsSender ConnectionException → `transport` errorLayer → retry)
- retry re-enqueue path proven (retry scheduler picks up pending+scheduled_at row, enqueues to Redis)
- success path proven: physical SMS received; `outbound_messages.status=sent`, `sent_at` populated
- `sims.last_success_at` and `sims.last_sent_at` update correctly after success (post-fix)
- `sim_daily_stats.sent_count` increments correctly after success
- Redis queue depth drains to 0 after successful send
- terminal failure path proven using temporary Python stub (`/dev/stub/send-network-fail`):
  - `status=failed`, `scheduled_at=null` confirmed
  - retry scheduler run confirmed zero eligible rows (status=failed is invisible to scheduler)
- stale lock check: no orphaned sending rows after clean run
- temporary `.env` stub path override removed; normal `/send` path restored

#### Bug Fix — `sims.last_success_at` Not Persisting
- Root cause: `SimStateService::markSendSuccess()` set `$sim->last_sent_at = now()` but never set `$sim->last_success_at`
- Fix: added `$sim->last_success_at = now();` immediately after `last_sent_at` assignment in `SimStateService:111`
- Both fields are now persisted on all three code paths (BURST, BURST→COOLDOWN, NORMAL) via the existing `$sim->save()` calls
- `SimStateServiceTest` added covering all three paths (normal, burst below limit, burst-into-cooldown)

#### Bootstrap Seeders Added
Idempotent bootstrap seeders for fresh-clone dev setup (`php artisan migrate --seed`):
- `BootstrapCompanySeeder` — one default active company, keyed on `code='bootstrap'`
- `BootstrapModemSeeder` — one placeholder modem (`status=offline`)
- `BootstrapSimSeeder` — one active SIM, placeholder IMSI (`000000000000000`), env() overridable
- `BootstrapApiClientSeeder` — one active API client; api_secret hashed once on first create only
- `DatabaseSeeder` updated to call all four in dependency order
- env() fallbacks: `BOOTSTRAP_COMPANY_NAME`, `BOOTSTRAP_SIM_PHONE`, `BOOTSTRAP_SIM_IMSI`, `BOOTSTRAP_API_KEY`, `BOOTSTRAP_API_SECRET`

#### Minor Dev Affordance
- `SMS_PYTHON_API_SEND_PATH` config key added (`config/sms.php`, backed by `PythonApiSmsSender`)
- Allows configuring the HTTP path appended to `SMS_PYTHON_API_URL` without code changes
- Default: `/send` (production behavior unchanged)
- Used for smoke test stub proof; `.env` override since removed

### Validation
- full suite currently green: 115 passed

### Status
- Phase 2 IN PROGRESS
- Phase 3 not started
- `sims.last_success_at` bug now closed; `SimHealthService` and `CheckSimHealthCommand` will now receive real data
- Remaining open items: Python API authentication (shared secret), per-modem send lock on Python side, SimHealthService validation against live-populated `last_success_at`

---

## [2026-04-04] Phase 2 Slice Checkpoint — errorLayer-Aware Retry Policy (In Progress)

### Summary
Implemented Laravel-side errorLayer-driven retry differentiation. Network-layer failures (Python-confirmed carrier/provider rejection) now become terminal. All other error layers remain retriable.

### Completed In This Slice
- `OutboundRetryService::handlePermanentFailure()` added — marks `status='failed'`, no `scheduled_at`, no retry
- `SimQueueWorkerService::markMessageFailed()` now routes on `errorLayer`:
  - `network` → permanent failure (`status='failed'`, `scheduled_at=null`)
  - all other layers (transport, hardware, modem, gateway, unknown, null) → existing retry path
- `PythonApiSmsSender` corrected: `ConnectionException` (Laravel→Python TCP failure) now classified as `errorLayer='transport'` instead of `'network'`; prevents Python outage from permanently killing messages
- tests added/updated:
  - `OutboundRetryServiceTest` — permanent failure test
  - `SimQueueWorkerServiceRedisTest` — network layer terminal test, non-network retry test, null errorLayer retry test
  - `PythonApiSmsSenderTest` — updated ConnectionException assertion to `'transport'`

### Validation
- full suite currently green: 112 passed

### Status
- Phase 2 IN PROGRESS
- Phase 3 not started
- TASK 012A Laravel-side errorLayer retry gap is now closed
- Remaining open items: Python API authentication (shared secret), per-modem send lock on Python side

---

## [2026-04-04] Phase 2 Slice Checkpoint — Redis Transport + Rebuild Wiring + Laravel Python Integration (In Progress)

### Summary
Recorded the current Phase 2 implementation slice without marking Phase 2 complete.

### Completed In This Slice
- Redis queue transport implemented (`RedisQueueService`)
- queue rebuild + rebuild lock implemented (`QueueRebuildService`)
- normalization/init/rebuild/retry commands implemented:
  - `gateway:normalize-paused-queued-to-pending`
  - `gateway:rebuild-sim-queue`
  - `gateway:init-queue-migration`
  - `gateway:retry-scheduler`
- outbound controller updated to Phase 2 intake semantics (active enqueue, paused pending, blocked reject)
- worker rewritten to Redis pop + rebuild-lock check + DB-truth recheck (`SimQueueWorkerService`)
- paused→active auto-requeue event/listener wiring implemented
- retry scheduler wired in Kernel
- focused Phase 2 infrastructure tests added:
  - `RedisQueueServiceTest`
  - `QueueRebuildServiceTest`
  - `RebuildSimQueueCommandTest`
  - `RetrySchedulerCommandTest`
  - `NormalizePausedQueuedToPendingCommandTest`
  - `InitializeQueueMigrationCommandTest`
  - `SimQueueWorkerServiceRedisTest`
- Laravel-side Python integration slice added:
  - `sims.imsi` migration added
  - `Sim` model includes `imsi`
  - `SmsSendResult` includes `errorLayer`
  - `PythonApiSmsSender` aligned to Python engine contract
  - `PythonApiSmsSenderTest` added
- supporting test/helper updates added

### Validation
- full suite currently green: 109 passed

### Status
- Phase 2 IN PROGRESS
- Phase 3 not started
- Python execution service/runtime stabilization is still not complete

---

## [2026-04-03] Phase 1 Complete and Locked

### Summary
Phase 1 manual migration baseline is complete and locked.
This is the final authoritative Phase 1 status; earlier 2026-04-03 slice checkpoint entries below are historical progress records.

### Completed
- manual migration baseline implemented
- single-customer migration command implemented
- bulk migration command implemented
- automatic failover CLI entry points disabled
- automatic reassignment path disabled in `CustomerSimAssignmentService::reassignSim()`
- stale recovery aligned to DB-first same-SIM retry behavior
- Phase 1 tests green

### Status
- Phase 2 not started

---

## [2026-04-03] Phase 1 Slice Checkpoint — Manual Reassignment Path Disabled (In Progress)

### Summary
Locked an additional Phase 1 safety checkpoint without starting new Phase 1 feature scope.

### Completed In This Slice
- `CustomerSimAssignmentService::reassignSim()` automatic reassignment path disabled for manual-migration-only compliance
- focused unit test added for disabled `reassignSim` behavior
- full test suite currently green: 61 passed

### Status
- Phase 1 is IN PROGRESS
- Phase 1 is not complete
- Phase 2 has not started

---

## [2026-04-03] Phase 1 Slice Checkpoint — Manual Migration Baseline (In Progress)

### Summary
Locked the current Phase 1 slice checkpoint without starting the next Phase 1 item.

### Completed In This Slice
- automatic failover CLI entry points hard-disabled (`gateway:failover-sim`, `gateway:scan-failover`)
- `SimMigrationService` added for DB-first manual migration baseline
- single-customer migration command added (`gateway:migrate-single-customer`)
- bulk migration command added (`gateway:migrate-sim-customers`)
- stale recovery aligned for DB-first migration safety and same-SIM retry behavior
- Phase 1 slice tests added for migration service/commands, failover-disabled commands, and recovery command behavior

### Status
- Phase 1 is IN PROGRESS
- Phase 1 is not complete
- Phase 2 has not started

---

## [2026-03-31] Phase 0 Complete and Locked

### Summary
Phase 0 implementation is complete and locked.

### Completed
- SIM operator controls completed
- health checks completed
- retry policy updated to fixed 5-minute forever retry
- operator commands completed
- outbound intake guardrails completed

### Validation
- Phase 0 tests passed: 39/39

### Status
- Phase 1 not started

---

## [2026-03-29] Architecture Lock — SIM-Centric Redis + Worker Model Finalized

### Summary
Locked the final SIM-centric queueing and operational architecture for the SMS Gateway Core before implementation.

This is a major architecture clarification and documentation update.  
It does not represent the final code implementation yet, but it is now the authoritative design baseline for the next implementation phase.

---

### Added

#### SIM-centric queue architecture
- Locked the system as **SIM-centric**, not modem-centric
- Confirmed:
  - 1 company can have multiple SIMs
  - 1 modem = 1 SIM only
  - each SIM has its own isolated queue / worker / retry / monitoring state

#### Redis per-SIM queue model
- Added final Redis queue design:
  - `sms:queue:sim:{sim_id}:chat`
  - `sms:queue:sim:{sim_id}:followup`
  - `sms:queue:sim:{sim_id}:blasting`
- Added final message-type mapping:
  - CHAT → chat
  - AUTO_REPLY → chat
  - FOLLOW_UP → followup
  - BLAST → blasting

#### Operator delivery controls
- Added final `operator_status` model:
  - `active`
  - `paused`
  - `blocked`

#### Assignment / health flags
- Added final state/assignment flags:
  - `accept_new_assignments`
  - `disabled_for_new_assignments`
  - `last_success_at`

#### Manual migration model
- Added manual migration-only design
- Added DB-first migration / queue rebuild principle
- Added pending-only safe rebuild scope
- Added paused→active auto-requeue requirement
- Added worker-visible rebuild lock requirement

#### Monitoring rules
- Added:
  - no-success-in-30-minutes rule
  - stuck-age warning model
  - 5-minute health check schedule
  - operator monitoring expectations

---

### Changed

#### Queue model
- Changed from older single-queue assumptions to final **per-SIM 3-queue Redis architecture**

#### Retry model
- Changed retry direction to:
  - fixed 5-minute interval
  - forever
  - no automatic stop
  - no automatic cross-SIM failover

#### Failover model
- Changed direction from automatic failover toward:
  - manual migration only
  - keep reusable failover internals where practical
  - remove automatic failover orchestration as a primary architecture behavior

#### Health semantics
- Changed health basis from ambiguous send timing to:
  - `last_success_at`
  - true successful send only

#### Intake semantics
- Finalized:
  - active = save + queue
  - paused = save only, return 202 warning
  - blocked = reject new intake, no DB save, no queue
- Clarified:
  - blocked still allows old queued work to drain
  - paused skips worker processing until resumed

#### Tenant identity rule
- Finalized that tenant/company identity must always come from authenticated context
- Request body `company_id` must not be trusted

---

### Removed / Replaced

#### Automatic failover direction
- Replaced older architecture direction that assumed automatic failover / reassignment
- Manual migration is now the authoritative behavior

#### Static modem mapping as primary design
- Replaced old assumption that static SIM → ttyUSB config mapping is the main strategy
- Python modem registry / discovery is now the authoritative execution-layer direction

---

### Locked Decisions

The following are now explicitly locked:

- Gateway remains transport-only
- Laravel remains control layer
- Python remains execution layer
- MySQL remains source of truth
- Redis remains queue/coordination layer only
- queueing is per SIM
- workers are per SIM
- sticky assignment is required
- migration is manual only
- no automatic cross-SIM rescue
- retries remain on the same SIM
- no message should be lost
- new SIMs require manual enablement for new assignments
- paused→active must safely auto-requeue from DB truth
- blocked stops new intake only
- rebuild must use worker-visible lock
- rebuild must be DB-first and pending-only

---

### Documentation Updated
The following docs were brought into alignment with the final architecture lock:

- `SYSTEM.md`
- `DECISIONS.md`
- `ROADMAP.md`
- `TASKS.md`
- `CHANGELOG.md`

---

### Implementation Impact
This changelog entry establishes the design baseline for upcoming implementation work:

- Python execution stabilization
- operator status model
- assignment/health flags
- manual migration
- DB-first rebuild
- retry policy update
- Redis per-SIM queue architecture
- intake routing changes
- paused→active auto-requeue
- blocked intake gate
- health command + scheduler
- dashboard / monitoring

---

## [2026-03-25] SMS Gateway Core v3.1 Baseline Established

### Added
- Laravel control-layer baseline for multi-tenant SMS transport
- SmsSenderInterface abstraction
- Python external execution layer direction
- sticky SIM assignment baseline
- retry/recovery baseline
- inbound relay baseline
- multi-tenant security hardening baseline

### Notes
This was the baseline architecture prior to the final SIM-centric Redis + operator-control lock completed on 2026-03-29.

---

## [Historical Entries]
Keep all older changelog entries below this line exactly as they already exist in your file, unless they directly conflict with the 2026-03-29 architecture lock.

If an older entry mentions:
- automatic failover as current direction
- single outbound queue as final direction
- static sim_map.json as final direction

those older entries should either:
- remain as historical context only
- or be marked as superseded by 2026-03-29
