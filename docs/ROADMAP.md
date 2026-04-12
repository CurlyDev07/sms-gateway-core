# ROADMAP

---

## CURRENT PHASE
Phase 5B – IN PROGRESS (TASK 021 closed; TASK 022/023 follow)

### Phase Status
- Phase 0: COMPLETE (Locked)
- Phase 1: COMPLETE (Locked)
- Phase 2: COMPLETE (Locked)
- Phase 3: COMPLETE (absorbed into Phase 2 — see Phase 3 section)
- Phase 4: COMPLETE (Locked) — tenant-safe operator API + core dashboard/operator surfaces implemented
- Phase 5A: COMPLETE (Locked) — dashboard/auth/operator system
- Phase 5B: IN PROGRESS — scale/infrastructure/throughput path (`TASK 021` complete; `TASK 022/023` pending)
- Phase 6: COMPLETE (Locked) — Python Runtime Integration & Live Modem Fleet (`TASK 029`..`TASK 032` closure complete)
- Phase 1 lock result: manual migration baseline + failover/reassign hardening complete
- Phase 2 lock result: Redis transport + rebuild + retry + worker/controller/event wiring + Laravel-side Python integration + errorLayer-aware retry policy + live smoke-test proven + last_success_at bug fix + bootstrap seeders + Python API authentication + SimHealthService validation — all complete and locked
- Phase 2 lock validation: full suite green (120 passed)
- Phase 2 explicit deferral: per-modem send lock is Python-owned hardware-safe execution behavior; deferred outside Phase 2 lock scope
- Phase 4 lock validation: full suite green (205 passed)
- Phase 5A closure: TASK 028 finalized as docs/closure boundary work (no additional core engineering required)
- Phase 6.2 current validation: full suite green (286 passed)
- Phase 6 runtime validation milestone: real Laravel→Python→modem send verified (physical SMS received)

---

## DONE
- SMS webhook design
- Message storage schema
- System architecture (v3.1 baseline)
- Task 001: Database + Models (companies, modems, sims, assignments, outbound/inbound, stats, health logs, api clients)
- Task 002: SIM assignment (sticky customer-to-SIM mapping with reassignment rules)
- Task 003: Single SIM worker loop (claim, send, update status, burst/cooldown timing, daily stats)
- Task 004: Formal SIM state engine (mode transitions, cooldown entry/exit, rate-limit checks, centralized sleep timing)
- Task 005: Inbound message handling + relay to Chat App webhook
- Task 006A: Retry + recovery reliability foundation (outbound retry backoff, inbound relay retry backoff, stale lock recovery commands/services)
- Task 009: Multi-SIM support core implementation (selection, availability checks, SIM-centric flow)
- Task 011: Multi-tenant security layer (API client authentication, tenant resolution middleware, tenant-isolated route protection)
- Task 011 hardening: hashed API secret verification and global tenant container context
- SMS sender abstraction layer (driver-based transport adapter: python API + queue bridge)
- SMS sender hardening and observability improvements
  (full trace metadata, provider-status validation, structured transport logs)
- Concurrency safety and reliability hardening
  (row locking, `SKIP LOCKED`, stale lock recovery, retry correctness)
- Inbound pipeline hardening
  (duplicate guard, async relay dispatch, `received_at` dedupe)
- Phase 1 slice checkpoint: manual migration baseline + failover command entry-point disable + stale recovery DB-first alignment + slice test coverage
- Phase 1 hardening checkpoint: automatic reassignment path disabled (`CustomerSimAssignmentService::reassignSim`) + focused unit test + full suite green (61 passed)

### Also locked as final architecture decisions
- Laravel remains the control layer
- Python remains the execution layer
- Gateway remains transport-only
- SIM-centric architecture is final
- sticky assignment is final
- tenant identity always comes from auth context

Legacy baseline status:
- Complete (Tasks 001–011 baseline)

---

## PHASE 2 LOCK SUMMARY (Complete)
- Phase 2 slice implemented:
  - Redis queue transport (`RedisQueueService`)
  - DB-first queue rebuild + rebuild lock (`QueueRebuildService`)
  - normalization/init/rebuild/retry commands
  - outbound intake Phase 2 semantics
  - Redis worker rewrite with DB-truth recheck + rebuild-lock awareness
  - paused→active auto-requeue event/listener wiring
  - retry scheduler wiring in Kernel
  - Laravel-side Python integration slice:
    - `sims.imsi` migration + `Sim` model `imsi`
    - `SmsSendResult.errorLayer` support
    - `PythonApiSmsSender` contract alignment + tests
  - focused Phase 2 infrastructure + worker Redis-path + sender integration tests added
  - errorLayer-aware retry policy implemented:
    - `network` errorLayer → terminal failure (`status='failed'`, no retry)
    - all other layers → existing 5-minute forever retry path
    - `PythonApiSmsSender` ConnectionException corrected to `transport` layer
  - full suite green (120 passed)
  - Task 012A: Python endpoints confirmed integration-ready; Laravel-side retry gap closed; Python API authentication complete (X-Gateway-Token, both sides, live-proven); remaining: per-modem send lock
  - live smoke test proven end-to-end (physical SMS received; all success/retry/terminal paths confirmed)
  - `sims.last_success_at` bug fixed: `SimStateService` now persists on all send-success paths; `SimStateServiceTest` added
  - bootstrap seeders added: `BootstrapCompanySeeder`, `BootstrapModemSeeder`, `BootstrapSimSeeder`, `BootstrapApiClientSeeder`
  - `SMS_PYTHON_API_SEND_PATH` config key added as minor dev/testing affordance (default: `/send`)
  - Python API authentication implemented: `X-Gateway-Token` header sent by Laravel, validated by Python
  - `SimHealthService`/`CheckSimHealthCommand` validated against real-populated `last_success_at` (3 tests added to `SimHealthServiceTest`)

---

## NEXT

### Phase 2A — Python SMS Execution Layer Stabilization
Status: COMPLETE (Locked for Phase 2)
- Python API server (`/send`, `/modems/discover`, `/modems/health`) ✓
- Stable modem discovery / modem registry ✓
- Stable SIM-centric identity resolution ✓
- AT command SMS sending (`AT+CMGS`) ✓
- Multi-modem support (USB hub) ✓
- Modem health check ✓
- Structured modem / hardware error normalization ✓
- No full scan during send path ✓
- Execution-layer correctness and observability ✓
- Python API authentication (`X-Gateway-Token`) ✓
Deferred (Python-owned, non-blocking): per-modem send lock — hardware-safe serial port concurrency guard; to be implemented Python-side before multi-modem concurrent load

### Phase 2B — Operator Control + Health Rules (Already Implemented/Locked)
Status: Completed and locked in Phase 0 baseline. Retained for traceability; not active NEXT scope.
- SIM operator status model:
  - `active`
  - `paused`
  - `blocked`
- `accept_new_assignments` support
- `disabled_for_new_assignments` support
- `last_success_at` health basis
- no-success-in-30-minutes rule
- stuck-age warnings:
  - 6h
  - 24h
  - 3d
- operator enable/disable controls for new assignment
- operator pause/block controls for delivery behavior

### Phase 2C — Manual Migration Model (Already Implemented/Locked)
Status: Completed and locked in Phase 1 baseline. Retained for traceability; not active NEXT scope.
- remove automatic failover as primary architecture
- manual migration only
- reuse failover internals where helpful
- bulk migration:
  - sticky customers
  - pending messages
  - future traffic automatically through updated assignment
- DB-first migration
- queue rebuild from DB truth
- safe rebuild locks
- stale-send recovery path remains separate

### Phase 2D — Retry Model Alignment (Already Implemented/Locked)
Status: Completed in baseline hardening. Retained for traceability; not active NEXT scope.
- replace older retry model with fixed retry policy:
  - every 5 minutes
  - forever
- no automatic stop
- no auto cross-SIM rescue
- stuck visibility in monitoring instead of auto-abandon

---

## PHASE 3 — REDIS + PER-SIM QUEUE ARCHITECTURE
Note: All items listed below were absorbed into Phase 2 and are already complete.
See Phase 4 for the active next scope.

- Introduce Redis as queue transport / coordination layer ✓ (Phase 2)
- Per-SIM isolated queue model ✓ (Phase 2)
- 3 Redis queues per SIM ✓ (Phase 2)
- Worker priority order (chat → followup → blasting) ✓ (Phase 2)
- AUTO_REPLY grouped into chat tier ✓ (Phase 2)
- Per-SIM worker consumption from Redis ✓ (Phase 2)
- Redis rebuild lock for queue rebuild safety ✓ (Phase 2)
- DB-first queue rebuild from MySQL truth ✓ (Phase 2)
- pending-only safe rebuild scope ✓ (Phase 2)
- paused → active auto-requeue path ✓ (Phase 2)
- no duplicate queue rebuild guarantee ✓ (Phase 2)

### Phase 3 exit condition (met in Phase 2)
- each SIM has isolated queue behavior ✓
- one SIM never blocks another SIM ✓
- Redis is active for queue transport ✓
- MySQL remains source of truth ✓

---

## PHASE 4 — MONITORING + CONTROL SURFACES

### Phase 4 Lock Result (2026-04-08) — Backend/API + Core Dashboard Complete
Backend/API surfaces (including rebalance + bulk send) and core dashboard/operator pages are implemented and tested (205 passed). Phase 4 is complete and locked.

#### Completed (Backend + Frontend)
- `GET /api/sims` — SIM list with health, queue depth, assignment flags ✓
- `GET /api/messages/status` — message status by `client_message_id` ✓
- `GET /api/assignments` — customer-SIM assignments with nested SIM ✓
- `POST /api/admin/sim/{id}/status` — operator status control ✓
- `POST /api/admin/sim/{id}/enable-assignments` — enable new assignments ✓
- `POST /api/admin/sim/{id}/disable-assignments` — disable new assignments ✓
- `POST /api/admin/migrate-single-customer` — single-customer migration ✓
- `POST /api/admin/migrate-bulk` — bulk SIM migration ✓
- `POST /api/admin/rebalance` — conservative tenant-safe rebalance ✓
- `POST /api/admin/sim/{id}/rebuild-queue` — per-SIM queue rebuild trigger ✓
- `POST /api/messages/bulk` — minimal tenant-authenticated bulk/blasting intake with per-item results ✓
- `/dashboard` — operator home/navigation page ✓
- `/dashboard/sims` — SIM fleet visibility page ✓
- `/dashboard/assignments` — assignment visibility page ✓
- `/dashboard/sims/{id}` — SIM detail/control page ✓
- `/dashboard/migration` — migration workflow page ✓
- `/dashboard/messages/status` — message status lookup page ✓
- dashboard UX polish pass ✓
  - shared credential persistence
  - consistent navigation links
  - improved action status messaging
  - SIM detail deep links from list pages

#### Deferred Beyond Phase 4 (Later Backlog)
- advanced monitoring analytics
- deeper error-tracking stack
- non-essential future UI polish iterations
- scale-oriented operator tooling

#### Intentional Deferral
- `StaleLockRecoveryService` not exposed as tenant API (system-scoped; wrong blast radius)
- Per-modem send lock (Python-owned; outside Phase 4 scope)

---

## PHASE 5A — DASHBOARD / AUTH / OPERATOR SYSTEM

Status: COMPLETE (Locked)

Completed in current repo:
- dashboard login/logout and session-protected dashboard routes
- server-side `/dashboard/api/*` bridge (no browser-side raw API secrets)
- tenant binding from `users.company_id`
- dashboard RBAC (`owner`, `admin`, `support`)
- operator management UI and owner-only write controls
- forced temporary-password change + self-service password change
- tenant-local operator audit logging + read-only audit view/API with filters/search
- shared dashboard layout + identity banner + navigation/page-title polish
- operator list filter/sort/search

Lock closure:
- Phase 5A lock/checkpoint closure task completed as docs/admin boundary finalization (TASK 028)
- no additional core engineering scope required for Phase 5A lock

---

## PHASE 5B — SCALING INFRASTRUCTURE
- Full per-SIM worker scaling
- Redis-backed queue throughput improvements
- Queue optimization
- distributed worker scaling
- multi-node Laravel worker deployment
- Python execution node scaling as needed
- node-aware modem / execution placement if required

Important:
- scaling remains SIM-centric
- no change to transport-only boundary

---

## PHASE 6 — PYTHON RUNTIME INTEGRATION & LIVE MODEM FLEET
Status: IN PROGRESS

### Phase 6.1 Foundation (Implemented Checkpoint)
- Laravel runtime client/service for Python API calls
- runtime contract foundation in use:
  - `GET /health`
  - `GET /modems/discover`
- read-only dashboard/runtime inspection surface added (page + dashboard API)
- tenant-filtered modem discovery visibility in Laravel (tenant SIM IMSI matching)

### Phase 6.2 Send Execution Bridge (Implemented Checkpoint)
- existing Python send contract reused via Laravel runtime integration path
- structured Laravel→Python send execution bridge implemented
- send-path failure normalization added in Laravel runtime client/sender integration
- explicit runtime failure classes surfaced:
  - `runtime_unreachable`
  - `runtime_timeout`
  - `invalid_response`
- runtime diagnostics persisted to `outbound_messages.metadata`
- controlled dashboard send-test surface added for manual verification

### Phase 6.3 Reliability Control (Implemented)
- retry classification and controlled retry behavior integrated into runtime send path
- SIM runtime suppression/control visibility integrated into Laravel-side health/safety surfaces
- runtime-control snapshots surfaced for operator visibility and safer behavior under repeated failures

### Phase 6.4 Runtime Fleet Observability UI (Implemented)
- 6.4.a: runtime fleet observability baseline
- 6.4.b: row safety semantics and operator action clarity
- 6.4.c: runtime detail drilldown diagnostics
- 6.4.d: empty/failure/refresh state clarity

### Phase 6.5 Runtime Action Clarity UI (Implemented)
- 6.5.a: action intent confirmation and identity-boundary clarity
- 6.5.b: selected-row/action-target context clarity
- 6.5.c: clear-selection/reset-context UX
- 6.5.d: lightweight operator guidance / page help copy

### Phase 6.6 Mapping Review Visibility UI (Implemented)
- 6.6.a: runtime-to-Laravel mapping review summary/filter/visibility
- 6.6.b: diagnostics-level reconciliation context explanation (read-only)
- mapping review remains informational only (no mapping-write workflow added)

### Phase 6 Runtime Validation Milestone (Implemented)
- real end-to-end Laravel→Python→modem send validated in live environment
- Python runtime discovery + send execution path verified through operator runtime dashboard flow
- physical SMS delivery confirmed on destination device
- runtime UI safety guardrails active:
  - full discovery rows rendered
  - non-send-ready rows blocked from send-test usage
  - row-level disabled reasons shown
- critical identity/mapping lesson validated and now part of operational model:
  - Runtime SIM ID (IMSI/fallback runtime identifier) is distinct from Tenant SIM DB ID (`sims.id`)
  - dashboard send-test and Laravel-side actions must use Tenant SIM DB ID
  - mixing IDs causes `sim_not_found`; mapping and UI distinction resolve the issue

### Phase 6 Follow-Up (Closed)
- TASK 031 (DONE): runtime/fleet hardening closure completed
  - completed via strict checklist `031-H1`..`031-H8` with `AC-031-01`..`AC-031-07` closure gate
  - artifact-linked evidence ledger retained in `docs/TASKS.md`
- TASK 032 (DONE): deeper send-path maturity closure completed
  - completed via strict checklist `032-S1`..`032-S8` with `AC-032-01`..`AC-032-08` closure gate
  - explicit Phase 5B handoff boundary captured in `docs/TASKS.md`

### Phase 5B Active Scale Path (Open)
- TASK 021 (DONE): worker scale-out closure completed via strict checklist `021-W1`..`021-W8` with closure gate `AC-021-01`..`AC-021-08`
- TASK 022 (FUTURE): Python execution scale-out (starts after TASK 021 readiness)
- TASK 023 (FUTURE): throughput/load testing (100k/500k/1M targets) after TASK 021/022 completion

---

## FUTURE
- Large-scale worker orchestration
- Redis HA / clustering if required
- per-SIM operational analytics refinement
- queue mismatch detection (Redis vs DB truth)
- scale-out load testing:
  - 100k/day
  - 500k/day
  - 1M/day

---

## NOT IN SCOPE
This roadmap is for SMS Gateway only.

The following remain outside this project:
- AI
- RAG
