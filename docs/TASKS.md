# TASKS

Last Updated: 2026-04-03

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

# PHASE 2 CONTINUATION — FINAL ALIGNED TASKS

## TASK 012A — PYTHON SMS EXECUTION LAYER STABILIZATION
Status: NEXT

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

---

## TASK 012B — OPERATOR STATUS MODEL
Status: NEXT

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

---

## TASK 012C — HEALTH / ASSIGNMENT FLAGS
Status: NEXT

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

---

## TASK 012D — MANUAL MIGRATION ONLY
Status: IN PROGRESS (SLICE 1 COMPLETE)

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

Remaining in this task:
- continue Phase 1 migration hardening and full checklist completion before Phase 1 lock

---

## TASK 012E — DB-FIRST QUEUE REBUILD
Status: NEXT

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

---

## TASK 012F — RETRY POLICY UPDATE
Status: NEXT

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

---

# PHASE 3 — REDIS PER-SIM QUEUE ARCHITECTURE

## TASK 013 — REDIS PER-SIM 3-QUEUE MODEL
Status: NEXT

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

---

## TASK 014 — MESSAGE INTAKE → REDIS ROUTING
Status: NEXT

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

---

## TASK 015 — PAUSED→ACTIVE AUTO-REQUEUE
Status: NEXT

Goal:
When SIM resumes from paused to active:
- rebuild that SIM’s Redis queues from DB truth
- no manual requeue command required

Requirements:
- event/listener or equivalent orchestration
- worker-visible rebuild lock
- no duplicate queue entries
- no message loss

---

## TASK 016 — BLOCKED INTAKE GATE
Status: NEXT

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

---

# PHASE 4 — MONITORING + OPERATOR TOOLS

## TASK 017 — HEALTH CHECK COMMAND + SCHEDULER
Status: NEXT

Goal:
- scheduled check every 5 minutes
- set `disabled_for_new_assignments` where appropriate
- drive operator visibility

---

## TASK 018 — STUCK-AGE MONITORING
Status: NEXT

Goal:
Surface:
- 30-minute no-success alert
- 6h stuck
- 24h stuck
- 3d stuck

Use:
- `last_success_at`

Do not stop retries automatically.

---

## TASK 019 — MANUAL MIGRATION TOOLING
Status: NEXT

Goal:
Operator tooling for:
- bulk SIM migration
- single-customer migration
- queue rebuild support
- safe recovery flow

Migration flow must be documented and testable.

---

## TASK 020 — DASHBOARD SURFACES
Status: FUTURE-NEXT

Dashboard needs per SIM:
- queued count
- messages by tier
- operator_status
- assignment flags
- last_success_at
- active customer count
- failed/retrying visibility
- signal / modem health where available
- cross-tenant operator monitoring where allowed

---

# PHASE 5 — SCALE PATH

## TASK 021 — WORKER SCALE-OUT
Status: FUTURE

Goal:
- scale per-SIM workers
- scale Redis transport
- scale Laravel control plane
- preserve SIM isolation

---

## TASK 022 — PYTHON EXECUTION SCALE-OUT
Status: FUTURE

Goal:
- scale Python nodes as needed
- keep Laravel/Python boundary intact
- maintain SIM-centric routing

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
