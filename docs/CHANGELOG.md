# CHANGELOG

Last Updated: 2026-04-07

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
