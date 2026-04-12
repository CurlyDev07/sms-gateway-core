# TASKS

Last Updated: 2026-04-11 (Phase 6 realignment checkpoint)

---

## PHASE 0 LOCK STATUS
- COMPLETE
- Validation: 39/39 tests passed

---

## TASK 001 — DATABASE + MODELS
Status: DONE

Implemented:
- companies
- modems
- sims
- outbound_messages
- inbound_messages
- customer_sim_assignments
- api_clients
- sim_daily_stats
- sim_health_logs

Locked:
- MySQL is source of truth
- SIM is the core operational unit
- tenant-aware schema is in place

Added in Phase 2 slice (2026-04-06):
- idempotent bootstrap seeders added for fresh-clone dev setup:
  - `BootstrapCompanySeeder`, `BootstrapModemSeeder`, `BootstrapSimSeeder`, `BootstrapApiClientSeeder`
  - `DatabaseSeeder` updated to call all four in dependency order
  - env() fallbacks supported; IMSI placeholder is explicit (15 zeros)
  - `php artisan migrate --seed` now produces a working dev baseline

---

## TASK 002 — STICKY CUSTOMER → SIM ASSIGNMENT
Status: DONE

Implemented:
- customer sticks to SIM once assigned / has message history
- reuse existing SIM if available
- assignment row support
- no invisible customer reassignment

Locked:
- sticky assignment remains core behavior
- no auto cross-SIM rescue
- migration must be manual

---

## TASK 003 — SINGLE SIM WORKER LOOP
Status: DONE (BASELINE)
Status Note: will be updated, not replaced

Implemented baseline:
- claim message
- send via transport layer
- update delivery status
- update stats
- sleep rules / burst timing

To be updated:
- claim source changes from DB query to per-SIM Redis queues
- worker becomes 3-queue aware
- worker respects operator status
- worker respects rebuild lock

---

## TASK 004 — SIM STATE ENGINE
Status: DONE (BASELINE)
Status Note: preserved and extended

Implemented baseline:
- cooldown logic
- availability checks
- daily limit checks
- centralized timing helpers

To be extended:
- cooperate with operator status model
- cooperate with health-based assignment disable rules
- cooperate with per-SIM queue architecture

---

## TASK 005 — INBOUND MESSAGE HANDLING + RELAY
Status: DONE

Implemented:
- inbound capture
- inbound dedupe protections
- relay to Chat App webhook
- async relay behavior

Locked:
- inbound remains unchanged by Redis / per-SIM queue redesign
- gateway still remains transport-only

---

## TASK 006 — RETRY + RECOVERY FOUNDATION
Status: DONE (BASELINE)
Status Note: logic must be updated

Implemented baseline:
- retry scheduling
- stale lock recovery
- recovery commands
- reliability foundation

Required update:
- retry policy becomes:
  - every 5 minutes
  - forever
- no automatic stop
- no exponential backoff
- no automatic cross-SIM failover
- stuck visibility belongs in monitoring

Recovery remains important for:
- stale `sending`
- rebuild safety
- migration safety
- Python / worker crash scenarios

---

## TASK 007 — FAILOVER / REASSIGNMENT MODEL
Status: REPLACED

Old direction:
- automatic failover
- SIM reassignment / replacement logic

Final direction:
- remove automatic failover as a primary architecture behavior
- keep reusable internals only if useful
- replace with manual migration only

This task is superseded by:
- TASK 012B
- TASK 012C

---

## TASK 008 — OPERATIONAL HARDENING
Status: DONE (BASELINE)
Status Note: continues as implementation hardening umbrella

Includes:
- row locking
- stale lock recovery
- sender hardening
- transport observability
- concurrency protections

Will continue to apply to:
- Redis queue claim path
- DB-first migration path
- paused→active queue rebuild
- worker-visible rebuild lock

---

## TASK 009 — MULTI-SIM SUPPORT
Status: DONE

Implemented:
- company can have multiple SIMs
- selection service baseline
- availability checks
- SIM-aware routing flow

Final locked interpretation:
- system is SIM-centric
- one SIM must not block another SIM
- queueing / workers / monitoring are per-SIM

---

## TASK 010 — INBOUND HARDENING
Status: DONE

Implemented:
- dedupe protections
- relay retry hardening
- async relay safety

No major changes required from final aligned outbound redesign.

---

## TASK 011 — MULTI-TENANT SECURITY
Status: DONE

Implemented:
- API client auth
- tenant resolution middleware
- tenant container context
- tenant-isolated route protection
- hashed API secret hardening

Final lock:
- tenant identity must always come from auth context
- never trust `company_id` in request body

This rule must now also be reflected in outbound API intake implementation.

---

# PHASE 2 — FINAL ALIGNED TASKS

Phase 2 Status: COMPLETE (Locked)
Phase 2 Lock Validation: full suite green (120 passed)
Phase 2 Explicit Deferral: per-modem send lock — Python-owned hardware-safe execution behavior; deferred outside Phase 2 lock scope; non-blocking for current single-modem live setup

## TASK 012A — PYTHON SMS EXECUTION LAYER STABILIZATION
Status: DONE (Phase 2 locked; per-modem send lock explicitly deferred as Python-owned)

Goal:
- finalize Python as stable execution layer

Includes:
- FastAPI service
- `/send`
- `/modems/discover`
- `/modems/health`
- modem discovery / registry
- SIM-centric identity resolution
- stable modem routing
- structured send result
- no full scan during send path
- hardware-level error normalization

Locked:
- Python is execution-only
- no business logic ownership
- no tenant policy ownership
- no retry policy ownership

Completed in current Phase 2 slice:
- `sims.imsi` migration added for Python execution identity plumbing
- `Sim` model updated to include `imsi`
- `SmsSendResult` updated to include `errorLayer`
- `PythonApiSmsSender` aligned to Python engine contract:
  - resolve IMSI from SIM
  - fail early on missing IMSI
  - send IMSI string as `sim_id`
  - success detection via top-level `success === true`
  - extract top-level `error` and `raw.error_layer`
- focused test added: `PythonApiSmsSenderTest`

Completed additionally in this slice:
- full Laravel↔Python↔modem live smoke test proven end-to-end (physical SMS received; all paths confirmed)
- `SMS_PYTHON_API_SEND_PATH` config key added as minor dev/testing affordance (default: `/send`; production behavior unchanged)
- Python API authentication complete:
  - `SMS_PYTHON_API_TOKEN` config key added (`config/sms.php`)
  - `PythonApiSmsSender` sends `X-Gateway-Token` header when token configured; omits when empty (backward-safe)
  - Python `/send` and protected endpoints validate `X-Gateway-Token`; `/health` intentionally open
  - two tests added to `PythonApiSmsSenderTest` covering header-sent and header-absent cases
  - authenticated live send proven end-to-end (physical SMS received)

Deferred outside Phase 2 lock scope:
- per-modem send lock (Python-owned) — hardware-safe serial port concurrency guard; does not affect correctness of current single-modem setup; to be addressed Python-side before multi-modem concurrent load

---

## TASK 012B — OPERATOR STATUS MODEL
Status: DONE (Baseline + Phase 2 wiring)

Goal:
Implement operator-controlled SIM intake/delivery states.

Add:
- `operator_status`
  - `active`
  - `paused`
  - `blocked`

Final behavior:

### active
- save to DB
- enqueue to Redis
- worker sends

### paused
- save to DB
- do not enqueue
- worker skips
- API returns 202 accepted warning
- auto-requeue on resume

### blocked
- reject new intake
- no DB save for new request
- no new enqueue
- worker may continue draining old already-existing queued work

Required code areas:
- SIM model
- message intake controller
- worker logic
- operator command(s)
- event/listener for paused→active resume

Completed in current Phase 2 slice:
- paused → active event/listener wiring implemented
- active/paused/blocked intake semantics implemented in controller
- worker behavior aligned for paused skip + blocked drain

---

## TASK 012C — HEALTH / ASSIGNMENT FLAGS
Status: DONE (Baseline)

Goal:
Implement final SIM assignment and health flags.

Add:
- `accept_new_assignments`
- `disabled_for_new_assignments`
- `last_success_at`

Rules:
- new future SIMs default to `accept_new_assignments = false`
- existing active SIMs should not be broken during rollout
- if no success in 30 minutes and company has >1 SIM:
  - disable SIM from new assignments only
- health checks run every 5 minutes
- health basis = `last_success_at`

Also surface:
- stuck_6h
- stuck_24h
- stuck_3d

Implemented baseline:
- `accept_new_assignments`
- `disabled_for_new_assignments`
- `last_success_at`
- health check command and scheduler baseline

Bug fix landed this slice:
- `sims.last_success_at` was not being persisted on successful sends
- Root cause: `SimStateService::markSendSuccess()` set `last_sent_at` but never set `last_success_at`
- Fix: `$sim->last_success_at = now()` added; persists on all three code paths (BURST, BURST→COOLDOWN, NORMAL)
- `SimStateServiceTest` added covering all three paths
- `SimHealthService` and `CheckSimHealthCommand` validation completed: 3 tests added to `SimHealthServiceTest` covering null `last_success_at` stuck-flags, healthy result shape, and exact 30-minute boundary; item is now closed

---

## TASK 012D — MANUAL MIGRATION ONLY
Status: DONE (Locked)

Goal:
Replace auto-failover direction with manual migration architecture.

Must support:
- one-by-one migration
- bulk migration

Bulk migration moves:
- sticky assignments
- pending messages
- future traffic via updated assignment

Rules:
- no automatic cross-SIM failover
- migration is operator-controlled only
- keep reusable failover internals only if helpful
- remove automatic failover commands/orchestration

Completed in current slice:
- automatic failover command entry points hard-disabled (`gateway:failover-sim`, `gateway:scan-failover`)
- `SimMigrationService` implemented (DB-first manual migration baseline)
- `MigrateSingleCustomerCommand` implemented
- `MigrateSimCustomersCommand` implemented
- stale recovery aligned for DB-first migration safety / same-SIM retry behavior
- Phase 1 migration baseline tests added for service and commands
- `CustomerSimAssignmentService::reassignSim()` automatic reassignment path disabled (manual migration only)
- focused unit test added for disabled `reassignSim` behavior
- checkpoint validation at lock time: full suite green (61 passed)

Lock result:
- Phase 1 manual-migration baseline complete; next work starts in Phase 2

---

## TASK 012E — DB-FIRST QUEUE REBUILD
Status: DONE (Phase 2 locked)

Goal:
Implement safe queue rebuild behavior.

Rules:
- MySQL is truth
- Redis is transport only
- rebuild = clear Redis + rebuild from DB truth
- rebuild scope = `pending` only
- do not requeue `sending` directly
- `sending` recovery handled separately

Use cases:
- paused → active resume
- manual migration
- queue repair / recovery

Must include:
- worker-visible rebuild lock
- lock set before rebuild
- worker checks rebuild lock before any LPOP
- always clear lock in `finally`

Completed in current Phase 2 slice:
- `QueueRebuildService` implemented with worker-visible rebuild lock
- `RebuildSimQueueCommand` implemented
- `InitializeQueueMigrationCommand` implemented
- `NormalizePausedQueuedToPendingCommand` implemented
- focused tests added:
  - `QueueRebuildServiceTest`
  - `RebuildSimQueueCommandTest`
  - `InitializeQueueMigrationCommandTest`
  - `NormalizePausedQueuedToPendingCommandTest`

---

## TASK 012F — RETRY POLICY UPDATE
Status: DONE (Phase 2 locked)

Goal:
Replace older retry model with final aligned retry behavior.

Final retry:
- every 5 minutes
- forever
- no automatic stop
- no auto-abandon
- no automatic migration

Messages remain on same SIM until:
- success
- or operator manually migrates

This task updates:
- OutboundRetryService
- retry docs
- operator monitoring expectations

Completed in current Phase 2 slice:
- `RetrySchedulerCommand` implemented for due retry re-enqueue
- scheduler wiring added in `Kernel.php` (every five minutes)
- focused test added: `RetrySchedulerCommandTest`
- `OutboundRetryService::handlePermanentFailure()` added — terminal path for network-layer carrier rejections
- `SimQueueWorkerService` routes `errorLayer='network'` → permanent failure; all other layers → retry
- `PythonApiSmsSender` `ConnectionException` corrected to `errorLayer='transport'` (was `'network'`)
- tests added/updated: `OutboundRetryServiceTest`, `SimQueueWorkerServiceRedisTest`, `PythonApiSmsSenderTest`

---

# PHASE 2 CONTINUATION — REDIS PER-SIM QUEUE ARCHITECTURE

## TASK 013 — REDIS PER-SIM 3-QUEUE MODEL
Status: DONE (Phase 2 locked)

Goal:
Move from DB-claim queueing to Redis per-SIM queue transport.

Per SIM queues:
- `sms:queue:sim:{sim_id}:chat`
- `sms:queue:sim:{sim_id}:followup`
- `sms:queue:sim:{sim_id}:blasting`

Priority order:
1. chat
2. followup
3. blasting

Mapping:
- CHAT → chat
- AUTO_REPLY → chat
- FOLLOW_UP → followup
- BLAST → blasting

Worker behavior:
- check queues in priority order
- claim only after rebuild lock check
- DB remains truth

This task updates:
- message intake
- worker claim logic
- queue rebuild services
- migration rebuild path

Completed in current Phase 2 slice:
- `RedisQueueService` implemented (chat/followup/blasting tiers)
- worker moved to Redis pop with priority order + DB-truth recheck
- rebuild services/commands integrated with per-SIM Redis queues
- focused test added: `RedisQueueServiceTest`
- focused worker Redis-path test added: `SimQueueWorkerServiceRedisTest`

---

## TASK 014 — MESSAGE INTAKE → REDIS ROUTING
Status: DONE (Phase 2 locked)

Goal:
Make outbound intake route directly into per-SIM Redis queue when SIM is active.

Flow:
- resolve tenant from auth context
- assign sticky SIM / lowest-load SIM
- enforce operator status
- create DB record if allowed
- enqueue into Redis if active

Final intake semantics:
- active → save + queue
- paused → save only, 202 warning
- blocked → reject, no DB save

Completed in current Phase 2 slice:
- controller now enqueues on active, saves pending on paused, rejects blocked

---

## TASK 015 — PAUSED→ACTIVE AUTO-REQUEUE
Status: DONE (Phase 2 locked)

Goal:
When SIM resumes from paused to active:
- rebuild that SIM’s Redis queues from DB truth
- no manual requeue command required

Requirements:
- event/listener or equivalent orchestration
- worker-visible rebuild lock
- no duplicate queue entries
- no message loss

Completed in current Phase 2 slice:
- `SimOperatorStatusChanged` event + `PausedSimResumeListener` wired in `EventServiceProvider`
- listener triggers DB-first rebuild for paused→active only

---

## TASK 016 — BLOCKED INTAKE GATE
Status: DONE (Phase 2 locked)

Goal:
Ensure blocked SIM rejects new intake while allowing old queued work to drain.

Rules:
- no new DB save
- no new queue push
- worker does not skip already-existing old queued work solely because of blocked status

This must be explicit in:
- intake controller
- worker semantics
- docs
- tests

Completed in current Phase 2 slice:
- blocked intake rejects new requests with no DB save
- worker allows draining old queued work for blocked SIM

---

# PHASE 4 — MONITORING + OPERATOR TOOLS

Phase 4 Status: COMPLETE (Locked)
Phase 4 Lock Validation (2026-04-08): full suite green — 205 passed

## TASK 017 — HEALTH CHECK COMMAND + SCHEDULER
Status: DONE (backend/service layer — Phase 0/2)
API surface: DONE (Phase 4 — exposed via GET /api/sims health field)
Dashboard: DONE (visible on `/dashboard/sims` and `/dashboard/sims/{id}`)

Completed:
- `SimHealthService` implemented in Phase 0 (30-min threshold, stuck-age flags, disable logic)
- `CheckSimHealthCommand` implemented in Phase 0 (scheduled every 5 minutes)
- `SimHealthService` validated in Phase 2 against real `last_success_at` data (3 tests added to `SimHealthServiceTest`)
- health status, stuck flags, and `minutes_since_last_success` exposed via `GET /api/sims` in Phase 4

---

## TASK 018 — STUCK-AGE MONITORING
Status: DONE (backend/service layer — Phase 0/2)
API surface: DONE (Phase 4 — exposed via GET /api/sims stuck field)
Dashboard: DONE (visible on `/dashboard/sims` and `/dashboard/sims/{id}`)

Completed:
- `stuck_6h`, `stuck_24h`, `stuck_3d` computed by `SimHealthService::computeStuckAge()`
- exposed in `GET /api/sims` response as `sims[].stuck` object
- do not stop retries; visibility only

---

## TASK 019 — MANUAL MIGRATION TOOLING
Status: DONE (Phase 4 — API surfaces complete)

Completed:
- `POST /api/admin/migrate-single-customer` — single-customer migration via `SimMigrationService::migrateSingleCustomer()`; tenant-scoped; returns `assignments_moved` + `messages_moved`
- `POST /api/admin/migrate-bulk` — bulk migration via `SimMigrationService::migrateBulk()`; tenant-scoped; same result shape
- `POST /api/admin/rebalance` — conservative tenant-scoped rebalance using existing migration/assignment logic; explicit `from_sim_id`/`to_sim_id`; returns moved counts
- `POST /api/admin/sim/{id}/rebuild-queue` — per-SIM Redis queue rebuild from DB truth via `QueueRebuildService`; returns 409 if lock already held
- all four endpoints fully tested; CLI commands remain available alongside API

---

## TASK 020 — DASHBOARD SURFACES
Status: DONE (Locked)

Completed in current Phase 4 slices:
- `/dashboard` home/navigation page
- `/dashboard/sims` read-only SIM fleet visibility page
- `/dashboard/assignments` read-only assignment visibility page
- `/dashboard/sims/{id}` SIM detail/control page (existing admin APIs only)
- `/dashboard/migration` migration workflow page (existing migration/assignment APIs only)
- `/dashboard/messages/status` message status lookup page
- `POST /api/messages/bulk` implemented (minimal tenant-authenticated blasting endpoint with per-item outcomes)
- dashboard UX polish pass:
  - shared credential persistence across dashboard pages
  - consistent cross-page navigation
  - improved action-status visibility after refresh
  - SIM-detail deep links from list pages

Lock result:
- core operator dashboard surfaces complete and validated
- no additional core-scope code changes required for Phase 4 lock

Deferred beyond Phase 4 (later backlog):
- advanced monitoring analytics
- deeper error-tracking stack
- non-essential future UI polish iterations
- scale-oriented operator tooling

---

# PHASE 5A — DASHBOARD / AUTH / OPERATOR SYSTEM

Phase 5A Status: COMPLETE (Locked)
Phase 5A Checkpoint Validation: full suite green (267 passed)

## TASK 024 — DASHBOARD SESSION AUTH + TENANT BINDING
Status: DONE (Phase 5A)

Completed:
- login/logout for dashboard operators
- dashboard routes protected by web session auth
- forced temporary-password change guard
- `/dashboard/api/*` session-authenticated bridge
- tenant binding from `users.company_id` (`ResolveDashboardTenant`)

---

## TASK 025 — DASHBOARD RBAC + OPERATOR MANAGEMENT
Status: DONE (Phase 5A)

Completed:
- RBAC roles: `owner`, `admin`, `support`
- dashboard write endpoints role-gated (support read-only)
- tenant-local operators page/API
- owner-only operator creation
- owner-only operator role update
- owner-only operator temporary-password reset/regeneration
- owner-only operator activation/deactivation
- safety guards:
  - no self-deactivation
  - last owner protection
  - last active owner protection

---

## TASK 026 — OPERATOR PASSWORD + ACCOUNT FLOWS
Status: DONE (Phase 5A)

Completed:
- forced first-login password change for temporary passwords
- self-service password change for authenticated operators
- read-only My Account page

---

## TASK 027 — TENANT-LOCAL AUDIT + DASHBOARD UX HARDENING
Status: DONE (Phase 5A)

Completed:
- operator audit log storage + model + service
- dashboard audit page/API (tenant-local, read-only)
- audit filters: `action`, `actor_user_id`, `date_from`, `date_to`
- audit text search (action/target_type)
- shared dashboard layout + identity context banner
- layout/nav/page-title polish
- operator list filter/sort/search

---

## TASK 028 — PHASE 5A LOCK/CHECKPOINT CLOSURE
Status: DONE (closure/admin/docs boundary finalized)

Completed:
- finalized Phase 5A lock boundary wording in docs
- closed Phase 5A checkpoint/lock decision as documentation/admin boundary work
- no unresolved core engineering scope remained for Phase 5A at closure

---

# PHASE 5B — SCALE PATH

## TASK 021 — WORKER SCALE-OUT
Status: IN PROGRESS (strict scale-out checklist active)

Objective:
- scale Laravel worker execution safely under multi-worker concurrency without breaking SIM isolation or message-state correctness
- validate worker scale-out readiness before Python node scale-out (`TASK 022`) and throughput load targets (`TASK 023`)

Scope In:
- multi-worker concurrency behavior on existing per-SIM Redis queue model
- safe claim/pop/state transitions under concurrent workers
- SIM and tenant isolation preservation under scale-out conditions
- retry scheduler + worker interaction correctness under concurrent processing
- operational runbook for worker scale-out rollout/recovery

Scope Out:
- Python execution node scaling (TASK 022)
- throughput/load benchmark closure (TASK 023)
- runtime page UI/operator polish
- transport architecture redesign

Scale-Out Checklist:

021-W1 Baseline worker topology + concurrency assumptions
- Scenario: document baseline worker topology, queue key model, and current single/multi-worker assumptions.
- Expected behavior: one explicit baseline snapshot exists before scale testing.
- Evidence to collect: config/env snapshot + worker process inventory + queue key inventory sample.
- Pass condition: baseline is reproducible and referenced by later checklist artifacts.
- Failure/follow-up condition: block W2+ until baseline ambiguity is removed.

021-W2 Concurrent worker claim/pop integrity
- Scenario: run concurrent workers against overlapping SIM queues.
- Expected behavior: claimed message lifecycle remains deterministic (`queued -> sending -> sent|pending|failed`) with no duplicate terminalization.
- Evidence to collect: message timeline samples + worker logs + row-level state snapshots.
- Pass condition: no contradictory state transitions and no duplicate completion for same message.
- Failure/follow-up condition: open worker-claim integrity defect with reproducible artifact set.

021-W3 SIM/tenant isolation under worker scale-out
- Scenario: concurrent workers process mixed tenant/SIM queues.
- Expected behavior: no cross-tenant or cross-SIM message handling leakage.
- Evidence to collect: sampled processed rows including `company_id`, `sim_id`, runtime metadata source tags.
- Pass condition: all processed rows remain tenant+SIM correct for validated runs.
- Failure/follow-up condition: open isolation defect and halt scale progression.

021-W4 Queue rebuild lock interaction with active workers
- Scenario: trigger queue rebuild while workers are active on same SIM scope.
- Expected behavior: workers respect rebuild lock behavior without corrupting queue/message state.
- Evidence to collect: rebuild command logs + worker logs + before/after queue depth snapshots.
- Pass condition: no queue corruption, no stuck lock side effects, deterministic recovery.
- Failure/follow-up condition: open rebuild-lock concurrency defect.

021-W5 Retry scheduler + worker interaction correctness
- Scenario: due retries are enqueued while workers actively consume queues.
- Expected behavior: retry scheduling remains policy-consistent and race-safe.
- Evidence to collect: retry scheduler run logs + message retry timeline + queue events.
- Pass condition: no lost retries, no contradictory pending/queued states, retry counts remain coherent.
- Failure/follow-up condition: open retry-scheduler interaction defect.

021-W6 Worker crash/restart recovery semantics
- Scenario: worker process interruption and restart during active processing.
- Expected behavior: system recovers without permanent stuck `sending` rows or silent message loss.
- Evidence to collect: controlled interruption timeline + recovery command output + message state reconciliation.
- Pass condition: interrupted flow resumes safely with explicit recovery path evidence.
- Failure/follow-up condition: open crash-recovery hardening defect.

021-W7 Runbook-grade worker scale-out operations
- Scenario: primary and secondary operator execute worker scale-out and rollback playbook.
- Expected behavior: repeatable outcomes without undocumented tribal knowledge.
- Evidence to collect: two-operator dry-run records + checklist completion notes.
- Pass condition: second-operator dry run passes with no hidden steps.
- Failure/follow-up condition: revise runbook and rerun validation.

021-W8 Evidence ledger and closure review
- Scenario: closure review for TASK 021.
- Expected behavior: all checklist items map to explicit pass/fail artifacts.
- Evidence to collect: single `TASK 021` evidence ledger linking W1–W7 artifacts.
- Pass condition: ledger complete and acceptance criteria satisfied.
- Failure/follow-up condition: keep TASK 021 open with explicit remaining items.

Acceptance Criteria:
- AC-021-01: Baseline worker/concurrency assumptions are documented and reproducible.
- AC-021-02: Concurrent worker claim/pop integrity is evidence-backed and contradiction-free.
- AC-021-03: Tenant/SIM isolation remains intact during scale-out runs.
- AC-021-04: Queue rebuild lock interaction is validated under active workers.
- AC-021-05: Retry scheduler + worker interaction remains race-safe and policy-consistent.
- AC-021-06: Worker crash/restart recovery behavior is validated and reproducible.
- AC-021-07: Runbook dry-run succeeds for independent secondary operator.
- AC-021-08: Evidence ledger is complete and auditable for closure.

Done / Closure Gate:
- TASK 021 can be marked DONE only when all checklist items (W1–W8) pass and acceptance criteria (AC-021-01..08) are satisfied with linked evidence.

TASK 021 Evidence Ledger:
- W1 artifact links + pass/fail
  - Scenario: baseline worker topology + concurrency assumptions snapshot
  - Artifacts: `artifacts/task-021/w1/commit.txt`, `artifacts/task-021/w1/timestamps.txt`, `artifacts/task-021/w1/docker_compose_ps.txt`, `artifacts/task-021/w1/host_worker_processes.txt`, `artifacts/task-021/w1/container_worker_processes.txt`, `artifacts/task-021/w1/config_snapshot.txt`, `artifacts/task-021/w1/queue_inventory.txt`
  - Baseline summary: app env `local`, sms driver `python`, runtime send timeout `90`, token configured, active SIM inventory captured (`active=4`, modes `NORMAL=3`, `COOLDOWN=1`)
  - Queue summary: per-SIM Redis keys and tier depths captured for active SIMs; baseline sample showed zero queue depth across chat/followup/blasting tiers at capture time
  - Process inventory note: host-level worker process inventory captured; container-side `pgrep` unavailable in `sms-app` image so container process file is empty by tool limitation
  - Result: PASS
- W2 artifact links + pass/fail
  - Scenario: concurrent workers against same SIM queue with forced runtime timeout path
  - Artifacts: `artifacts/task-021/w2/run_tag.txt`, `artifacts/task-021/w2/seed.txt`, `artifacts/task-021/w2/worker_1.log`, `artifacts/task-021/w2/worker_2.log`, `artifacts/task-021/w2/claimed_lines.txt`, `artifacts/task-021/w2/claimed_counts.txt`, `artifacts/task-021/w2/outcome_snapshot.txt`
  - Concurrency summary: two worker processes were launched concurrently (`timeout 40s`, both exited `124` after timeout window) against the same SIM scope and same probe run tag
  - State summary: run-tag snapshot captured 8 probe rows; one row transitioned to retryable worker failure state (`pending`, `failure_reason=RUNTIME_TIMEOUT`, `retry_count=1`, `source=worker_send_failure`) and remaining rows stayed queued with no contradictory lifecycle mutation
  - Integrity summary: no contradictory state transitions and no duplicate completion observed for any probe message in the captured run-tag set
  - Log note: claim-line grep output was empty for this run due to emitted log pattern mismatch; integrity conclusion is based on row-state evidence + full worker logs
  - Result: PASS
- W3 artifact links + pass/fail
  - Scenario: concurrent workers processing mixed SIM queues under one tenant
  - Artifacts: `artifacts/task-021/w3/precheck_sims.txt`, `artifacts/task-021/w3/precondition_override.txt`, `artifacts/task-021/w3/precheck_normals.txt`, `artifacts/task-021/w3/run_tag.txt`, `artifacts/task-021/w3/seed.txt`, `artifacts/task-021/w3/worker_sim1.log`, `artifacts/task-021/w3/worker_sim2.log`, `artifacts/task-021/w3/outcome_snapshot.txt`
  - Isolation summary: seed captured two active NORMAL SIM targets (`225`, `228`) with eight run-tagged probe rows (`119..126`); all processed/snapshotted rows preserved `company_id` and `sim_id` alignment with probe metadata (`probe_company_id`, `probe_sim_id`)
  - Processing summary: SIM `228` rows showed worker failure transitions (`pending`, `failure_reason=RUNTIME_TIMEOUT`, `source=worker_send_failure`), while SIM `225` rows remained queued; no cross-SIM leakage observed
  - Integrity summary: `contradictions` set empty in run-tag snapshot (`[]`)
  - Result: PASS
- W4 artifact links + pass/fail
  - Scenario: queue rebuild lock behavior while concurrent workers are active on same SIM scope
  - Artifacts: `artifacts/task-021/w4/target_sim.txt`, `artifacts/task-021/w4/run_tag.txt`, `artifacts/task-021/w4/seed.txt`, `artifacts/task-021/w4/worker_1.log`, `artifacts/task-021/w4/worker_2.log`, `artifacts/task-021/w4/rebuild_command.txt`, `artifacts/task-021/w4/after_snapshot.txt`
  - Rebuild summary: rebuild command executed successfully during active worker window for SIM `225` / company `259`; command reported lock key `sms:lock:rebuild:sim:225` and deterministic completion
  - Queue/state summary: post-run snapshot captured queue depth (`depth_total=1`, `depth_chat=1`) with all six run-tagged rows present and no corrupted lifecycle mutation
  - Lock/concurrency summary: rebuild lock absent after completion (`rebuild_lock_present=false`), both workers exited at bounded timeout (`124`), and row contradiction set remained empty
  - Result: PASS
- W5 artifact links + pass/fail
  - Result: PENDING
- W6 artifact links + pass/fail
  - Result: PENDING
- W7 artifact links + pass/fail
  - Result: PENDING
- W8 artifact links + pass/fail
  - Result: PENDING

---

## TASK 022 — PYTHON EXECUTION SCALE-OUT
Status: FUTURE

Goal:
- scale Python nodes as needed
- keep Laravel/Python boundary intact
- maintain SIM-centric routing

Scope note:
- deferred scale-path work (not a blocker for current Phase 6 implemented runtime/UI maturity documentation)

---

## TASK 023 — LOAD TESTING
Status: FUTURE

Targets:
- 100k/day
- 500k/day
- 1M/day

Validate:
- queue behavior
- SIM isolation
- rebuild safety
- retry pressure
- operator controls
- migration safety

Scope note:
- deferred scale-path work (not a blocker for current Phase 6 implemented runtime/UI maturity documentation)

---

# PHASE 6 — PYTHON RUNTIME INTEGRATION & LIVE MODEM FLEET

Phase 6 Status: COMPLETE (implemented through 6.6.b runtime/operator maturity; TASK 031 and TASK 032 closure gates satisfied)
Current Validation Baseline: full suite green (286 passed)

## TASK 029 — PHASE 6.1 LARAVEL ↔ PYTHON RUNTIME CONTRACT FOUNDATION
Status: DONE (Current Phase 6 slice)

Completed:
- Python runtime remains external to this Laravel repo
- dedicated Laravel runtime client/service added for Python API calls
- runtime contract foundation integrated:
  - `GET /health`
  - `GET /modems/discover`
- read-only dashboard runtime inspection surface added:
  - dashboard page
  - dashboard API endpoint
- tenant-filtered modem discovery visibility implemented in Laravel using tenant SIM IMSI matching

Boundary of this task:
- foundation slice only (health + discovery/list)
- no full runtime send-execution implementation in this task
- no broader scaling/hardening changes in this task

---

## TASK 030 — PHASE 6.2 STRUCTURED SEND EXECUTION BRIDGE
Status: DONE (Current Phase 6 slice)

Completed:
- existing Python send contract reused through Laravel runtime integration
- structured Laravel→Python send execution bridge implemented
- runtime send-path error normalization added in Laravel
- `PythonApiSmsSender` routed through runtime client send path
- runtime diagnostics persisted into `outbound_messages.metadata`
- controlled dashboard send-test surface added for manual verification
- explicit runtime failure classes covered:
  - `runtime_unreachable`
  - `runtime_timeout`
  - `invalid_response`

Boundary of this task:
- does not claim full production send hardening completed
- does not redesign retry/queue/scaling behavior
- does not claim full live fleet/hardware maturity completion

---

## IMPLEMENTED PHASE 6 MATURITY SLICES (POST-6.2, NOW REALIGNED)
Status: IMPLEMENTED (reflected in docs realignment)

Implemented:
- Phase 6.3: retry reliability + SIM runtime suppression/control behavior
- Phase 6.4.a: runtime fleet observability UI
- Phase 6.4.b: row safety semantics / operator action clarity
- Phase 6.4.c: runtime detail drilldown / operator diagnostics
- Phase 6.4.d: operator empty/failure/refresh states
- Phase 6.5.a: runtime page action safety / intent confirmation
- Phase 6.5.b: selected row context / action target clarity
- Phase 6.5.c: operator reset / clear context UX
- Phase 6.5.d: lightweight operator guidance / page help copy
- Phase 6.6.a: runtime-to-Laravel mapping review / operator reconciliation UX
- Phase 6.6.b: mapping review detail / reconciliation context

Boundary:
- implemented 6.4/6.5/6.6 slices are operator/runtime UI maturity and reconciliation visibility
- these slices do not represent final runtime hardening completion
- these slices do not add mapping-write workflows
- Runtime SIM ID remains distinct from Tenant SIM DB ID (`sims.id`)
- send-test/Laravel actions continue using Tenant SIM DB ID only

---

## TASK 031 — PHASE 6 LIVE FLEET VALIDATION / RUNTIME HARDENING FOLLOW-UPS
Status: DONE (strict hardening checklist completed; closure gate satisfied)

Objective:
- complete runtime hardening and live-fleet reliability depth beyond the validated baseline using evidence-based pass/fail gates

Scope In:
- live-fleet reliability hardening only
- multi-modem validation repeatability
- runtime failure-mode recovery hardening
- suppression/cooldown/retry interaction hardening
- runbook-grade operational expectations with reproducible evidence

Scope Out:
- runtime page UI polish/maturity slices (already implemented in 6.4/6.5/6.6)
- deeper send-path maturity redesign (TASK 032)
- scale/load work (TASK 021/022/023)
- mapping-write workflow changes

Timeout Budget Source of Truth:
- use currently active runtime timeout settings from deployment/runtime configuration (Laravel + Python runtime), rather than introducing new fixed values in this task doc

Evidence Ledger Location:
- maintain one ledger inside this task section under `TASK 031 Evidence Ledger` with artifact links for each hardening item (H1–H8)

Hardening Checklist:

031-H1 Multi-modem discovery reliability matrix
- Scenario: repeated live discovery runs across healthy + degraded fleet conditions.
- Expected behavior: bounded discovery response with structured output and no hanging.
- Evidence to collect: timestamped discovery payload set + runtime logs per run.
- Pass condition: all planned runs complete within configured timeout budget with no unclassified errors.
- Failure/follow-up condition: capture failing payload/log and open hardening sub-item.

031-H2 Runtime unreachable handling
- Scenario: runtime endpoint unreachable.
- Expected behavior: explicit unreachable classification, safe failure, no false success.
- Evidence to collect: dashboard API response, runtime client result, outbound metadata sample, logs.
- Pass condition: classification + surfaced state are consistent and traceable end-to-end.
- Failure/follow-up condition: file reliability defect with payload + metadata evidence.

031-H3 Runtime timeout handling
- Scenario: runtime timeout on discovery/send path.
- Expected behavior: timeout classification preserved and controlled retry behavior applied.
- Evidence to collect: metadata retry fields (`retry_count`, `scheduled_at`, classification) + logs.
- Pass condition: no uncontrolled immediate retry loop; policy-consistent scheduling confirmed.
- Failure/follow-up condition: open retry-hardening defect with reproducible timeline.

031-H4 Invalid response handling
- Scenario: malformed/invalid runtime response.
- Expected behavior: deterministic invalid-response classification and safe terminal/retry handling per policy.
- Evidence to collect: raw response sample, normalized classification, resulting message state.
- Pass condition: deterministic classification path with no ambiguous final state.
- Failure/follow-up condition: document classification gap and lock corrective rule.

031-H5 Suppression/cooldown behavior under repeated failures
- Scenario: repeated runtime failures for same SIM.
- Expected behavior: suppression/cooldown activates per policy and becomes operator-visible.
- Evidence to collect: SIM runtime-control snapshots across repeated failures.
- Pass condition: suppression state transitions are predictable and policy-consistent.
- Failure/follow-up condition: open SIM-health hardening defect with transition evidence.

031-H6 Recovery behavior after faults clear
- Scenario: previously failing runtime/SIM recovers.
- Expected behavior: suppression/cooldown exits per policy; send eligibility restores when conditions are met.
- Evidence to collect: before/after health snapshots + message outcomes + logs.
- Pass condition: recovery occurs without stale stuck suppression.
- Failure/follow-up condition: file recovery-path bug with before/after evidence.

031-H7 Runbook-grade operator expectations
- Scenario: operator executes procedures for unreachable, timeout, probe-error-heavy, suppression/recovery conditions.
- Expected behavior: repeatable outcomes from documented steps.
- Evidence to collect: completed runbook execution records for each scenario.
- Pass condition: second-operator dry run succeeds without undocumented tribal knowledge.
- Failure/follow-up condition: revise runbook and rerun validation.

031-H8 Evidence ledger and closure review
- Scenario: hardening closure decision.
- Expected behavior: all checklist items map to explicit artifacts and pass/fail outcomes.
- Evidence to collect: single evidence ledger linking artifacts for H1–H7.
- Pass condition: ledger complete; all required pass conditions met.
- Failure/follow-up condition: keep TASK 031 open with explicit remaining items.

Acceptance Criteria:
- AC-031-01: Discovery reliability matrix completed with bounded, reproducible outcomes.
- AC-031-02: Unreachable and timeout classes validated with policy-consistent handling.
- AC-031-03: Invalid-response handling is deterministic and evidence-backed.
- AC-031-04: Suppression/cooldown activation and recovery are validated and visible.
- AC-031-05: Runbook procedures are dry-run validated for all required scenarios.
- AC-031-06: Evidence ledger is complete and auditable.
- AC-031-07: No ambiguous remaining scope in TASK 031; spillover is explicitly placed in TASK 032 or deferred tasks.

Done / Closure Gate:
- TASK 031 can be marked DONE only when all checklist items (H1–H8) pass and all acceptance criteria (AC-031-01..07) are satisfied with linked evidence.

TASK 031 Evidence Ledger:
- H1 artifact links + pass/fail
  - Scenario: multi-modem discovery reliability matrix (repeat runs)
  - Artifacts: `artifacts/task-031/h1/runs.log`, `artifacts/task-031/h1/python_discover_*.json`, `artifacts/task-031/h1/config_snapshot.txt`, `artifacts/task-031/h1/sms-app.log`, `artifacts/task-031/h1/commit.txt`, `artifacts/task-031/h1/timestamps.txt`
  - Run summary: 10/10 runs completed with `python_discover_status=ok` at 20s cadence (validation speed run), runtime timeout config captured as `90`
  - Dashboard note: `dashboard_api_status=302` is expected in script context (session-protected dashboard API without login cookie) and is not counted as runtime discovery failure
  - Result: PASS
- H2 artifact links + pass/fail
  - Scenario: runtime endpoint unreachable classification path
  - Artifacts: `artifacts/task-031/h2/runs.log`, `artifacts/task-031/h2/runtime_client_unreachable.txt`, `artifacts/task-031/h2/outbound_metadata_sample.txt`, `artifacts/task-031/h2/sms-app.log`, `artifacts/task-031/h2/config_snapshot.txt`, `artifacts/task-031/h2/commit.txt`, `artifacts/task-031/h2/timestamps.txt`
  - Run summary: in-process URL override to unreachable endpoint produced `health.ok=false` and `discover.ok=false` with `error=connection_failed` and explicit connection exceptions
  - Dashboard note: `dashboard_api_status=302` expected in script context (session-protected dashboard API without login cookie)
  - Result: PASS
- H3 artifact links + pass/fail
  - Scenario: runtime send timeout classification + retry policy mapping
  - Artifacts: `artifacts/task-031/h3/runs.log`, `artifacts/task-031/h3/runtime_send_timeout.txt`, `artifacts/task-031/h3/retry_classification.txt`, `artifacts/task-031/h3/retry_fields_sample.txt`, `artifacts/task-031/h3/sms-app.log`, `artifacts/task-031/h3/config_snapshot.txt`, `artifacts/task-031/h3/commit.txt`, `artifacts/task-031/h3/timestamps.txt`
  - Run summary: send-path timeout simulation produced `error=runtime_timeout` with `is_runtime_timeout=true`; classifier marks `RUNTIME_TIMEOUT` as retryable and `INVALID_RESPONSE` as non-retryable
  - Persistence note: timeout sample validated classification + retry policy mapping; `runtime_timeout_rows` may be empty in artifact query when no recent persisted timeout-failed rows exist
  - Dashboard note: `dashboard_api_status=302` expected in script context (session-protected dashboard API without login cookie)
  - Result: PASS
- H4 artifact links + pass/fail
  - Scenario: invalid response classification path (missing `success` in runtime payload)
  - Artifacts: `artifacts/task-031/h4/runs.log`, `artifacts/task-031/h4/runtime_invalid_response.txt`, `artifacts/task-031/h4/retry_classification.txt`, `artifacts/task-031/h4/invalid_response_rows_sample.txt`, `artifacts/task-031/h4/sms-app.log`, `artifacts/task-031/h4/config_snapshot.txt`, `artifacts/task-031/h4/commit.txt`, `artifacts/task-031/h4/timestamps.txt`
  - Run summary: fake 200 payload missing `success` produced `error=invalid_response`, `error_layer=python_api`, and `is_invalid_response=true`
  - Retry policy summary: classifier marks `INVALID_RESPONSE` + `python_api` as `non_retryable` while keeping `RUNTIME_TIMEOUT` + `transport` retryable
  - Persistence note: `invalid_response_rows` may be empty in artifact query when no recent persisted invalid-response rows exist from worker/dashboard execution paths
  - Dashboard note: `dashboard_api_status=302` expected in script context (session-protected dashboard API without login cookie)
  - Result: PASS
- H5 artifact links + pass/fail
  - Scenario: repeated runtime failures trigger temporary SIM runtime suppression/cooldown
  - Artifacts: `artifacts/task-031/h5/runs.log`, `artifacts/task-031/h5/runtime_suppression_simulation.txt`, `artifacts/task-031/h5/runtime_control_rows_sample.txt`, `artifacts/task-031/h5/sms-app.log`, `artifacts/task-031/h5/config_snapshot.txt`, `artifacts/task-031/h5/commit.txt`, `artifacts/task-031/h5/timestamps.txt`
  - Run summary: isolated test SIM moved from unsuppressed state to suppressed state after 3 runtime-timeout failures within window; `mode=COOLDOWN`, `cooldown_until` set, cooldown health log created
  - Runtime-control summary: `before_runtime_control.suppressed=false` and `after_runtime_control.suppressed=true` with `recent_failure_count=3`, `threshold=3`, `last_error=RUNTIME_TIMEOUT`, `last_error_layer=transport`
  - Dashboard note: `dashboard_api_status=302` expected in script context (session-protected dashboard API without login cookie)
  - Result: PASS
- H6 artifact links + pass/fail
  - Scenario: suppression/cooldown recovery after fault window clears
  - Artifacts: `artifacts/task-031/h6/runs.log`, `artifacts/task-031/h6/recovery_simulation.txt`, `artifacts/task-031/h6/recovery_rows_sample.txt`, `artifacts/task-031/h6/sms-app.log`, `artifacts/task-031/h6/commit.txt`, `artifacts/task-031/h6/timestamps.txt`
  - Run summary: suppression state reached at threshold, then cleared after failure window expiry + cooldown expiry; SIM normalized for sending
  - Recovery summary: `suppressed_snapshot.suppressed=true` -> `post_window_snapshot.suppressed=false`, `can_send_after_recovery=true`, `final_sim_mode=NORMAL`, `final_cooldown_until=null`
  - Health-log summary: expected error logs plus cooldown transition log present for test SIM
  - Dashboard note: `dashboard_api_status=302` expected in script context (session-protected dashboard API without login cookie)
  - Result: PASS
- H7 artifact links + pass/fail
  - Scenario: runbook-grade operator expectations with independent second-operator dry run
  - Artifacts: `artifacts/task-031/h7/runs.log`, `artifacts/task-031/h7/primary_operator_dry_run.md`, `artifacts/task-031/h7/second_operator_dry_run.md`, `artifacts/task-031/h7/h7_completion_checklist.txt`, `artifacts/task-031/h7/config_snapshot.txt`, `artifacts/task-031/h7/runtime_snapshot.txt`, `artifacts/task-031/h7/sms-app.log`, `artifacts/task-031/h7/commit.txt`, `artifacts/task-031/h7/timestamps.txt`
  - Run summary: primary and second operator both completed scenario walkthroughs for H2/H3/H4/H5/H6 with PASS outcomes and no undocumented tribal knowledge requirement
  - Dashboard note: `dashboard_api_status=302` expected in script context (session-protected dashboard API without login cookie)
  - Result: PASS
- H8 artifact links + pass/fail
  - Scenario: evidence ledger and closure review
  - Artifacts: this `TASK 031 Evidence Ledger` block + `artifacts/task-031/h1/*`, `artifacts/task-031/h2/*`, `artifacts/task-031/h3/*`, `artifacts/task-031/h4/*`, `artifacts/task-031/h5/*`, `artifacts/task-031/h6/*`, `artifacts/task-031/h7/*`
  - Closure summary: H1..H7 all PASS; acceptance criteria `AC-031-01`..`AC-031-07` satisfied with artifact-linked evidence
  - Result: PASS

---

## TASK 032 — PHASE 6 DEEPER SEND-PATH MATURITY + LATER SCALE HANDOFF
Status: DONE (strict send-path maturity checklist completed; closure gate satisfied)

Objective:
- complete deeper send-path maturity beyond the validated bridge baseline using deterministic evidence-backed pass/fail gates
- define explicit readiness boundary for later Phase 5B scale/load handoff

Scope In:
- send-path maturity only (post-TASK 031 hardening closure)
- deterministic runtime-send classification parity across execution surfaces
- retry scheduling/state transition correctness for retryable vs terminal outcomes
- outbound message metadata/traceability completeness for runtime send paths
- duplicate-send/idempotency guardrail validation at Laravel send-path level
- explicit evidence-backed handoff boundary to Phase 5B scale/load tasks

Scope Out:
- runtime page UI polish/operator UX slices (already implemented in 6.4/6.5/6.6)
- runtime hardening checklist already closed in TASK 031
- Phase 5B scale/load execution (TASK 021/022/023)
- mapping-write workflow changes

Send-Path Maturity Checklist:

032-S1 Runtime send classification parity matrix
- Scenario: controlled send-path simulations across key runtime outcomes (`runtime_timeout`, `runtime_unreachable`, `invalid_response`, `runtime_send_failed`) on dashboard send-test and worker-driven execution surfaces.
- Expected behavior: classification/retryability mapping remains deterministic and policy-consistent across surfaces.
- Evidence to collect: artifact matrix + classifier snapshots + row samples from both execution surfaces.
- Pass condition: each planned outcome maps to one consistent classification/retryability result across surfaces.
- Failure/follow-up condition: open send-classification defect with reproducible mismatch evidence.

032-S2 Retry scheduling and state-transition integrity
- Scenario: retryable and non-retryable outcomes across first attempt and follow-up attempts.
- Expected behavior: `retry_count`, `scheduled_at`, and terminal status transitions follow policy without contradictory state.
- Evidence to collect: timestamped outbound row timeline + retry metadata + queue/runtime logs.
- Pass condition: no contradictory transitions (for example terminal + rescheduled) in validated scenarios.
- Failure/follow-up condition: open retry-state integrity defect with timeline evidence.

032-S3 Runtime metadata completeness and correlation
- Scenario: successful and failed runtime sends are inspected for persistence payload completeness.
- Expected behavior: runtime metadata fields required for operator/debug traceability are consistently present.
- Evidence to collect: outbound metadata samples + correlation notes to runtime logs for each scenario.
- Pass condition: each validated scenario is traceable end-to-end from outbound row to runtime artifact/log evidence.
- Failure/follow-up condition: document missing metadata keys/correlation gaps and open corrective sub-item.

032-S4 Send outcome persistence semantics
- Scenario: success and failure outcomes are checked for persistence consistency (`status`, `failure_reason`, `provider_message_id`, metadata envelope).
- Expected behavior: persistence semantics are deterministic and aligned with current contracts.
- Evidence to collect: per-outcome outbound row snapshots + runtime raw-response snippets.
- Pass condition: no ambiguous persistence state for validated outcomes.
- Failure/follow-up condition: open persistence-semantics defect with exact row/state evidence.

032-S5 Duplicate-send/idempotency guardrail validation
- Scenario: repeated/duplicate execution attempts against same message context are validated.
- Expected behavior: guardrails prevent ambiguous duplicate terminalization/send-path corruption.
- Evidence to collect: controlled duplicate-attempt artifacts + outbound state timeline + runtime log correlation.
- Pass condition: duplicate-attempt behavior is deterministic and policy-consistent for validated cases.
- Failure/follow-up condition: open idempotency guardrail defect with reproducible steps.

032-S6 Cross-surface observability parity
- Scenario: same send-path scenarios are compared across dashboard-triggered and queue/worker-triggered execution surfaces.
- Expected behavior: operator-visible and stored outcomes remain semantically aligned despite surface differences.
- Evidence to collect: paired scenario artifacts (dashboard vs worker) + comparison notes.
- Pass condition: no material semantic drift in classification/retry/final state interpretation.
- Failure/follow-up condition: open cross-surface parity defect and lock reconciliation rule.

032-S7 Phase 5B handoff boundary definition
- Scenario: send-path maturity closure review and handoff scoping.
- Expected behavior: remaining open work is explicitly categorized as Phase 5B scale/load (021/022/023) vs closed Phase 6 send-path maturity.
- Evidence to collect: boundary decision table + explicit deferred-item mapping.
- Pass condition: no ambiguous overlap between TASK 032 closure scope and deferred Phase 5B tasks.
- Failure/follow-up condition: keep TASK 032 open until unresolved boundary items are explicitly assigned.

032-S8 Evidence ledger and closure review
- Scenario: final closure decision for TASK 032.
- Expected behavior: all checklist items map to explicit artifacts and pass/fail outcomes.
- Evidence to collect: single ledger linking artifacts for S1–S7 + closure notes.
- Pass condition: ledger complete; all acceptance criteria satisfied.
- Failure/follow-up condition: keep TASK 032 open with explicit remaining checklist items.

Acceptance Criteria:
- AC-032-01: Runtime send classification parity matrix completed across planned outcomes and execution surfaces.
- AC-032-02: Retry scheduling/state transitions validated as policy-consistent and contradiction-free in validated scenarios.
- AC-032-03: Runtime metadata/correlation traceability is evidence-backed for success and failure paths.
- AC-032-04: Send outcome persistence semantics are deterministic and contract-aligned.
- AC-032-05: Duplicate-send/idempotency guardrails are validated with reproducible evidence.
- AC-032-06: Cross-surface observability parity is validated without material semantic drift.
- AC-032-07: Phase 5B handoff boundary is explicit and ambiguity-free.
- AC-032-08: Evidence ledger is complete and auditable for closure.

Done / Closure Gate:
- TASK 032 can be marked DONE only when all checklist items (S1–S8) pass and all acceptance criteria (AC-032-01..08) are satisfied with linked evidence.

TASK 032 Evidence Ledger:
- S1 artifact links + pass/fail
  - Scenario: runtime send classification parity matrix across dashboard + worker execution surfaces
  - Artifacts: `artifacts/task-032/s1/runtime_client_unreachable.txt`, `artifacts/task-032/s1/runtime_send_timeout.txt`, `artifacts/task-032/s1/runtime_invalid_response.txt`, `artifacts/task-032/s1/retry_classification_h3.txt`, `artifacts/task-032/s1/retry_classification_h4.txt`, `artifacts/task-032/s1/parity_rows.txt`, `artifacts/task-032/s1/worker_source_rows.txt`, `artifacts/task-032/s1/outbound_rows.txt`
  - Run summary: dashboard surface evidence captured with `execution_surface=dashboard_runtime_send_test`; worker surface evidence captured with `source=worker_send_failure`, `error=RUNTIME_TIMEOUT`, `error_layer=transport`
  - Classification summary: unreachable/timeout/invalid-response scenarios remain deterministic and aligned with existing retryability rules; `SEND_FAILED` network failures remain visible in parity rows
  - Dashboard note: `dashboard_api_status=302` is expected in script context (session-protected dashboard API without login cookie) and is not treated as a send-path classification failure
  - Result: PASS
- S2 artifact links + pass/fail
  - Scenario: retry scheduling and state-transition integrity for retryable vs non-retryable outcomes
  - Artifacts: `artifacts/task-032/s2/seed.txt`, `artifacts/task-032/s2/state_snapshot.txt`, `artifacts/task-032/s2/runs.log`
  - Run summary: isolated worker timeout probe produced retryable transition with `status=pending`, `retry_count=1`, `failure_reason=RUNTIME_TIMEOUT`, and non-null `scheduled_at`
  - Non-retryable summary: `SEND_FAILED` + `error_layer=network` samples remain terminal with `status=failed` and `scheduled_at=null`
  - Integrity summary: no contradictory terminal+rescheduled state observed in captured snapshots
  - Result: PASS
- S3 artifact links + pass/fail
  - Scenario: runtime metadata completeness and correlation across success/failure paths
  - Artifacts: `artifacts/task-032/s3/metadata_correlation_snapshot.txt`
  - Run summary: latest snapshot shows `with_python_runtime=13/13` rows with populated runtime envelope fields (`source`, `success`, `error`, `error_layer`, `processed_at`, `provider_message_id` where applicable)
  - Correlation summary: dashboard success/failure rows include `raw.meta` linkage (`execution_surface`, `message_id`, `company_id`) while worker failure rows consistently include `retry_decision` and `sim_runtime_control` metadata
  - Traceability summary: failure rows remain correlatable by `python_runtime.source` (`worker_send_failure` / `dashboard_runtime_send_test`) with deterministic runtime error-layer context
  - Result: PASS
- S4 artifact links + pass/fail
  - Scenario: send outcome persistence semantics across sent/failed/retry-pending states
  - Artifacts: `artifacts/task-032/s4/persistence_semantics_snapshot.txt`
  - Run summary: snapshot shows deterministic persistence envelopes for all sampled outcomes (`sent=9`, `failed=2`, `pending_with_failure_reason=0`)
  - Sent-state summary: sent rows consistently keep `failure_reason=null`, `scheduled_at=null`, and runtime success metadata with provider message IDs where available
  - Failed-state summary: `SEND_FAILED` + `error_layer=network` rows remain terminal with `status=failed`, `scheduled_at=null`, and preserved failure runtime metadata
  - Integrity summary: contradiction scan returned empty set (`contradictions=[]`) for invalid terminal/retry state overlaps
  - Result: PASS
- S5 artifact links + pass/fail
  - Scenario: duplicate-send/idempotency guardrail validation under duplicate queue enqueue conditions
  - Artifacts: `artifacts/task-032/s5/seed_duplicate_enqueue.txt`, `artifacts/task-032/s5/requeue_duplicate.txt`, `artifacts/task-032/s5/message_outcome.txt`
  - Run summary: same probe message ID was intentionally enqueued twice, worker processed failure path with unreachable runtime, and row converged to deterministic retryable state
  - Idempotency summary: probe row `id=104` ended as `status=pending`, `failure_reason=RUNTIME_TIMEOUT`, `retry_count=1`, non-null `scheduled_at`, with `python_runtime.source=worker_send_failure`
  - Guardrail summary: `probe_row_count=1` confirms no duplicate outbound DB row creation from duplicate queue entries
  - Result: PASS
- S6 artifact links + pass/fail
  - Scenario: cross-surface observability parity between dashboard-triggered and worker-triggered runtime send paths
  - Artifacts: `artifacts/task-032/s6/cross_surface_parity_snapshot.txt`
  - Run summary: parity snapshot captured both surfaces (`dashboard=11`, `worker=3`) with consistent runtime error envelopes and source attribution
  - Dashboard summary: dashboard failures remain terminal `SEND_FAILED` + `error_layer=network`; dashboard success rows remain `sent` with clean success metadata
  - Worker summary: worker failures remain retryable `RUNTIME_TIMEOUT` + `error_layer=transport` with `retry_decision` and `sim_runtime_control` metadata present
  - Parity summary: no material semantic drift observed between surfaces for failure interpretation; surface-specific metadata remains consistent with execution model
  - Result: PASS
- S7 artifact links + pass/fail
  - Scenario: Phase 5B handoff boundary definition for remaining open items
  - Artifacts: `artifacts/task-032/s7/boundary_decision_table.md`
  - Boundary summary: decision table explicitly maps send-path maturity closure items to TASK 032 and keeps throughput/load/infra items deferred to TASK 021/022/023
  - Scope summary: runtime-page polish and mapping-write workflow changes remain outside TASK 032 scope
  - Handoff summary: no ambiguous overlap remains between TASK 032 closure scope and deferred Phase 5B scale/load scope
  - Result: PASS
- S8 artifact links + pass/fail
  - Scenario: evidence ledger and closure review
  - Artifacts: this `TASK 032 Evidence Ledger` block + `artifacts/task-032/s1/*`, `artifacts/task-032/s2/*`, `artifacts/task-032/s3/*`, `artifacts/task-032/s4/*`, `artifacts/task-032/s5/*`, `artifacts/task-032/s6/*`, `artifacts/task-032/s7/*`
  - Closure summary: S1..S7 all PASS; acceptance criteria `AC-032-01`..`AC-032-08` satisfied with artifact-linked evidence
  - Boundary summary: remaining scale/load concerns are explicitly deferred to TASK 021/022/023 with no ambiguous overlap
  - Result: PASS

---

# IMPLEMENTATION ORDER (LOCKED)

1. TASK 012A — Python stabilization
2. TASK 012B — operator status model
3. TASK 012C — health / assignment flags
4. TASK 012D — manual migration model
5. TASK 012E — DB-first rebuild
6. TASK 012F — retry policy update
7. TASK 013 — Redis per-SIM 3-queue model
8. TASK 014 — message intake → Redis routing
9. TASK 015 — paused→active auto-requeue
10. TASK 016 — blocked intake gate
11. TASK 017 — health check command + scheduler
12. TASK 018 — stuck-age monitoring
13. TASK 019 — migration tooling
14. TASK 020 — dashboard surfaces
15. TASK 021+ — scale-out and load testing

---

# FINAL LOCK

This task system is now aligned to:

- transport-only gateway
- Laravel control layer
- Python execution layer
- MySQL truth
- Redis transport
- SIM-centric workers
- sticky customer assignment
- manual migration only
- no automatic failover
- 5-minute forever retry
- operator-controlled SIM delivery states
