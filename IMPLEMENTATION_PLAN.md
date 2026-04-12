# SMS Gateway Core – Implementation Plan (Revised)

**Last Updated:** 2026-04-12 (TASK 021 strict checklist kickoff checkpoint)
**Status:** Phase 0/1/2/4 Complete (Locked) — Phase 5A Complete (Locked), Phase 6 Complete (Locked), Phase 5B In Progress (`TASK 021` active)
**Alignment:** Validated against all 9 locked docs with phase-boundary corrections

---

## 1. PURPOSE

This document specifies the exact implementation order for the SMS Gateway Core, translating locked architectural decisions (2026-03-29) into actionable phases with zero overlap and clear dependencies.

**Core Principle:** Implement incrementally from safest (schema + control) → to foundational (manual migration) → to architectural (Redis + rebuild) → to visibility (monitoring).

**DO NOT BREAK:** Transport-only boundary, SIM-centric model, layer ownership (Laravel control, Python execute, MySQL truth, Redis transport), manual migration, 5-minute forever retry, sticky assignment, worker-visible rebuild lock.

---

## 2. CRITICAL DO-NOT-BREAK RULES

### Layer Ownership (Immutable)

- **Laravel** = control layer: tenant isolation, SIM selection, retry scheduling, migration, operator controls, monitoring state
- **Python** = execution layer: modem communication, AT commands, hardware normalization only
- **MySQL** = source of truth: all durable state, queue rebuilds from DB only
- **Redis** = queue transport + coordination: per-SIM queues, rebuild locks only, no business truth

### SIM-Centric Model (Immutable)

- One SIM = one isolated lane (never blocks another SIM)
- Sticky customer assignment (no silent reassignment, only manual migration)
- New customer by current queue load (not historical count)
- All queueing, workers, retry, monitoring are per SIM

### Operator Control Model (Immutable)

- `active`: accept + save + queue + process → 200 OK
- `paused`: accept + save, no queue, worker skips → 202 Accepted warning
- `blocked`: reject new intake, no save → 503 Service Unavailable; old queued work may still drain (worker behavior)
- Health-based disable: `disabled_for_new_assignments` only, not sticky customer movement or retry stopping
- Manual migration only: no automatic failover

### Retry Policy (Immutable)

- Fixed 5-minute interval forever
- No max attempts, no auto-stop, no auto cross-SIM failover
- Stay on same SIM until success or manual migration

### Queue Rebuild Pattern (Immutable)

- DB-first always (MySQL truth, Redis rebuilt from DB)
- Pending-only scope (do not requeue `sending` directly)
- Worker-visible rebuild lock (Redis semaphore `sms:lock:rebuild:sim:{sim_id}`)
- Worker checks lock before any operation

### Health Basis (Immutable)

- `last_success_at` only (not dispatch or attempt time)
- 30-minute no-success = unhealthy warning
- Auto-disable new assignments only if company has >1 SIM
- Health checks every 5 minutes

### Multi-Tenant (Immutable)

- Tenant identity from auth context ONLY, never from request body
- All operations enforce tenant boundaries

---

## 3. REVISED PHASE STRUCTURE

### Phase Dependency Graph

```
Phase 0 (Control Baseline)
    ↓
Phase 1 (Manual Migration)
    ↓
Phase 2 (Redis + Rebuild Lock + Auto-Requeue)
    ↓
Phase 4 (Operator API + Dashboard Surfaces) [Locked]
```

Phase 3 scope was absorbed into Phase 2 and is retained in docs only for historical traceability.
Each phase is **self-contained** and does NOT depend on future phases.

---

## 4. PHASE 0: SCHEMA + CONTROL BASELINE

**Goal:** Add operator control fields, health tracking, and correct retry policy. Implement intake guardrails for operator status.

**Status:** Complete (Locked)
**Lock Result:** 39/39 tests passed; Phase 0 complete and locked
**Duration:** 2-3 days
**Risk Level:** Low (additive schema, no worker changes, no Redis yet)
**What's Locked:** All architecture, all rules
**What's New:** operator_status, accept_new_assignments, disabled_for_new_assignments, last_success_at fields + Phase 0 control logic only

### 4.1 Phase 0 Scope

**Architecture Preserved:**
- No Redis queues yet (DB-claim approach continues)
- No rebuild lock logic (no Redis to rebuild)
- No paused→active auto-requeue yet (requires rebuild logic + Redis)
- No blocked worker behavior semantics yet (only intake rejection) — worker behavior updates in Phase 2
- MySQL remains source of truth, workers still claim from DB
- SmsSenderInterface unchanged

**What Phase 0 Implements:**

1. **Database Schema Changes**
   - Add SIM fields: `operator_status` (enum: active, paused, blocked)
   - Add SIM fields: `accept_new_assignments` (bool, default true for existing, false for new)
   - Add SIM fields: `disabled_for_new_assignments` (bool, default false)
   - Add SIM fields: `last_success_at` (timestamp, nullable, updated only on real successful send)

2. **Services Created**
   - **SimHealthService** — compute health status, check 30-min threshold, auto-disable logic
   - **SimOperatorStatusService** — update operator_status, pause/block/activate SIM

3. **Commands Created**
   - **CheckSimHealthCommand** — scheduled every 5 minutes, runs health checks, auto-disables from new assignments if needed
   - **SetSimOperatorStatusCommand** — manual: change operator_status to active/paused/blocked
   - **EnableSimForNewAssignmentsCommand** — manual: set accept_new_assignments = true
   - **DisableSimForNewAssignmentsCommand** — manual: set accept_new_assignments = false

4. **Service Updates**
   - **OutboundRetryService** — Change from exponential backoff to fixed 5-minute interval, remove max-attempt caps, messages retry forever
   - **SimSelectionService** — Filter available SIMs by `accept_new_assignments && !disabled_for_new_assignments`

5. **API Changes**
   - **GatewayOutboundController::store()** — Check operator_status before enqueue:
     - If paused: save to DB, return 202 Accepted with warning, do NOT enqueue
     - If blocked: reject, return 503 Service Unavailable, do NOT save
     - If active: save and enqueue (enqueue logic unchanged from current implementation)

### 4.2 Phase 0 Files

**New:**
- `app/Services/SimHealthService.php`
- `app/Services/SimOperatorStatusService.php`
- `app/Console/Commands/CheckSimHealthCommand.php`
- `app/Console/Commands/SetSimOperatorStatusCommand.php`
- `app/Console/Commands/EnableSimForNewAssignmentsCommand.php`
- `app/Console/Commands/DisableSimForNewAssignmentsCommand.php`
- `database/migrations/2026_03_29_120000_add_operator_control_fields_to_sims.php`

**Modified:**
- `app/Models/Sim.php` — add fields, fillable, casts, accessors, mark_successful() method
- `app/Services/OutboundRetryService.php` — change to fixed 5-minute retry forever
- `app/Services/SimSelectionService.php` — filter by accept_new_assignments && !disabled_for_new_assignments
- `app/Http/Controllers/GatewayOutboundController.php` — check operator_status, return 202/503 as needed
- `app/Console/Kernel.php` — register CheckSimHealthCommand to run every 5 minutes

### 4.3 Phase 0 Database Migration

```php
// database/migrations/2026_03_29_120000_add_operator_control_fields_to_sims.php
Schema::table('sims', function (Blueprint $table) {
    // Operator-controlled delivery status
    $table->enum('operator_status', ['active', 'paused', 'blocked'])
        ->default('active')
        ->after('mode');

    // New SIM enablement (new SIMs default false, existing default true)
    $table->boolean('accept_new_assignments')
        ->default(true)
        ->after('operator_status');

    // Health-based assignment disable (auto-set by health check)
    $table->boolean('disabled_for_new_assignments')
        ->default(false)
        ->after('accept_new_assignments');

    // Health basis: only real successful sends update this
    $table->timestamp('last_success_at')
        ->nullable()
        ->after('last_error_at');

    // Indexes for operator controls and health checks
    $table->index(['company_id', 'operator_status']);
    $table->index(['company_id', 'disabled_for_new_assignments']);
    $table->index(['last_success_at']);
});
```

### 4.4 Phase 0 Key Implementation Details

**SimHealthService.php:**
```php
class SimHealthService {
    public function checkHealth(Sim $sim): array {
        $lastSuccess = $sim->last_success_at;
        $minutesSinceSuccess = now()->diffInMinutes($lastSuccess);

        // 30-minute threshold
        if ($minutesSinceSuccess >= 30) {
            // Unhealthy
            if ($sim->company->sims_count > 1) {
                // Auto-disable from new assignments
                $sim->update(['disabled_for_new_assignments' => true]);
                // Log: health check auto-disabled
            }
            return ['status' => 'unhealthy', 'reason' => 'no_success_30_min'];
        }

        return ['status' => 'healthy', 'last_success' => $lastSuccess];
    }

    public function computeStuckAge(Sim $sim): array {
        $lastSuccess = $sim->last_success_at;
        $hoursSince = now()->diffInHours($lastSuccess);

        return [
            'stuck_6h' => $hoursSince >= 6,
            'stuck_24h' => $hoursSince >= 24,
            'stuck_3d' => $hoursSince >= 72,
        ];
    }
}
```

**GatewayOutboundController.php (excerpt):**
```php
public function store(Request $request) {
    // Resolve tenant from auth context
    $company = Auth::user()->company; // or context

    // Validate and select SIM (existing logic)
    $sim = $this->selectSim($company, $request->customer_phone);

    // NEW: Check operator status
    if ($sim->operator_status === 'paused') {
        $message = OutboundMessage::create([...]);
        // Do NOT enqueue (no queue push)
        return response()->json([
            'status' => 'accepted',
            'message' => 'SIM is paused; message saved but not queued',
            'queued' => false,
        ], 202);
    }

    if ($sim->operator_status === 'blocked') {
        // Reject immediately
        return response()->json([
            'status' => 'unavailable',
            'message' => 'SIM is blocked',
        ], 503);
    }

    // If active: save and enqueue (existing logic)
    $message = OutboundMessage::create([...]);
    $this->enqueueMessage($message); // existing enqueue logic
    return response()->json(['status' => 'queued', 'queued' => true], 200);
}
```

**OutboundRetryService.php (excerpt):**
```php
public function scheduleRetry(OutboundMessage $message): void {
    // Fixed 5-minute interval
    $message->update([
        'scheduled_at' => now()->addMinutes(5),
        'retry_count' => $message->retry_count + 1,
        'status' => 'pending', // Wait for retry time, don't enqueue yet
    ]);
    // No max attempts, no auto-stop
}
```

### 4.5 Phase 0 Validation Checklist

Before Phase 1, verify:

- [ ] Migration runs without error
- [ ] New Sim fields exist and are correctly typed
- [ ] Sim model casts/accessors work
- [ ] mark_successful() updates last_success_at correctly
- [ ] SimHealthService computes 30-min threshold correctly
- [ ] CheckSimHealthCommand runs every 5 minutes without errors
- [ ] SetSimOperatorStatusCommand updates database
- [ ] GatewayOutboundController respects operator_status:
  - [ ] paused → 202, save DB, NO enqueue
  - [ ] blocked → 503, NO save
  - [ ] active → 200, save + enqueue (existing behavior)
- [ ] OutboundRetryService uses fixed 5-minute retry
- [ ] No max-attempt cap on retries
- [ ] SimSelectionService filters by accept_new_assignments && !disabled_for_new_assignments
- [ ] Existing active SIMs still work (accept_new_assignments defaults true)
- [ ] Manual commands work (enable/disable/set-status)
- [ ] Health check does NOT move sticky customers (only disables new assignments)
- [ ] Health check does NOT stop retries
- [ ] Multi-tenant isolation preserved

### 4.6 Phase 0 Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Migration blocks on large SIMs table | Run during low-traffic window |
| Operator_status confusion with SimStateService mode | Document: operator_status = manual control, mode = delivery rate state (separate concerns) |
| Health check every 5 min creates overhead | Optimize with proper indexes; monitor CPU |
| 30-minute threshold too aggressive | Can be adjusted; dashboard will show stuck messages for manual intervention |

### 4.7 Phase 0 Rollback

```bash
php artisan migrate:rollback --path=database/migrations/2026_03_29_120000_...
git revert <Phase-0-commit>
php artisan queue:restart
```

**Impact:** Operator controls disabled, retry reverts to old exponential backoff, paused SIM semantics removed. Core send/queue logic unaffected.

---

## 5. PHASE 1: MANUAL MIGRATION BASELINE

**Goal:** Implement operator-triggered manual migration (bulk + single customer) with DB-first semantics. Establish migration pattern before introducing Redis.

**Status:** Complete (Locked)
**Duration:** 3-4 days
**Risk Level:** Medium (complex data movement, critical operator tool)
**What's Locked:** All rules, no Redis yet
**What's New:** Migration services + commands using DB-first pattern (no rebuild lock needed yet)

### 5.0 Lock Result

- manual migration baseline is complete
- failover/reassign hardening is complete
- tests are green
- Phase 2 had not started at the Phase 1 lock point

### 5.1 Phase 1 Scope

**Architecture Preserved:**
- Still DB-claim approach (Redis queues don't exist yet)
- No rebuild lock logic (Phase 2 will introduce this)
- No paused→active auto-requeue (requires Phase 2)
- MySQL remains source of truth
- Phase 0 operator controls still work

**What Phase 1 Implements:**

1. **Services Created**
   - **SimMigrationService** — bulk and single customer migration with DB-first semantics
   - **StaleLockRecoveryService** — recover stuck 'sending' messages to 'pending'

2. **Commands Created**
   - **MigrateSimCustomersCommand** — bulk migrate all customers from SIM A → SIM B
   - **MigrateSingleCustomerCommand** — single customer migration
   - **RecoverOutboundCommand** — manual recovery of stuck sending messages

3. **Services Updated**
   - **CustomerSimAssignmentService** — add methods: updateAssignment(), getAssignmentsForSim()

### 5.2 Phase 1 Files

**New:**
- `app/Services/SimMigrationService.php`
- `app/Services/StaleLockRecoveryService.php`
- `app/Console/Commands/MigrateSimCustomersCommand.php`
- `app/Console/Commands/MigrateSingleCustomerCommand.php`
- `app/Console/Commands/RecoverOutboundCommand.php`

**Modified:**
- `app/Services/CustomerSimAssignmentService.php` — add helper methods

### 5.3 Phase 1 Key Services

**SimMigrationService.php:**
```php
class SimMigrationService {
    public function migrateCustomer(Company $company, Sim $fromSim, Sim $toSim, string $customerPhone): array {
        // Validate
        $this->validateMigration($company, $fromSim, $toSim);

        // DB transaction: atomic migration
        return DB::transaction(function () use ($company, $fromSim, $toSim, $customerPhone) {
            // 1. Update customer assignment
            CustomerSimAssignment::where([
                'company_id' => $company->id,
                'customer_phone' => $customerPhone,
            ])->update(['sim_id' => $toSim->id]);

            // 2. Move pending messages
            OutboundMessage::where([
                'company_id' => $company->id,
                'sim_id' => $fromSim->id,
                'customer_phone' => $customerPhone,
                'status' => 'pending',
            ])->update(['sim_id' => $toSim->id]);

            // 3. Enqueue migrated messages (existing enqueue logic)
            $movedMessages = OutboundMessage::where([
                'sim_id' => $toSim->id,
                'customer_phone' => $customerPhone,
                'status' => 'pending',
            ])->get();

            foreach ($movedMessages as $msg) {
                $this->enqueueSender->enqueue($msg);
                $msg->update(['status' => 'queued']);
            }

            // Log migration
            Log::info('Customer migrated', [
                'company' => $company->id,
                'from_sim' => $fromSim->id,
                'to_sim' => $toSim->id,
                'customer' => $customerPhone,
                'messages' => $movedMessages->count(),
            ]);

            return [
                'customer_phone' => $customerPhone,
                'messages_moved' => $movedMessages->count(),
                'from_sim' => $fromSim->id,
                'to_sim' => $toSim->id,
            ];
        });
    }

    public function bulkMigrateCustomers(Company $company, Sim $fromSim, Sim $toSim): array {
        // Validate
        $this->validateMigration($company, $fromSim, $toSim);

        return DB::transaction(function () use ($company, $fromSim, $toSim) {
            // 1. Get all customers on fromSim
            $assignments = CustomerSimAssignment::where([
                'company_id' => $company->id,
                'sim_id' => $fromSim->id,
                'status' => 'active',
            ])->get();

            // 2. Migrate all assignments
            CustomerSimAssignment::where([
                'company_id' => $company->id,
                'sim_id' => $fromSim->id,
                'status' => 'active',
            ])->update(['sim_id' => $toSim->id]);

            // 3. Migrate all pending messages
            $pendingMessages = OutboundMessage::where([
                'company_id' => $company->id,
                'sim_id' => $fromSim->id,
                'status' => 'pending',
            ])->update(['sim_id' => $toSim->id]);

            // 4. Enqueue migrated messages
            $queuedMessages = OutboundMessage::where([
                'company_id' => $company->id,
                'sim_id' => $toSim->id,
                'status' => 'pending',
            ])->get();

            foreach ($queuedMessages as $msg) {
                $this->enqueueSender->enqueue($msg);
                $msg->update(['status' => 'queued']);
            }

            Log::info('Bulk migration completed', [
                'company' => $company->id,
                'from_sim' => $fromSim->id,
                'to_sim' => $toSim->id,
                'customers' => $assignments->count(),
                'messages' => $queuedMessages->count(),
            ]);

            return [
                'total_customers' => $assignments->count(),
                'total_messages_moved' => $queuedMessages->count(),
                'from_sim' => $fromSim->id,
                'to_sim' => $toSim->id,
            ];
        });
    }

    private function validateMigration(Company $company, Sim $fromSim, Sim $toSim): void {
        if ($fromSim->company_id !== $company->id || $toSim->company_id !== $company->id) {
            throw new \Exception('SIMs must belong to company');
        }
        if ($toSim->operator_status === 'blocked') {
            throw new \Exception('Cannot migrate to blocked SIM');
        }
        if ($fromSim->id === $toSim->id) {
            throw new \Exception('From and to SIM must be different');
        }
    }
}
```

**StaleLockRecoveryService.php:**
```php
class StaleLockRecoveryService {
    public function recoverStaleMessages(Sim $sim): int {
        // Find messages stuck in 'sending' > 10 minutes old
        $staleMessages = OutboundMessage::where([
            'sim_id' => $sim->id,
            'status' => 'sending',
        ])->where('updated_at', '<', now()->subMinutes(10))->get();

        $count = 0;
        foreach ($staleMessages as $msg) {
            // Recover to pending (will be picked up by retry scheduler)
            $msg->update(['status' => 'pending']);
            $count++;
        }

        Log::info('Recovered stale messages', [
            'sim' => $sim->id,
            'count' => $count,
        ]);

        return $count;
    }
}
```

### 5.4 Phase 1 Validation Checklist

Before Phase 2, verify:

- [ ] SimMigrationService.migrateCustomer() works:
  - [ ] Validates from/to SIMs
  - [ ] Rejects migration to blocked SIM
  - [ ] Updates customer_sim_assignments correctly
  - [ ] Moves pending messages to toSim
  - [ ] Does NOT move non-pending messages
  - [ ] Messages enqueued after migration
  - [ ] No duplicates, no loss
  - [ ] Returns correct summary

- [ ] SimMigrationService.bulkMigrateCustomers() works:
  - [ ] Migrates all customers
  - [ ] All assignments updated
  - [ ] All pending messages moved
  - [ ] Non-pending NOT moved
  - [ ] Atomicity: all or nothing

- [ ] MigrateSimCustomersCommand and MigrateSingleCustomerCommand work

- [ ] StaleLockRecoveryService.recoverStaleMessages() works:
  - [ ] Finds sending messages >10 min old
  - [ ] Updates to pending
  - [ ] Does NOT affect recent sending messages

- [ ] Multi-tenant isolation preserved
- [ ] Queue integrity after migration
- [ ] Logs show clear audit trail

### 5.5 Phase 1 Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Migration under heavy load impacts performance | Run during low-traffic window |
| Partial migration if transaction fails | DB transaction ensures atomicity; full rollback on error |
| Sticky assignment not moved | Ensure CustomerSimAssignmentService updates assignments |
| Messages lost if DELETE instead of UPDATE | Code review; test with sample data |
| Operator migrates to blocked SIM | validateMigration() rejects blocked destination |

### 5.6 Phase 1 Rollback

```bash
git revert <Phase-1-commit>
# If partial migration, manually correct:
UPDATE customer_sim_assignments SET sim_id = {original_sim} WHERE ...
UPDATE outbound_messages SET sim_id = {original_sim} WHERE ...
```

---

## 6. PHASE 2: REDIS PER-SIM QUEUE ARCHITECTURE + REBUILD LOCK + AUTO-REQUEUE

**Goal:** Replace DB-claim queueing with Redis per-SIM queues. Introduce rebuild lock and worker-visible coordination. Implement paused→active auto-requeue and full blocked worker semantics.

**Status:** Complete (Locked)
**Duration:** 5-7 days
**Risk Level:** High (architectural change, worker rewrite, rebuild lock introduction)
**What's Locked:** All rules
**What's New:** Redis queue transport, rebuild lock pattern, paused auto-requeue, blocked worker semantics

### 6.0 Current Checkpoint (In Progress)

- `RedisQueueService` implemented
- `QueueRebuildService` implemented with worker-visible rebuild lock
- commands implemented:
  - `NormalizePausedQueuedToPendingCommand`
  - `RebuildSimQueueCommand`
  - `InitializeQueueMigrationCommand`
  - `RetrySchedulerCommand`
- `GatewayOutboundController` updated to Phase 2 intake semantics
- `SimQueueWorkerService` rewritten to Redis pop + rebuild-lock check + DB-truth recheck
- paused→active auto-requeue wiring implemented (`SimOperatorStatusChanged` + `PausedSimResumeListener`)
- `Kernel.php` retry scheduler wiring implemented (every five minutes)
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
- supporting tests/helper updates added
- errorLayer-aware retry policy implemented:
  - `OutboundRetryService::handlePermanentFailure()` — terminal path for network-layer carrier rejections
  - `SimQueueWorkerService` routes `errorLayer='network'` → permanent failure; all other layers → retry
  - `PythonApiSmsSender` ConnectionException corrected to `errorLayer='transport'`
  - tests added/updated: `OutboundRetryServiceTest`, `SimQueueWorkerServiceRedisTest`, `PythonApiSmsSenderTest`
- full suite green at lock: 120 passed
- Phase 2 is COMPLETE and LOCKED; Phase 3 absorbed into Phase 2; Phase 4 COMPLETE and LOCKED (see Phase 4 lock result below)
- Task 012A: DONE — Python API authentication complete (`X-Gateway-Token`, both sides, live-proven); per-modem send lock explicitly deferred as Python-owned hardware-safe execution behavior
- live smoke test proven end-to-end (physical SMS received; success/retry/terminal/stale-lock paths all confirmed)
- `sims.last_success_at` bug fixed: `SimStateService::markSendSuccess()` now sets both `last_sent_at` and `last_success_at`; `SimStateServiceTest` added
- bootstrap seeders added (`BootstrapCompanySeeder`, `BootstrapModemSeeder`, `BootstrapSimSeeder`, `BootstrapApiClientSeeder`); `DatabaseSeeder` updated
- `SMS_PYTHON_API_SEND_PATH` config key added as minor dev/testing affordance (default: `/send`)
- `SMS_PYTHON_API_TOKEN` config key added; `PythonApiSmsSender` sends `X-Gateway-Token` when configured
- `SimHealthService`/`CheckSimHealthCommand` validated against real-populated `last_success_at`; 3 tests added to `SimHealthServiceTest`; item closed

### 6.1 Phase 2 Scope

**Architecture Changes:**
- Queue transport moves from DB-claim to Redis per-SIM queues
- Worker changes from DB polling to Redis LPOP with priority order
- Rebuild lock introduced: `sms:lock:rebuild:sim:{sim_id}`
- Message status transitions: pending → queued (in Redis) → sending → sent/failed → pending (retry) → queued
- Paused→active auto-requeue implemented (requires rebuild lock + Redis)
- Blocked worker semantics fully implemented (allow old queued work to drain)

**Architecture Preserved:**
- MySQL remains source of truth
- SIM-centric model
- All Phase 0 operator controls
- All Phase 1 manual migration
- SmsSenderInterface unchanged

**What Phase 2 Implements:**

1. **Queue Services Created**
   - **RedisQueueService** — enqueue/dequeue from Redis per-SIM queues, manage queue depth
   - **QueueRebuildService** — rebuild queues from DB with rebuild lock pattern

2. **Rebuild & Auto-Requeue Created**
   - **PausedSimResumeListener** — triggered on paused→active, calls rebuild
   - **RetrySchedulerCommand** — every 5 minutes, enqueue scheduled messages back to Redis

3. **Worker Rewrite**
   - **SimQueueWorkerService** — complete rewrite to use Redis LPOP, check rebuild lock, respect priority

4. **Commands Created**
   - **RebuildSimQueueCommand** — manual queue rebuild trigger
   - **InitializeQueueMigrationCommand** — one-time: migrate pending DB messages to Redis
   - **RetrySchedulerCommand** — scheduled every 5 minutes

5. **Services Updated**
   - **GatewayOutboundController** — enqueue to Redis immediately on active SIM
   - **SimSelectionService** — keep existing DB-backed queue-load ordering and availability filters

### 6.2 Phase 2 Database Schema

**Minimal schema change landed in current Phase 2 slice:** `sims.imsi` for Laravel-side Python execution integration.
No other Phase 2 schema changes are required; outbound_messages.status already supports pending/queued/sending/sent/failed transitions.

### 6.3 Phase 2 Key Components

**RedisQueueService.php (new):**
```php
class RedisQueueService {
    protected $redis;

    public function enqueueMessage(Sim $sim, OutboundMessage $message): void {
        // Determine queue based on message_type
        $queueKey = match($message->message_type) {
            'CHAT', 'AUTO_REPLY' => "sms:queue:sim:{$sim->id}:chat",
            'FOLLOW_UP' => "sms:queue:sim:{$sim->id}:followup",
            'BLAST' => "sms:queue:sim:{$sim->id}:blasting",
        };

        // Push message ID to Redis queue
        $this->redis->rpush($queueKey, $message->id);
    }

    public function dequeueMessage(Sim $sim): ?int {
        // Check rebuild lock
        if ($this->isRebuildLocked($sim)) {
            return null;
        }

        // Check queues in priority order: chat → followup → blasting
        foreach (['chat', 'followup', 'blasting'] as $tier) {
            $queueKey = "sms:queue:sim:{$sim->id}:{$tier}";
            $messageId = $this->redis->lpop($queueKey);
            if ($messageId) {
                return (int)$messageId;
            }
        }

        return null;
    }

    public function getQueueDepth(Sim $sim, ?string $tier = null): int {
        if ($tier) {
            $queueKey = "sms:queue:sim:{$sim->id}:{$tier}";
            return $this->redis->llen($queueKey);
        }

        // Total depth across all 3 queues
        $total = 0;
        foreach (['chat', 'followup', 'blasting'] as $t) {
            $queueKey = "sms:queue:sim:{$sim->id}:{$t}";
            $total += $this->redis->llen($queueKey);
        }
        return $total;
    }

    public function clearQueuesForSim(Sim $sim): void {
        foreach (['chat', 'followup', 'blasting'] as $tier) {
            $queueKey = "sms:queue:sim:{$sim->id}:{$tier}";
            $this->redis->del($queueKey);
        }
    }

    private function isRebuildLocked(Sim $sim): bool {
        return (bool)$this->redis->exists("sms:lock:rebuild:sim:{$sim->id}");
    }
}
```

**QueueRebuildService.php (new):**
```php
class QueueRebuildService {
    protected $redis;
    protected $redisQueue;

    public function rebuildQueuesForSim(Sim $sim, int $lockTtl = 30): void {
        // Set rebuild lock with TTL
        $lockKey = "sms:lock:rebuild:sim:{$sim->id}";
        $this->redis->setex($lockKey, $lockTtl, 'rebuilding');

        try {
            // Clear all 3 queues for this SIM
            $this->redisQueue->clearQueuesForSim($sim);

            // Load pending messages from DB (source of truth)
            $pendingMessages = OutboundMessage::where([
                'sim_id' => $sim->id,
                'status' => 'pending',
            ])->get();

            // Enqueue each message to appropriate queue
            foreach ($pendingMessages as $message) {
                $this->redisQueue->enqueueMessage($sim, $message);
            }

            // Update message status to queued
            OutboundMessage::where([
                'sim_id' => $sim->id,
                'status' => 'pending',
            ])->update(['status' => 'queued', 'queued_at' => now()]);

            Log::info('Queue rebuild completed', [
                'sim' => $sim->id,
                'messages' => $pendingMessages->count(),
            ]);
        } finally {
            // Always clear lock
            $this->redis->del($lockKey);
        }
    }

    public function waitForRebuildLock(Sim $sim, int $maxWaitSeconds = 6): bool {
        $lockKey = "sms:lock:rebuild:sim:{$sim->id}";
        $waited = 0;
        $checkInterval = 0.1; // Check every 100ms

        while ($waited < $maxWaitSeconds) {
            if (!$this->redis->exists($lockKey)) {
                return true; // Lock cleared
            }
            usleep($checkInterval * 1000000);
            $waited += $checkInterval;
        }

        return false; // Timeout, lock still exists
    }
}
```

**SimQueueWorkerService.php (rewrite):**
```php
class SimQueueWorkerService {
    protected $redisQueue;
    protected $rebuildService;
    protected $smsSender;
    protected $retryService;
    protected $redis;

    public function processSim(Sim $sim): void {
        $running = true;

        while ($running) {
            // Check rebuild lock
            if ($this->redis->exists("sms:lock:rebuild:sim:{$sim->id}")) {
                // Rebuild in progress, wait or skip
                $this->rebuildService->waitForRebuildLock($sim, 6);
                sleep(1);
                continue;
            }

            // Check operator status
            if ($sim->operator_status === 'paused') {
                sleep(5);
                continue; // Skip this SIM while paused
            }

            // Dequeue message (priority order: chat → followup → blasting)
            $messageId = $this->redisQueue->dequeueMessage($sim);
            if (!$messageId) {
                sleep(5);
                continue; // No messages, wait
            }

            // Load message details from DB (Redis only had the ID)
            $message = OutboundMessage::find($messageId);
            if (!$message) {
                Log::warning('Message not found after dequeue', ['id' => $messageId]);
                continue;
            }

            // Mark as sending
            $message->update(['status' => 'sending']);

            // Send via abstraction
            $result = $this->smsSender->send($message);

            if ($result->success) {
                // Success
                $message->update(['status' => 'sent', 'sent_at' => now()]);
                $sim->update(['last_success_at' => now()]);
                // Log success
            } else {
                // Failure
                $message->update(['status' => 'failed']);
                // Schedule retry
                $this->retryService->scheduleRetry($message);
                // Log failure
            }
        }
    }
}
```

**PausedSimResumeListener.php (new):**
```php
class PausedSimResumeListener {
    protected $rebuildService;

    public function handle(SimOperatorStatusChanged $event): void {
        if ($event->oldStatus === 'paused' && $event->newStatus === 'active') {
            // SIM is being resumed, auto-requeue pending messages
            Sim::find($event->simId)->tap(function ($sim) {
                $this->rebuildService->rebuildQueuesForSim($sim);
                Log::info('Paused SIM resumed, queues rebuilt', ['sim' => $sim->id]);
            });
        }
    }
}
```

**RetrySchedulerCommand.php (new):**
```php
class RetrySchedulerCommand extends Command {
    public function handle() {
        // Every 5 minutes: find scheduled messages and enqueue them
        while (true) {
            $scheduledMessages = OutboundMessage::where('scheduled_at', '<=', now())
                ->where('status', '=', 'pending')
                ->get();

            foreach ($scheduledMessages as $message) {
                $sim = $message->sim;

                // Skip if SIM is paused
                if ($sim->operator_status === 'paused') {
                    continue;
                }

                // Enqueue to Redis
                app(RedisQueueService::class)->enqueueMessage($sim, $message);
                $message->update(['status' => 'queued', 'queued_at' => now()]);

                Log::info('Retry message enqueued', [
                    'message' => $message->id,
                    'retry_count' => $message->retry_count,
                ]);
            }

            sleep(300); // Every 5 minutes
        }
    }
}
```

**InitializeQueueMigrationCommand.php (new):**
```php
class InitializeQueueMigrationCommand extends Command {
    public function handle() {
        // One-time: migrate all pending messages from DB to Redis
        $pendingMessages = OutboundMessage::where('status', '=', 'pending')->get();

        $queueService = app(RedisQueueService::class);

        foreach ($pendingMessages as $message) {
            $sim = $message->sim;
            $queueService->enqueueMessage($sim, $message);
            $message->update(['status' => 'queued', 'queued_at' => now()]);
        }

        $this->info("Migrated {$pendingMessages->count()} messages to Redis");
    }
}
```

### 6.4 Phase 2 API Changes

**GatewayOutboundController.php (update):**
```php
public function store(Request $request) {
    // ... existing tenant/SIM selection logic ...

    // Check operator status (existing Phase 0 logic)
    if ($sim->operator_status === 'paused') {
        $message = OutboundMessage::create([...]);
        return response()->json([...], 202); // Accepted warning, not queued
    }

    if ($sim->operator_status === 'blocked') {
        return response()->json([...], 503); // Rejected
    }

    // If active: NEW phase 2 logic
    $message = OutboundMessage::create([...]);

    // NEW: Immediately enqueue to Redis
    app(RedisQueueService::class)->enqueueMessage($sim, $message);
    $message->update(['status' => 'queued', 'queued_at' => now()]);

    return response()->json(['status' => 'queued', 'queued' => true], 200);
}
```

### 6.5 Phase 2 Blocked Worker Semantics

**Key Point:** Phase 2 is where blocked worker behavior is fully implemented.

When `operator_status === 'blocked'`:
- Worker does NOT skip the SIM
- Worker continues draining already-queued messages
- Worker does NOT accept new intake (Phase 0 already handles this at intake level)
- This allows draining of backlog while preventing new messages

**Implementation:** Worker checks `operator_status === 'blocked'` and allows processing; only `paused` causes worker skip.

### 6.6 Phase 2 Files

**New:**
- `app/Services/RedisQueueService.php`
- `app/Services/QueueRebuildService.php`
- `app/Events/SimOperatorStatusChanged.php` (if using events)
- `app/Listeners/PausedSimResumeListener.php`
- `app/Console/Commands/RebuildSimQueueCommand.php`
- `app/Console/Commands/InitializeQueueMigrationCommand.php`
- `app/Console/Commands/RetrySchedulerCommand.php`

**Modified:**
- `app/Services/SimQueueWorkerService.php` (complete rewrite)
- `app/Http/Controllers/GatewayOutboundController.php` (add Redis enqueue)
- `app/Services/SimOperatorStatusService.php` (fire event on status change)
- `app/Console/Kernel.php` (register RetrySchedulerCommand)

### 6.7 Phase 2 Validation Checklist

Before Phase 3, verify:

- [ ] Redis per-SIM queues created and work:
  - [ ] `sms:queue:sim:{sim_id}:chat` exists
  - [ ] `sms:queue:sim:{sim_id}:followup` exists
  - [ ] `sms:queue:sim:{sim_id}:blasting` exists

- [ ] RedisQueueService works:
  - [ ] enqueue() pushes to correct queue
  - [ ] popNext() respects priority order
  - [ ] depth() returns correct counts
  - [ ] Rebuild lock blocks dequeue

- [ ] Message intake enqueues to Redis:
  - [ ] active SIM: save DB + enqueue Redis + status queued + 200
  - [ ] paused SIM: save DB + NO enqueue + status pending + 202
  - [ ] blocked SIM: NO save + NO enqueue + 503

- [ ] Worker respects rebuild lock:
  - [ ] Checks lock before LPOP
  - [ ] Waits/skips if locked
  - [ ] No operations during rebuild

- [ ] Worker respects operator_status:
  - [ ] active: process messages
  - [ ] paused: skip SIM, don't process
  - [ ] blocked: allow messages to drain (do NOT skip)

- [ ] Worker processes in priority order:
  - [ ] chat first, followup second, blasting third
  - [ ] No starvation

- [ ] Paused→active auto-requeue works:
  - [ ] Pause SIM
  - [ ] Messages saved but not queued
  - [ ] Resume SIM
  - [ ] Listener triggered
  - [ ] Rebuild called
  - [ ] Messages queued to Redis
  - [ ] No duplicates

- [ ] Blocked worker behavior:
  - [ ] Blocked SIM new intake rejected (Phase 0 intake check)
  - [ ] Blocked SIM old queued messages continue draining (worker processes)

- [ ] Retry scheduler works:
  - [ ] Every 5 minutes, scheduled messages enqueued
  - [ ] Status pending → queued
  - [ ] Worker picks up retried messages
  - [ ] No duplicates

- [ ] InitializeQueueMigrationCommand works:
  - [ ] Pending DB truth is seeded to Redis queues
  - [ ] No forced DB status rewrite during initialization
  - [ ] No loss, no duplicates

- [ ] Multi-tenant isolation preserved
- [ ] Health tracking still works (last_success_at updated)
- [ ] Manual migration (Phase 1) still works with Redis queues

### 6.8 Phase 2 Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Queue data loss on Redis crash | Messages in DB (source of truth); rebuild from DB on restart |
| Duplicate sends if Redis AND retry enqueues | Use message status to prevent; update status after dequeue |
| Queue ordering not priority-based | Explicit priority checking in dequeueMessage() |
| Worker deadlock on rebuild lock | Max wait 6s; lock TTL 30s; always clear in finally |
| Paused SIM auto-requeue loses messages | Rebuild uses DB pending (source of truth) |
| Blocked SIM drains messages too slowly | Operator can manually migrate to active SIM if needed |

### 6.9 Phase 2 Rollback

```bash
git revert <Phase-2-commit>
# Revert worker to DB-claim logic
# Clear Redis queues
redis-cli DEL "sms:queue:sim:*"
# Update message statuses: queued → pending
UPDATE outbound_messages SET status = 'pending' WHERE status = 'queued';
php artisan queue:restart
```

---

## 7. PHASE 4: OPERATOR API + DASHBOARD SURFACES

**Goal:** Expose minimum-safe operator visibility and control surfaces via tenant-authenticated API, then deliver core operator dashboard pages using those existing APIs.

**Status:** COMPLETE (Locked) — backend/API + core dashboard/operator pages implemented
**Lock Validation:** full suite green (205 passed)

### 7.0 Phase 4 Lock Result (2026-04-08)

#### Read-Only Visibility (Complete)

| Endpoint | Controller | Notes |
|----------|-----------|-------|
| `GET /api/sims` | `SimController` | health, stuck-age, queue depth per SIM |
| `GET /api/messages/status` | `MessageStatusController` | lookup by `client_message_id`; optional `sim_id` filter |
| `GET /api/assignments` | `AssignmentController` | customer-SIM list; optional filters; nested SIM object |

#### Admin/Control (Complete)

| Endpoint | Controller | Delegates To |
|----------|-----------|-------------|
| `POST /api/admin/sim/{id}/status` | `SimAdminController` | `SimOperatorStatusService` |
| `POST /api/admin/sim/{id}/enable-assignments` | `SimAdminController` | direct `$sim->update()` |
| `POST /api/admin/sim/{id}/disable-assignments` | `SimAdminController` | direct `$sim->update()` |
| `POST /api/admin/migrate-single-customer` | `MigrationController` | `SimMigrationService::migrateSingleCustomer()` |
| `POST /api/admin/migrate-bulk` | `MigrationController` | `SimMigrationService::migrateBulk()` |
| `POST /api/admin/rebalance` | `MigrationController` | `SimMigrationService::rebalanceSafeAssignments()` |
| `POST /api/admin/sim/{id}/rebuild-queue` | `SimAdminController` | `QueueRebuildService::rebuildSimQueue()` |
| `POST /api/messages/bulk` | `GatewayOutboundController` | same intake rules as `/api/messages/send`, processed per item |

#### Dashboard/UI (Complete in Current Checkpoint)

| Route | Controller | Notes |
|----------|-----------|-------|
| `/dashboard` | `DashboardHomePageController` | operator navigation entry point |
| `/dashboard/sims` | `SimFleetStatusPageController` | SIM fleet read-only status |
| `/dashboard/assignments` | `AssignmentDashboardPageController` | assignment visibility with filters |
| `/dashboard/sims/{id}` | `SimDetailControlPageController` | SIM detail + existing control actions |
| `/dashboard/migration` | `MigrationDashboardPageController` | migration workflow UI using existing APIs |
| `/dashboard/messages/status` | `MessageStatusDashboardPageController` | message status lookup by client ID |

UX polish included:
- shared local credential persistence across dashboard pages
- consistent cross-page navigation
- improved action-status messaging after refresh
- SIM-detail deep links from list pages

#### Intentional Exclusions
- `StaleLockRecoveryService` not exposed as tenant API: system-scoped (no `company_id`), wrong blast radius
- no new backend endpoints added for dashboard pages in this checkpoint
- no schema changes in this checkpoint

#### Architecture Preserved
- All Phase 0/1/2 behaviors unchanged
- No backend schema changes
- Dashboard uses existing backend services/endpoints (no backend redesign)
- Tenant isolation enforced on every endpoint via `TenantContext::companyId()`

#### Deferred Beyond Phase 4 (Later Backlog)
- advanced monitoring analytics
- deeper error-tracking stack
- non-essential future UI polish iterations
- scale-oriented operator tooling

---

### 7.1 PHASE 5A — DASHBOARD / AUTH / OPERATOR SYSTEM (Current)

**Status:** COMPLETE (Locked)
**Validation:** checkpoint full suite green (267 passed)

Implemented in repo:
- dashboard login/logout and session-protected dashboard routes
- server-side `/dashboard/api/*` bridge (browser no longer sends raw gateway API secrets)
- tenant binding from `users.company_id`
- dashboard RBAC (`owner`, `admin`, `support`) with write/owner middleware guards
- tenant-local operator management:
  - list + filter/sort/search
  - owner-only operator creation
  - owner-only role update
  - owner-only temporary-password reset/regeneration
  - owner-only activation/deactivation
- forced first-login temporary-password change + self-service password change
- read-only account page
- tenant-local operator audit log (record + read API/page + filters/search)
- shared dashboard layout + tenant/operator identity banner + nav/page-title polish

Current boundary:
- this track is dashboard/session-human-operator scope only
- machine `/api/*` API-client authentication remains unchanged
- lock/checkpoint closure finalized as docs/admin boundary (TASK 028 complete)
- no unresolved core engineering scope remains in this track

### 7.2 PHASE 5B — SCALE / INFRASTRUCTURE / THROUGHPUT (Future)

**Status:** NOT STARTED

Planned scope:
- worker scale-out
- Redis throughput and queue optimization at scale
- multi-node Laravel worker deployment
- Python execution node scale-out
- load/performance validation under higher volume targets

---

### 7.3 PHASE 6 — PYTHON RUNTIME INTEGRATION & LIVE MODEM FLEET (Current Runtime Track)

**Status:** IN PROGRESS (implemented through 6.6.b runtime/operator maturity; Phase 6 remains open)
**Validation:** current full suite green (286 passed)

Phase 6.1 foundation implemented in current repo:
- Python runtime service remains external to this Laravel repo
- Laravel now has a dedicated runtime client/service for Python API calls
- current contract endpoints used by Laravel:
  - `/health`
  - `/modems/discover`
- read-only Laravel runtime inspection surface added:
  - dashboard page
  - dashboard API endpoint
- modem discovery visibility is tenant-filtered in Laravel by matching tenant SIM IMSI values

Phase 6.2 send-execution bridge implemented in current repo:
- structured Laravel→Python send execution bridge added on top of existing `/send` runtime contract
- send path now flows through runtime client normalization
- normalized runtime failure classes include:
  - `runtime_unreachable`
  - `runtime_timeout`
  - `invalid_response`
- runtime diagnostics are persisted to `outbound_messages.metadata`
- controlled dashboard send-test surface added for manual runtime verification

Phase 6 runtime validation milestone achieved in live environment:
- real end-to-end Laravel→Python→modem send validated
- destination device SMS delivery confirmed from dashboard-triggered runtime send-test
- runtime UI safety guardrails active:
  - full discovery rows rendered
  - send-test blocked on non-send-ready rows
  - explicit disabled reasons shown for operator safety
- runtime SIM identity mapping lesson now explicit:
  - Runtime SIM ID (Python discovery IMSI/fallback identifier) is not Tenant SIM DB ID (`sims.id`)
  - Laravel dashboard send-test and Laravel-side actions must use Tenant SIM DB ID
  - mixing IDs causes `sim_not_found`; mapping resolution + UI distinction fix this

Implemented Phase 6 maturity slices after 6.2:
- Phase 6.3: retry reliability + SIM runtime suppression/control behavior
- Phase 6.4.a–6.4.d: runtime fleet observability, row safety/action clarity, diagnostics drilldown, empty/failure/refresh clarity
- Phase 6.5.a–6.5.d: action safety/intent clarity, selected-target clarity, reset context UX, lightweight operator guidance
- Phase 6.6.a–6.6.b: mapping review visibility + reconciliation context explanation (read-only)

Scope boundary clarification for implemented 6.4/6.5/6.6 slices:
- these slices are operator/runtime UI maturity and reconciliation visibility
- these slices are not final runtime hardening completion
- these slices do not introduce mapping-write workflows
- Runtime SIM ID and Tenant SIM DB ID remain explicitly distinct
- send-test/Laravel actions continue using Tenant SIM DB ID (`sims.id`) only

Current Phase 6 boundary:
- implemented scope now covers Phase 6.1/6.2 foundation/bridge + real runtime send validation + 6.3/6.4/6.5/6.6 runtime/operator maturity
- Python runtime remains external to this Laravel repo
- this does not claim full production send hardening/complete fleet maturity
- this does not include broader scale-path hardening (Phase 5B/later work)

Phase 6 closure summary:
- TASK 031 (DONE): live-fleet reliability hardening follow-ups closed
  - completed under strict hardening checklist `031-H1`..`031-H8` with `AC-031-01`..`AC-031-07` closure gate
  - artifact-linked evidence ledger retained in `docs/TASKS.md`
- TASK 032 (DONE): deeper send-path maturity closure completed + Phase 5B handoff boundary defined
  - completed under strict send-path checklist `032-S1`..`032-S8` with `AC-032-01`..`AC-032-08` closure gate in `docs/TASKS.md`

Active Phase 5B follow-up:
- TASK 021 (IN PROGRESS): worker scale-out via strict checklist `021-W1`..`021-W8` and acceptance gate `AC-021-01`..`AC-021-08`
- TASK 022 (FUTURE): Python execution scale-out after TASK 021 readiness
- TASK 023 (FUTURE): throughput/load testing after TASK 021/022 completion

---

## 8. LEGACY ARCHIVED PLANNING (Superseded)

This section is retained for historical traceability only and is superseded by locked Phase 2/Phase 4 implementation reality.

## 8.1 PHASE 3: HEALTH MONITORING & OPERATOR DASHBOARD

**Goal:** Build operator visibility into SIM health, queue depth, stuck messages, and provide monitoring surfaces.

**Status:** Follows Phase 2
**Duration:** 3-4 days
**Risk Level:** Low (UI/API, no core logic changes)
**What's Locked:** All architecture
**What's New:** Health computation, API endpoints, dashboard UI

### 7.1 Phase 3 Scope

**What Phase 3 Implements:**

1. **Services Created**
   - **SimHealthCheckService** — comprehensive health computation, stuck-age calculation

2. **Controllers Created**
   - **SimHealthController** — API endpoints for SIM health, queue depth, messages by status

3. **Resources Created**
   - **SimHealthResource** — format health data for API responses

4. **UI Created** (if building operator dashboard)
   - Dashboard showing per-SIM health, queue depth, stuck warnings
   - Operator controls (pause/block/enable, migration)

### 7.2 Phase 3 Files

**New:**
- `app/Services/SimHealthCheckService.php`
- `app/Http/Controllers/Api/SimHealthController.php`
- `app/Http/Resources/SimHealthResource.php`
- `resources/views/dashboard/sim-health.blade.php` (if Blade-based)

**Modified:**
- `app/Models/Sim.php` (add health() method)
- `routes/api.php` (register endpoints)

### 7.3 Phase 3 Key Services

**SimHealthCheckService.php:**
```php
class SimHealthCheckService {
    public function computeHealthStatus(Sim $sim): array {
        $lastSuccess = $sim->last_success_at;
        $minutesSince = $lastSuccess ? now()->diffInMinutes($lastSuccess) : PHP_INT_MAX;

        // 30-minute threshold
        $isUnhealthy = $minutesSince >= 30;

        return [
            'status' => $isUnhealthy ? 'unhealthy' : 'healthy',
            'last_success_at' => $lastSuccess,
            'minutes_since_success' => $minutesSince,
            'warning' => $isUnhealthy ? 'No successful send in 30 minutes' : null,
        ];
    }

    public function getStuckAge(Sim $sim): array {
        $lastSuccess = $sim->last_success_at;
        $hoursSince = $lastSuccess ? now()->diffInHours($lastSuccess) : PHP_INT_MAX;

        return [
            'stuck_6h' => $hoursSince >= 6,
            'stuck_24h' => $hoursSince >= 24,
            'stuck_3d' => $hoursSince >= 72,
            'hours_since_success' => $hoursSince,
        ];
    }

    public function getQueueStats(Sim $sim): array {
        $redisQueue = app(RedisQueueService::class);

        return [
            'total_depth' => $redisQueue->getQueueDepth($sim),
            'chat' => $redisQueue->getQueueDepth($sim, 'chat'),
            'followup' => $redisQueue->getQueueDepth($sim, 'followup'),
            'blasting' => $redisQueue->getQueueDepth($sim, 'blasting'),
        ];
    }

    public function getMessageCounts(Sim $sim): array {
        return [
            'pending' => OutboundMessage::where(['sim_id' => $sim->id, 'status' => 'pending'])->count(),
            'queued' => OutboundMessage::where(['sim_id' => $sim->id, 'status' => 'queued'])->count(),
            'sending' => OutboundMessage::where(['sim_id' => $sim->id, 'status' => 'sending'])->count(),
            'sent' => OutboundMessage::where(['sim_id' => $sim->id, 'status' => 'sent'])->count(),
            'failed' => OutboundMessage::where(['sim_id' => $sim->id, 'status' => 'failed'])->count(),
        ];
    }

    public function getStickyCustomers(Sim $sim): Collection {
        return CustomerSimAssignment::where([
            'sim_id' => $sim->id,
            'status' => 'active',
        ])->get();
    }
}
```

### 7.4 Phase 3 API Endpoints

**SimHealthController.php:**
```php
class SimHealthController extends Controller {
    protected $healthService;

    public function __construct(SimHealthCheckService $healthService) {
        $this->healthService = $healthService;
    }

    // GET /api/sims/{sim_id}/health
    public function show(Sim $sim) {
        return new SimHealthResource([
            'health_status' => $this->healthService->computeHealthStatus($sim),
            'stuck_age' => $this->healthService->getStuckAge($sim),
            'queue_stats' => $this->healthService->getQueueStats($sim),
            'message_counts' => $this->healthService->getMessageCounts($sim),
            'sticky_customers' => $this->healthService->getStickyCustomers($sim),
            'operator_status' => $sim->operator_status,
            'accept_new_assignments' => $sim->accept_new_assignments,
            'disabled_for_new_assignments' => $sim->disabled_for_new_assignments,
        ]);
    }

    // GET /api/companies/{company_id}/sims/health
    public function index(Company $company) {
        $sims = $company->sims;
        return SimHealthResource::collection($sims->map(function ($sim) {
            return [
                'sim' => $sim,
                'health_status' => $this->healthService->computeHealthStatus($sim),
                'stuck_age' => $this->healthService->getStuckAge($sim),
                'queue_stats' => $this->healthService->getQueueStats($sim),
            ];
        }));
    }
}
```

### 7.5 Phase 3 Validation Checklist

Before production, verify:

- [ ] API endpoints return correct health data
- [ ] Health status computed correctly (30-min threshold)
- [ ] Stuck-age computed correctly (6h, 24h, 3d)
- [ ] Queue depth accurate from Redis
- [ ] Message counts accurate from DB
- [ ] Dashboard displays clearly
- [ ] Operator controls work via API (pause, block, enable)
- [ ] Multi-tenant isolation (only own company SIMs)
- [ ] Performance: health API <100ms per SIM
- [ ] No data leaks

### 7.6 Phase 3 Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| N+1 problem with large SIM lists | Eager loading, cache health status (5 min) |
| Dashboard slow with many SIMs | Paginate, AJAX for individual SIM |
| UI confusion (multiple status fields) | Clear labeling: Manual Control vs Auto-Disable |

---

## 8. IMPLEMENTATION ORDER (LOCKED)

**Execute phases in exact order:**

1. **PHASE 0** — Schema + Control Baseline
   - Database: add operator_status, accept_new_assignments, disabled_for_new_assignments, last_success_at
   - Services: SimHealthService, SimOperatorStatusService
   - Commands: CheckSimHealthCommand, SetOperatorStatusCommand, Enable/DisableCommands
   - Retry: Fixed 5-minute forever
   - API: Respect operator_status at intake (202 paused, 503 blocked)

2. **PHASE 1** — Manual Migration Baseline
   - Services: SimMigrationService, StaleLockRecoveryService
   - Commands: MigrateSimCustomersCommand, MigrateSingleCustomerCommand, RecoverOutboundCommand
   - Expected: Operators can manually migrate customers

3. **PHASE 2** — Redis Per-SIM Queue + Rebuild Lock + Auto-Requeue
   - Queue: RedisQueueService, 3 per-SIM queues
   - Rebuild: QueueRebuildService with worker-visible rebuild lock
   - Worker: Complete rewrite for Redis LPOP + priority order
   - Auto-Requeue: PausedSimResumeListener
   - Blocked Semantics: Worker allows message draining
   - Scheduler: RetrySchedulerCommand for retries back to Redis
   - Migration: InitializeQueueMigrationCommand (one-time)

4. **PHASE 3** — Health Monitoring & Dashboard
   - Services: SimHealthCheckService
   - API: SimHealthController with endpoints
   - UI: Dashboard for operator visibility

---

## 9. MASTER PRE-FLIGHT CHECKLIST

### Code Review

- [ ] All 9 locked docs read and understood
- [ ] No contradictions in revised IMPLEMENTATION_PLAN.md
- [ ] Phase boundaries clear (no overlap)
- [ ] Dependencies explicit and correct
- [ ] All architecture rules preserved
- [ ] No hidden redesigns

### Development Setup

- [ ] Laravel + Python codebases current
- [ ] Test database ready
- [ ] Redis running and accessible
- [ ] MySQL running and accessible
- [ ] CI/CD pipeline green
- [ ] All tests passing

### Phase 0 Specifics

- [ ] Database migration file agreed
- [ ] Service locations agreed
- [ ] Command naming agreed
- [ ] API controller location agreed
- [ ] Scheduler setup understood
- [ ] Retry service changes approved

### Team Readiness

- [ ] Team understands locked architecture
- [ ] Team understands phase sequencing
- [ ] Code review process in place
- [ ] Testing strategy clear
- [ ] Rollback strategy understood by ops

### Testing Strategy

- [ ] Unit tests for all new services
- [ ] Integration tests for API intake
- [ ] Integration tests for database migrations
- [ ] E2E tests for paused/blocked/active SIM behavior
- [ ] Multi-tenant tests for isolation
- [ ] Worker tests (Phase 2+)

### Documentation

- [ ] IMPLEMENTATION_PLAN.md is current
- [ ] Code comments explain rebuild lock pattern
- [ ] Code comments explain operator_status semantics
- [ ] Operator runbook updated
- [ ] Database changes documented

### Deployment Strategy

- [ ] Zero-downtime migration approach
- [ ] Worker restart coordination
- [ ] Rollback plan documented
- [ ] Monitoring/alerting in place

### Sign-Off

- [ ] Tech lead approved IMPLEMENTATION_PLAN.md
- [ ] Product owner reviewed scope
- [ ] DevOps reviewed deployment
- [ ] QA reviewed testing plan
- [ ] All stakeholders: ready to start Phase 0

---

## 10. SUMMARY

**Four clean phases, zero overlap, clear dependencies:**

- **Phase 0** (2-3 days): Database + operator controls + retry policy. No Redis, no rebuild logic, no auto-requeue. Intake respects paused/blocked.

- **Phase 1** (3-4 days): Manual migration. Still DB-based, no rebuild lock, no Redis queues. Operators can move customers.

- **Phase 2** (5-7 days): Redis queues + rebuild lock + auto-requeue + blocked worker semantics. Major architectural shift; worker rewritten.

- **Phase 3** (3-4 days): Health dashboard + operator visibility. No core logic changes.

**Total: ~18-24 days (4 weeks with integration + testing + review)**

**Next Step:** Begin Phase 0 with full confidence in architecture, clear phase boundaries, and explicit dependencies.

---

**Document Status:** ✓ Revised and Ready
**Last Update:** 2026-03-29 (Phase-boundary corrections applied)
**Next Review:** After Phase 0 completion
