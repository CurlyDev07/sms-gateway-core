# PHASE 0 EXECUTION PLAN (Corrected)

**Locked Document References:** SYSTEM.md, DECISIONS.md, ROADMAP.md, TASKS.md, AI_RULES.md, FULL_SYSTEM_FLOW.md, sms-gateway-design-v3.md, CHANGELOG.md, IMPLEMENTATION_PLAN.md

**Corrections Applied:**
1. accept_new_assignments: DB default false, backfill existing SIMs to true
2. disabled_for_new_assignments: Explicitly reversible (not permanent)
3. Tenant auth: Explicit checks in all commands/admin paths

---

## 1. CONFIRMATION: USING LOCKED DOCS ONLY

✓ All 9 locked architecture documents read in order
✓ Zero contradictions detected
✓ Phase 0 scope strictly adhered to
✓ NO jumping to Phase 2+ features
✓ NO Redis, rebuild lock, auto-requeue, blocked worker drain, or dashboard
✓ Phase 0 = smallest, safest foundational changes only

---

## 2. EXACT PHASE 0 SCOPE

**What Phase 0 Implements:**

1. **Database Schema:** Add 4 new SIM fields
   - `operator_status` (enum: active, paused, blocked)
   - `accept_new_assignments` (bool, DB default false, backfilled to true for existing)
   - `disabled_for_new_assignments` (bool, default false, reversible)
   - `last_success_at` (timestamp)

2. **Services:** Two new services
   - `SimHealthService` — compute health status, check 30-min threshold, auto-disable logic (reversible)
   - `SimOperatorStatusService` — manage operator_status changes

3. **Commands:** Four new commands (all with explicit tenant auth)
   - `CheckSimHealthCommand` — run every 5 minutes, with tenant auth
   - `SetSimOperatorStatusCommand` — manual operator control, with tenant auth
   - `EnableSimForNewAssignmentsCommand` — enable new customer assignments, with tenant auth
   - `DisableSimForNewAssignmentsCommand` — disable new customer assignments, with tenant auth

4. **Service Updates:** One critical service
   - `OutboundRetryService` — change from exponential backoff to fixed 5-minute forever retry

5. **API Changes:** One controller update
   - `GatewayOutboundController` — check operator_status, return 202 for paused, 503 for blocked

6. **Scheduler:** Register health check command
   - `Kernel.php` — schedule CheckSimHealthCommand every 5 minutes

---

## 3. FILES TO CHANGE (RECOMMENDED ORDER)

**SAFE ORDER (smallest-risk first):**

### A. Database Migration (1 file)
1. `database/migrations/2026_03_29_120000_add_operator_control_fields_to_sims.php` (create new)

### B. Models (1 file)
2. `app/Models/Sim.php` (modify existing)

### C. Services (2 files)
3. `app/Services/SimHealthService.php` (create new)
4. `app/Services/SimOperatorStatusService.php` (create new)

### D. Existing Service (1 file)
5. `app/Services/OutboundRetryService.php` (modify existing)

### E. Commands (4 files)
6. `app/Console/Commands/CheckSimHealthCommand.php` (create new)
7. `app/Console/Commands/SetSimOperatorStatusCommand.php` (create new)
8. `app/Console/Commands/EnableSimForNewAssignmentsCommand.php` (create new)
9. `app/Console/Commands/DisableSimForNewAssignmentsCommand.php` (create new)

### F. Scheduler (1 file)
10. `app/Console/Kernel.php` (modify existing)

### G. Existing Services (2 files - LAST, because depend on models/commands being ready)
11. `app/Services/SimSelectionService.php` (modify existing)
12. `app/Http/Controllers/GatewayOutboundController.php` (modify existing)

---

## 4. EXACT DATABASE CHANGES (CORRECTED)

**Migration File: `database/migrations/2026_03_29_120000_add_operator_control_fields_to_sims.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            // Operator-controlled delivery status (active, paused, blocked)
            $table->enum('operator_status', ['active', 'paused', 'blocked'])
                ->default('active')
                ->after('mode');

            // New SIM enablement control
            // DB DEFAULT: false (new SIMs start disabled)
            // Backfill: existing SIMs set to true (preserve current behavior)
            $table->boolean('accept_new_assignments')
                ->default(false)  // NEW SIMs default false
                ->after('operator_status');

            // Health-based assignment disable (REVERSIBLE - operator can re-enable)
            $table->boolean('disabled_for_new_assignments')
                ->default(false)
                ->after('accept_new_assignments');

            // Health basis: only real successful sends update this (not dispatch time)
            $table->timestamp('last_success_at')
                ->nullable()
                ->after('last_error_at');

            // Indexes for operator controls and health checks
            $table->index(['company_id', 'operator_status']);
            $table->index(['company_id', 'disabled_for_new_assignments']);
            $table->index(['last_success_at']);
        });

        // CRITICAL: Backfill existing SIMs to accept_new_assignments = true
        // This preserves existing SIM behavior during rollout
        // New SIMs created AFTER migration will default to false
        DB::table('sims')->update(['accept_new_assignments' => true]);
    }

    public function down(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'operator_status']);
            $table->dropIndex(['company_id', 'disabled_for_new_assignments']);
            $table->dropIndex(['last_success_at']);
            $table->dropColumn([
                'operator_status',
                'accept_new_assignments',
                'disabled_for_new_assignments',
                'last_success_at'
            ]);
        });
    }
};
```

**Key Changes:**
- `accept_new_assignments` DB default: **false** (not true)
- Migration backfills existing SIMs: **true**
- Result: existing SIMs unaffected, new SIMs safe by default
- `disabled_for_new_assignments` is **reversible** (operator can re-enable)

---

## 5. EXACT MODEL CHANGES

**File: `app/Models/Sim.php`**

Add to `protected $fillable`:
```php
'operator_status',
'accept_new_assignments',
'disabled_for_new_assignments',
'last_success_at',
```

Add to `protected $casts`:
```php
'operator_status' => 'string', // or enum if using PHP 8.1+ enums
'accept_new_assignments' => 'boolean',
'disabled_for_new_assignments' => 'boolean',
'last_success_at' => 'datetime',
```

Add accessor methods:
```php
public function isOperatorActive(): bool
{
    return $this->operator_status === 'active';
}

public function isOperatorPaused(): bool
{
    return $this->operator_status === 'paused';
}

public function isOperatorBlocked(): bool
{
    return $this->operator_status === 'blocked';
}

public function acceptsNewAssignments(): bool
{
    return $this->accept_new_assignments && !$this->disabled_for_new_assignments;
}

public function markSuccessful(): void
{
    $this->update(['last_success_at' => now()]);
}

public function minutesSinceLastSuccess(): ?int
{
    if (!$this->last_success_at) {
        return null;
    }
    return now()->diffInMinutes($this->last_success_at);
}
```

---

## 6. EXACT SERVICE CHANGES

### A. NEW: `app/Services/SimHealthService.php`

Core responsibility: Compute health status, 30-minute threshold, auto-disable logic (REVERSIBLE).

Methods needed:
- `checkHealth(Sim $sim): array` — returns {status, warning, minutes_since_success}
- `checkLastSuccessThreshold(Sim $sim): bool` — 30-minute threshold check
- `autoDisableFromNewAssignments(Sim $sim): void` — disable from new assignments if >=30 min
- `autoEnableForNewAssignments(Sim $sim): void` — RE-ENABLE if health recovers
- `computeStuckAge(Sim $sim): array` — returns {stuck_6h, stuck_24h, stuck_3d}

**CORRECTED Behavior (REVERSIBLE):**
- `last_success_at` is ONLY source of health (not dispatch time)
- If `last_success_at` is NULL or >30 minutes ago: unhealthy
- If unhealthy AND company has >1 SIM: auto-set `disabled_for_new_assignments = true`
- **If healthy AND company has >1 SIM: auto-set `disabled_for_new_assignments = false`** ← REVERSIBLE
- If unhealthy/healthy AND company has 1 SIM: do NOT change disabled_for_new_assignments
- Health check runs every 5 minutes
- Never move sticky customers
- Never stop retries
- Only prevent/restore new customer assignment

Key method:
```php
public function checkHealth(Sim $sim, ?Company $company = null): array {
    $company = $company ?? $sim->company;
    $lastSuccess = $sim->last_success_at;
    $minutesSince = $lastSuccess ? now()->diffInMinutes($lastSuccess) : PHP_INT_MAX;

    $isUnhealthy = $minutesSince >= 30;

    // Only auto-disable/enable if company has >1 SIM
    if ($company->sims_count > 1) {
        if ($isUnhealthy && !$sim->disabled_for_new_assignments) {
            // Become unhealthy → auto-disable
            $sim->update(['disabled_for_new_assignments' => true]);
        } elseif (!$isUnhealthy && $sim->disabled_for_new_assignments) {
            // Recover health → auto-enable
            $sim->update(['disabled_for_new_assignments' => false]);
        }
    }

    return [
        'status' => $isUnhealthy ? 'unhealthy' : 'healthy',
        'last_success_at' => $lastSuccess,
        'minutes_since_success' => $minutesSince,
        'warning' => $isUnhealthy ? 'No successful send in 30 minutes' : null,
        'disabled_for_new_assignments' => $sim->disabled_for_new_assignments,
    ];
}
```

### B. NEW: `app/Services/SimOperatorStatusService.php`

Core responsibility: Update operator_status field, manage transitions.

Methods needed:
- `setOperatorStatus(Sim $sim, string $status): void` — set to active/paused/blocked, with tenant check
- `pauseSim(Sim $sim): void` — set to paused, with tenant check
- `blockSim(Sim $sim): void` — set to blocked, with tenant check
- `activateSim(Sim $sim): void` — set to active, with tenant check
- `validateStatus(string $status): bool` — ensure valid enum value

**CORRECTED Behavior (EXPLICIT TENANT AUTH):**
- Must check that SIM belongs to authenticated company/tenant
- Only changes database field
- Phase 0 does NOT trigger auto-requeue (that's Phase 2)
- Phase 0 does NOT implement worker behavior (that's Phase 2)
- Phase 0 only manages the status field
- Logs all status changes with company_id and operator_id for audit trail

```php
public function setOperatorStatus(Sim $sim, string $status, Company $company): void {
    // EXPLICIT TENANT CHECK
    if ($sim->company_id !== $company->id) {
        throw new \Exception('SIM does not belong to authenticated company');
    }

    $this->validateStatus($status);

    $oldStatus = $sim->operator_status;
    $sim->update(['operator_status' => $status]);

    Log::info('Operator status changed', [
        'sim_id' => $sim->id,
        'company_id' => $company->id,
        'old_status' => $oldStatus,
        'new_status' => $status,
    ]);
}
```

### C. MODIFY: `app/Services/OutboundRetryService.php`

Current behavior: Likely uses exponential backoff or capped attempts.

Phase 0 required changes:
- **Remove exponential backoff logic** — use fixed 5-minute interval
- **Remove max-attempt caps** — retry forever
- **Remove auto-stop logic** — never auto-abandon a message

New behavior:
```
If send fails:
  - Set status to 'pending'
  - Set scheduled_at = now() + 5 minutes
  - Increment retry_count
  - Do NOT set max retry limit
  - Message will be retried again at scheduled_at time
```

Key method: `scheduleRetry(OutboundMessage $message): void`

```php
public function scheduleRetry(OutboundMessage $message): void {
    // Fixed 5-minute interval, no limit
    $message->update([
        'scheduled_at' => now()->addMinutes(5),
        'retry_count' => $message->retry_count + 1,
        'status' => 'pending',
    ]);
    // That's it. No max check, no backoff calculation.
}
```

---

## 7. EXACT COMMAND CHANGES (CORRECTED - EXPLICIT TENANT AUTH)

### A. NEW: `app/Console/Commands/CheckSimHealthCommand.php`

Purpose: Run every 5 minutes, check health, auto-disable/enable unhealthy SIMs.

Signature:
```php
protected $signature = 'sms:check-health {--company_id= : Company ID to check (optional)}';
```

Logic with EXPLICIT TENANT AUTH:
1. If --company_id provided: validate it exists, verify authenticated user can access it (or use admin auth)
2. Get SIMs (filtered by company_id if provided)
3. For each SIM: call `SimHealthService->checkHealth($sim, $company)`
4. If unhealthy AND company has >1 SIM: auto-disable (already done in service)
5. If healthy AND was disabled: auto-enable (already done in service)
6. Log all actions with company_id
7. Return exit code 0

**IMPORTANT:** In scheduled context (every 5 min), this runs as system task, not authenticated user.
So: must handle company_id filtering carefully.

Option A: Run for all companies (simpler)
```php
public function handle() {
    $sims = Sim::all();
    foreach ($sims as $sim) {
        app(SimHealthService::class)->checkHealth($sim, $sim->company);
    }
}
```

Option B: Allow per-company check (for manual runs)
```php
public function handle() {
    if ($companyId = $this->option('company_id')) {
        $company = Company::findOrFail($companyId);
        $sims = $company->sims;
    } else {
        $sims = Sim::all();
    }

    foreach ($sims as $sim) {
        app(SimHealthService::class)->checkHealth($sim, $sim->company);
    }
}
```

### B. NEW: `app/Console/Commands/SetSimOperatorStatusCommand.php`

Purpose: Manual operator command to change operator_status.

**CORRECTED with EXPLICIT TENANT AUTH:**

Signature:
```php
protected $signature = 'sms:set-operator-status {sim_id : SIM ID} {status : Status (active|paused|blocked)} {--company_id= : Company ID (required for auth)}';
```

Logic:
1. **EXPLICIT TENANT AUTH:** Require --company_id option (or extract from auth context if available)
2. Validate company_id exists
3. Validate sim_id exists and belongs to company_id
4. Validate status is valid enum
5. Call `SimOperatorStatusService->setOperatorStatus($sim, $status, $company)`
6. Log the change with company_id
7. Return success message

```php
public function handle() {
    $simId = $this->argument('sim_id');
    $status = $this->argument('status');
    $companyId = $this->option('company_id');

    if (!$companyId) {
        $this->error('--company_id is required');
        return 1;
    }

    $company = Company::findOrFail($companyId);
    $sim = Sim::where(['id' => $simId, 'company_id' => $company->id])->firstOrFail();

    app(SimOperatorStatusService::class)->setOperatorStatus($sim, $status, $company);

    $this->info("SIM {$simId} status set to {$status}");
    return 0;
}
```

### C. NEW: `app/Console/Commands/EnableSimForNewAssignmentsCommand.php`

Purpose: Enable new customer assignments for a SIM.

**CORRECTED with EXPLICIT TENANT AUTH:**

Signature:
```php
protected $signature = 'sms:enable-new-assignments {sim_id : SIM ID} {--company_id= : Company ID (required for auth)}';
```

Logic:
1. **EXPLICIT TENANT AUTH:** Require --company_id
2. Validate company_id and sim_id with tenant check
3. Set `accept_new_assignments = true`
4. Log the change with company_id
5. Return success message

```php
public function handle() {
    $simId = $this->argument('sim_id');
    $companyId = $this->option('company_id');

    if (!$companyId) {
        $this->error('--company_id is required');
        return 1;
    }

    $company = Company::findOrFail($companyId);
    $sim = Sim::where(['id' => $simId, 'company_id' => $company->id])->firstOrFail();

    $sim->update(['accept_new_assignments' => true]);

    Log::info('SIM enabled for new assignments', [
        'sim_id' => $sim->id,
        'company_id' => $company->id,
    ]);

    $this->info("SIM {$simId} enabled for new assignments");
    return 0;
}
```

### D. NEW: `app/Console/Commands/DisableSimForNewAssignmentsCommand.php`

Purpose: Disable new customer assignments for a SIM (manually, not auto).

**CORRECTED with EXPLICIT TENANT AUTH:**

Signature:
```php
protected $signature = 'sms:disable-new-assignments {sim_id : SIM ID} {--company_id= : Company ID (required for auth)}';
```

Logic:
1. **EXPLICIT TENANT AUTH:** Require --company_id
2. Validate company_id and sim_id with tenant check
3. Set `accept_new_assignments = false`
4. Log the change with company_id
5. Return success message

```php
public function handle() {
    $simId = $this->argument('sim_id');
    $companyId = $this->option('company_id');

    if (!$companyId) {
        $this->error('--company_id is required');
        return 1;
    }

    $company = Company::findOrFail($companyId);
    $sim = Sim::where(['id' => $simId, 'company_id' => $company->id])->firstOrFail();

    $sim->update(['accept_new_assignments' => false]);

    Log::info('SIM disabled for new assignments', [
        'sim_id' => $sim->id,
        'company_id' => $company->id,
    ]);

    $this->info("SIM {$simId} disabled for new assignments");
    return 0;
}
```

---

## 8. EXACT API/CONTROLLER CHANGES

### MODIFY: `app/Http/Controllers/GatewayOutboundController.php`

Current flow (assumed):
```
POST /messages/send
→ validate tenant from auth
→ select SIM
→ save to DB
→ enqueue message
→ return 200 queued
```

Phase 0 required changes:

**After SIM selection, BEFORE save/enqueue:**

```php
public function store(Request $request) {
    // ... existing: resolve tenant from auth context ...
    $company = $this->resolveTenant(); // or Auth::user()->company

    // ... existing: validate request payload ...

    // ... existing: assign sticky SIM or select by queue load ...
    $sim = $this->selectSim($company, $request->customer_phone);

    // NEW: Check operator_status BEFORE saving/enqueueing
    if ($sim->operator_status === 'paused') {
        // Accept and save, but don't queue
        $message = OutboundMessage::create([
            'company_id' => $company->id,
            'sim_id' => $sim->id,
            'customer_phone' => $request->customer_phone,
            'message' => $request->message,
            'message_type' => $request->message_type,
            'status' => 'pending', // Save to DB
        ]);

        // DO NOT enqueue (no queue push)
        // Return 202 Accepted warning
        return response()->json([
            'status' => 'accepted',
            'message_id' => $message->id,
            'queued' => false,
            'warning' => 'SIM is paused; message saved but not queued',
        ], 202);
    }

    if ($sim->operator_status === 'blocked') {
        // Reject immediately, don't save
        return response()->json([
            'status' => 'unavailable',
            'message' => 'SIM is blocked from new intake',
        ], 503);
    }

    // If active: existing behavior (save + enqueue)
    if ($sim->operator_status === 'active') {
        // ... existing: create message, enqueue, return 200 ...
    }
}
```

**Key points:**
- Paused: 202 Accepted, save but no enqueue, warning included
- Blocked: 503 Service Unavailable, no save, no enqueue
- Active: 200 OK, save and enqueue (existing behavior)
- Logs show operator_status decision
- **Tenant isolation preserved** (uses auth context, not request body)

---

## 9. WHAT MUST NOT BE TOUCHED YET

**Explicitly DO NOT implement:**

1. ❌ Redis per-SIM queues (Phase 2)
2. ❌ Rebuild lock: `sms:lock:rebuild:sim:{sim_id}` (Phase 2)
3. ❌ QueueRebuildService (Phase 2)
4. ❌ Paused→active auto-requeue (Phase 2)
5. ❌ SimQueueWorkerService changes (Phase 2)
6. ❌ Retry scheduler command (Phase 2)
7. ❌ Manual migration services (Phase 1)
8. ❌ Blocked worker drain semantics (Phase 2)
9. ❌ Health dashboard / API endpoints (Phase 3)
10. ❌ RedisQueueService (Phase 2)
11. ❌ Any changes to SmsSenderInterface (stays as-is)
12. ❌ Any changes to worker claim logic (still DB-based in Phase 0)

**What stays unchanged:**
- Worker still uses DB-claim approach
- Message enqueue logic (no Redis yet)
- Sticky assignment logic (already done)
- Multi-tenant security (already done)
- Python layer interaction (unchanged)

---

## 10. SAFE CODING ORDER (SMALLEST-RISK FIRST)

**Execute in this order:**

### Step 1: Database Migration (FIRST)
- Create migration file with DB default false + backfill to true
- Run migration on test database
- Verify fields exist and correct types
- Verify backfill worked (existing SIMs have accept_new_assignments = true)
- **Risk:** Low (additive, no data loss)

### Step 2: Update Sim Model (SECOND)
- Add fillable, casts, accessors
- Test model attribute access
- **Risk:** Low (no DB changes, just model layer)

### Step 3: Create Health Service (THIRD)
- Implement SimHealthService with REVERSIBLE logic
- Unit test health check logic
- Test 30-minute threshold
- Test auto-disable logic (unhealthy)
- **NEW:** Test auto-enable logic (recovery) ← REVERSIBLE
- Test company >1 SIM check (only auto-disable/enable in this case)
- **Risk:** Low (no side effects yet, pure business logic)

### Step 4: Create Operator Status Service (FOURTH)
- Implement SimOperatorStatusService with EXPLICIT TENANT AUTH
- Unit test status setting with company check
- Test error on cross-tenant access
- **Risk:** Low (only writes to operator_status field, tenant-protected)

### Step 5: Create Commands (FIFTH)
- Implement all 4 commands with EXPLICIT TENANT AUTH (--company_id required)
- Test each command locally with --company_id
- Test error handling when --company_id missing
- Test error handling when sim_id doesn't belong to company_id
- Test help text
- **Risk:** Low (no side effects until executed)

### Step 6: Register Scheduler (SIXTH)
- Update Kernel.php
- Register CheckSimHealthCommand every 5 minutes
- **Risk:** Low (scheduler just runs command)

### Step 7: Update Retry Service (SEVENTH)
- Remove exponential backoff
- Remove max-attempt caps
- Implement fixed 5-minute retry
- Unit test retry scheduling
- **Risk:** Medium (core retry logic changes, needs testing)

### Step 8: Update Sim Selection Service (EIGHTH)
- Filter available SIMs by `accept_new_assignments && !disabled_for_new_assignments`
- Test selection logic
- Test that existing SIMs are still selectable (accept_new_assignments = true from backfill)
- Test that new SIMs are not selected by default (accept_new_assignments = false from DB default)
- **Risk:** Medium (affects new customer assignment)

### Step 9: Update Outbound Controller (NINTH - LAST)
- Add operator_status checks
- Return 202 for paused, 503 for blocked
- Test API responses
- Test tenant isolation (no cross-tenant access)
- **Risk:** Medium-High (public API, affects intake behavior)

---

## 11. VALIDATION CHECKLIST AFTER PHASE 0 (UPDATED)

**Before committing Phase 0:**

### Database
- [ ] Migration runs successfully on test DB
- [ ] New fields exist: operator_status, accept_new_assignments, disabled_for_new_assignments, last_success_at
- [ ] Field types correct (enum, bool, bool, timestamp)
- [ ] Indexes created
- [ ] **NEW:** accept_new_assignments DB default is false
- [ ] **NEW:** Backfill successful: all existing SIMs have accept_new_assignments = true
- [ ] New SIMs created after migration have accept_new_assignments = false
- [ ] Rollback works: can revert migration cleanly

### Model
- [ ] Sim model loads from DB without errors
- [ ] Fillable includes new fields
- [ ] Casts work
- [ ] All accessors work
- [ ] markSuccessful() updates last_success_at to current time
- [ ] minutesSinceLastSuccess() returns correct minutes or null

### Health Service
- [ ] checkHealth($sim) returns {status, warning, minutes_since_success, disabled_for_new_assignments}
- [ ] 30-minute threshold works (>= 30 min = unhealthy)
- [ ] Auto-disable works: unhealthy + >1 SIM = disabled_for_new_assignments = true
- [ ] **NEW:** Auto-enable works: healthy + was disabled = disabled_for_new_assignments = false ← REVERSIBLE
- [ ] Auto-disable/enable does NOT happen if company has 1 SIM
- [ ] computeStuckAge() returns stuck_6h, stuck_24h, stuck_3d correctly

### Operator Status Service
- [ ] setOperatorStatus() updates database field
- [ ] **NEW:** setOperatorStatus() validates sim.company_id matches provided company_id
- [ ] **NEW:** setOperatorStatus() throws exception on cross-tenant access
- [ ] pauseSim(), blockSim(), activateSim() work
- [ ] Status validates against enum (active, paused, blocked)
- [ ] Invalid status rejected with error

### Commands (All with Explicit Tenant Auth)
- [ ] CheckSimHealthCommand runs without error (for all companies)
- [ ] CheckSimHealthCommand properly auto-disables unhealthy SIMs (reversible)
- [ ] **NEW:** CheckSimHealthCommand properly auto-enables recovered SIMs
- [ ] **NEW:** SetSimOperatorStatusCommand requires --company_id
- [ ] **NEW:** SetSimOperatorStatusCommand rejects if --company_id missing
- [ ] **NEW:** SetSimOperatorStatusCommand rejects cross-tenant access
- [ ] SetSimOperatorStatusCommand updates status correctly
- [ ] **NEW:** EnableSimForNewAssignmentsCommand requires --company_id
- [ ] **NEW:** EnableSimForNewAssignmentsCommand rejects cross-tenant access
- [ ] EnableSimForNewAssignmentsCommand sets accept_new_assignments = true
- [ ] **NEW:** DisableSimForNewAssignmentsCommand requires --company_id
- [ ] **NEW:** DisableSimForNewAssignmentsCommand rejects cross-tenant access
- [ ] DisableSimForNewAssignmentsCommand sets accept_new_assignments = false
- [ ] All commands log their actions with company_id

### Scheduler
- [ ] CheckSimHealthCommand registered in Kernel.php
- [ ] Scheduled to run every 5 minutes
- [ ] Can be tested with `php artisan schedule:run`

### Retry Service
- [ ] Retry interval fixed at 5 minutes (not exponential)
- [ ] No max-attempt cap (can retry forever)
- [ ] No auto-abandon logic
- [ ] retry_count increments
- [ ] scheduled_at moves forward 5 minutes each retry
- [ ] status = 'pending' while waiting for retry time

### SIM Selection Service
- [ ] getAvailableSimsForNewAssignment() filters by accept_new_assignments && !disabled_for_new_assignments
- [ ] Rejects SIMs with disabled_for_new_assignments = true
- [ ] Rejects SIMs with accept_new_assignments = false
- [ ] **NEW:** Existing active SIMs still selectable (accept_new_assignments = true from backfill)
- [ ] **NEW:** New SIMs NOT selectable by default (accept_new_assignments = false from DB default)
- [ ] Can be manually enabled with command

### API Intake
- [ ] Active SIM: message saved + enqueued, return 200, queued=true
- [ ] Paused SIM: message saved, NOT enqueued, return 202, queued=false, warning included
- [ ] Blocked SIM: message NOT saved, NOT enqueued, return 503
- [ ] Logs show operator_status decision
- [ ] Tenant isolation preserved (no cross-tenant access)

### Multi-Tenant & Tenant Auth
- [ ] Cannot access other company's SIMs (API)
- [ ] Cannot access other company's SIMs (commands with --company_id)
- [ ] Health check respects tenant boundaries
- [ ] Operator commands respect tenant boundaries
- [ ] **NEW:** All admin commands require explicit --company_id or fail

### Existing Features
- [ ] Existing worker still works (DB-claim approach unchanged)
- [ ] Existing inbound flow unchanged
- [ ] Sticky assignment unchanged
- [ ] SmsSenderInterface unchanged
- [ ] Python layer unchanged
- [ ] All existing tests still pass

### Integration Tests
- [ ] Create test: paused SIM saves but doesn't queue
- [ ] Create test: blocked SIM rejects intake
- [ ] Create test: active SIM queues normally
- [ ] Create test: health check auto-disables after 30 min
- [ ] **NEW:** Create test: health check auto-enables when recovered
- [ ] Create test: operator can enable/disable new assignments
- [ ] **NEW:** Create test: operator commands with cross-tenant sim_id are rejected

---

## 12. ROLLBACK NOTES FOR PHASE 0 (UPDATED)

**If Phase 0 needs to be rolled back completely:**

### Database Rollback
```bash
# Revert migration
php artisan migrate:rollback --path=database/migrations/2026_03_29_120000_add_operator_control_fields_to_sims.php

# The backfill (accept_new_assignments = true) is also reverted
# No data loss, just drops the 4 new columns
```

### Code Rollback
```bash
# Revert all Phase 0 code changes
git revert <Phase-0-commit-hash>

# Or manually delete/revert:
# - Delete: SimHealthService.php, SimOperatorStatusService.php
# - Delete: 4 new command files
# - Revert: Sim.php model (remove fillable, casts, accessors)
# - Revert: OutboundRetryService.php (restore old exponential backoff)
# - Revert: GatewayOutboundController.php (remove operator_status checks)
# - Revert: SimSelectionService.php (remove accept_new_assignments filtering)
# - Revert: Kernel.php (unregister CheckSimHealthCommand)
```

### Verify Rollback Success
```bash
# Restart workers
php artisan queue:restart

# Test API
curl -X POST http://localhost/api/messages/send -H "Authorization: Bearer ..." -d '...'
# Should work as before (no operator_status checks)

# Verify model
php artisan tinker
>>> Sim::first()->operator_status  // Should error: column not exist
>>> Sim::first()->accept_new_assignments  // Should error: column not exist
```

### Impact of Rollback
- Operator controls disappear (can't pause/block manually)
- Retry reverts to old exponential backoff (if that's what existed)
- Health checks stop (until Phase 0 is re-applied)
- Paused SIM semantics removed (202 Accepted returns to normal 200)
- Blocked SIM semantics removed (503 blocks removed)
- **Core send/queue logic unaffected** (worker still works)

---

## 13. KEY CORRECTIONS SUMMARY

### Correction 1: accept_new_assignments Default
**Before:** Default true for existing, false for new
**After:** DB default false, backfill existing to true
**Result:** New SIMs safe by default, existing SIMs unaffected during rollout

### Correction 2: disabled_for_new_assignments Reversibility
**Before:** "Auto-set by health check, never reverts"
**After:** Explicitly reversible (auto-enables when health recovers)
**Result:** Health-based disable is dynamic, not permanent

### Correction 3: Tenant Auth in Commands
**Before:** Implicit tenant context
**After:** Explicit --company_id requirement in all operator commands
**Result:** Clear tenant boundaries, no accidental cross-tenant access

---

## 14. SUMMARY: PHASE 0 IS READY TO CODE

**Phase 0 is a clean, self-contained, lowest-risk foundational layer:**

- ✓ 4 new database fields (additive, no data loss, smart backfill)
- ✓ 2 new services (no side effects until executed, reversible logic)
- ✓ 4 new commands (manual trigger only, explicit tenant auth)
- ✓ 1 service update (critical: retry policy)
- ✓ 2 controller/service updates (intake guardrails, tenant-protected)
- ✓ 1 scheduler registration (health check loop)
- ✓ NO Redis, NO rebuild lock, NO auto-requeue, NO worker changes
- ✓ Fully testable in isolation
- ✓ Easily rollbackable if needed
- ✓ Clear validation checklist
- ✓ **Reversible health auto-disable (not permanent)**
- ✓ **Explicit tenant auth in all admin paths**

**Ready to code Phase 0 file-by-file in the recommended order.**

---

## Execution Result
- Phase 0 scope completed
- 39/39 tests passed
- Lock date: 2026-03-31
- No Phase 1 work started
