# CHANGELOG

Last Updated: 2026-04-03

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
