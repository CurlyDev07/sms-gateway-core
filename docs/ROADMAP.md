# ROADMAP

---

## CURRENT PHASE
Phase 2 – IN PROGRESS

### Phase Status
- Phase 0: COMPLETE (Locked)
- Phase 1: COMPLETE (Locked)
- Phase 2: IN PROGRESS
- Phase 3: NOT STARTED
- Phase 1 lock result: manual migration baseline + failover/reassign hardening complete
- Phase 2 slice checkpoint: Redis transport + rebuild + retry + worker/controller/event wiring implemented (+ worker Redis-path + Laravel-side Python integration coverage)
- Phase 2 checkpoint validation: full suite green (109 passed)

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

## IN PROGRESS
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
  - full suite green (109 passed)
  - Python execution service/runtime work remains open under Task 012A

---

## NEXT

### Phase 2A — Python SMS Execution Layer Stabilization
- Python API server (`/send`, `/modems/discover`, `/modems/health`)
- Stable modem discovery / modem registry
- Stable SIM-centric identity resolution
- AT command SMS sending (`AT+CMGS`)
- Multi-modem support (USB hub)
- Modem health check
- Structured modem / hardware error normalization
- No full scan during send path
- Execution-layer correctness and observability

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
- Introduce Redis as queue transport / coordination layer
- Per-SIM isolated queue model
- 3 Redis queues per SIM:
  - `chat`
  - `followup`
  - `blasting`
- Worker priority order:
  1. chat
  2. followup
  3. blasting
- AUTO_REPLY grouped into chat tier
- Per-SIM worker consumption from Redis
- Redis rebuild lock for queue rebuild safety
- DB-first queue rebuild from MySQL truth
- pending-only safe rebuild scope
- paused → active auto-requeue path
- no duplicate queue rebuild guarantee

### Phase 3 exit condition
- each SIM has isolated queue behavior
- one SIM never blocks another SIM
- Redis is active for queue transport
- MySQL remains source of truth

---

## PHASE 4 — MONITORING + CONTROL SURFACES
- SIM health monitoring
- Per-SIM queue depth visibility
- per-SIM message counts by tier
- operator status visibility
- last success visibility
- stuck-age warnings
- admin APIs / controls for:
  - SIM status
  - enable/disable new assignments
  - migration
  - queue rebuild / recovery tools
- logging and error tracking

---

## PHASE 5 — SCALING INFRASTRUCTURE
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

## PHASE 6 — OPERATIONAL TOOLS
- Dashboard (gateway monitoring only)
- Alerting system (SIM stuck, no success, queue growth, modem issues)
- performance analytics:
  - send rate
  - retry rate
  - failure rate
  - queue depth by SIM
- operator runbooks
- recovery tooling
- migration tooling
- queue repair tooling

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
