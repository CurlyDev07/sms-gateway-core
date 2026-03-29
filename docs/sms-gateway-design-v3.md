# 📡 SMS Gateway System – Final Design (v3.1 Locked)
## With Multi-Tenant SIM-Centric Queueing, Operator Controls, Python Execution Layer, and Redis Worker Architecture

Last Updated: 2026-03-29

---

# 1. SYSTEM OVERVIEW

A multi-tenant, SIM-centric SMS gateway system that:

- Sends SMS via single-SIM EC25 USB modems
- Integrates with an external Chat App via API
- Supports transport message types:
  - CHAT
  - AUTO_REPLY
  - FOLLOW_UP
  - BLAST
- Uses:
  - sticky customer-to-SIM assignment
  - per-SIM isolated queueing
  - operator-controlled SIM states
  - fixed retry scheduling
  - manual migration only
  - Redis queue transport
  - Python execution layer for modem communication

Important:
- The Chat App is the intelligence layer
- This Gateway is transport-only
- Laravel is the control layer
- Python is the execution layer
- MySQL is the source of truth
- Redis is queue transport / coordination only

---

# 2. HIGH-LEVEL ARCHITECTURE

```text
Chat App / External System
        ↓
Laravel Gateway API (Control Layer)
        ↓
MySQL (Source of Truth)
        ↓
Redis Per-SIM Priority Queues
        ↓
Per-SIM Laravel Worker
        ↓
SmsSenderInterface
        ↓
Python SMS Engine (Execution Layer)
        ↓
EC25 USB Modem
        ↓
SIM Card
        ↓
Carrier Network
        ↓
Customer Phone
```

Notes:

- Chat App = Brain (AI, RAG, Automation, Conversations)
- SMS Gateway = Transport Layer (this project)
- Python SMS Engine = Hardware Execution Layer

The gateway does NOT execute business logic.

---

# 3. CORE RULES

## 3.1 Multi-tenant
- One gateway serves many companies
- Tenant identity must always come from authenticated context
- Request body `company_id` must not be trusted

## 3.2 SIM-centric model
- 1 company can have many SIMs
- 1 modem = 1 SIM only
- 1 customer belongs to 1 SIM at a time
- queueing is per SIM
- workers are per SIM
- monitoring is per SIM
- retry is per SIM
- migration is per SIM

## 3.3 Sticky customer assignment
- Once assigned, future messages continue on the same SIM
- Sticky history may come from:
  - outbound history
  - successful outbound history
  - inbound/outbound conversation history
  - active assignment row
- No automatic reassignment
- Only manual migration may move sticky customers

## 3.4 New customer selection
If customer has no sticky assignment:
- select SIM with fewer queued messages right now

New future SIMs:
- must not automatically receive new customers
- must be manually enabled for new assignments

## 3.5 Message priorities
Priority order:
1. CHAT
2. AUTO_REPLY
3. FOLLOW_UP
4. BLAST

Queue grouping:
- CHAT → highest tier
- AUTO_REPLY → same as CHAT
- FOLLOW_UP → medium tier
- BLAST → lowest tier

Important:
- Priority affects queue scheduling only
- Gateway does NOT interpret business meaning

## 3.6 Sending / retry rules
- Retry every 5 minutes
- Retry forever
- Retry stays on same SIM
- No auto-stop
- No automatic cross-SIM failover
- No message should be lost

## 3.7 Operator controls
Each SIM has:
- `operator_status`:
  - `active`
  - `paused`
  - `blocked`
- `accept_new_assignments`
- `disabled_for_new_assignments`

Operator controls:
- manual pause
- manual block
- manual enable/disable for new assignments
- manual migration

## 3.8 Health rules
Health basis:
- `last_success_at`

Not:
- dispatch attempt time
- ambiguous send attempt time

Main warning:
- no success in 30 minutes

If company has more than 1 SIM:
- SIM may be disabled for new assignments only

Stuck-age warnings:
- stuck_6h
- stuck_24h
- stuck_3d

---

# 4. DATABASE SCHEMA

## 4.1 companies

### Purpose
Tenant/company record.

### Fields
- id
- uuid
- name
- code
- status
- timezone
- created_at
- updated_at

### Suggested migration
```php
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('name');
    $table->string('code')->nullable()->unique();
    $table->enum('status', ['active', 'suspended', 'disabled'])->default('active');
    $table->string('timezone')->default('Asia/Manila');
    $table->timestamps();
});
```

---

## 4.2 modems

### Purpose
Physical modem devices.

### Fields
- id
- uuid
- device_name
- vendor
- model
- chipset
- serial_number
- usb_path
- control_port
- status
- last_seen_at
- created_at
- updated_at

### Suggested migration
```php
Schema::create('modems', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('device_name')->nullable();
    $table->string('vendor')->nullable();
    $table->string('model')->nullable();
    $table->string('chipset')->nullable();
    $table->string('serial_number')->nullable()->index();
    $table->string('usb_path')->nullable();
    $table->string('control_port')->nullable()->index();
    $table->enum('status', ['online', 'offline', 'disabled', 'error'])->default('offline');
    $table->timestamp('last_seen_at')->nullable();
    $table->timestamps();
});
```

---

## 4.3 sims

### Purpose
Logical SIM record assigned to a company and mapped to one modem.

### Fields
- id
- uuid
- company_id
- modem_id
- slot_name
- phone_number
- carrier
- sim_label
- status
- mode
- operator_status
- accept_new_assignments
- disabled_for_new_assignments
- daily_limit
- recommended_limit
- burst_limit
- burst_interval_min_seconds
- burst_interval_max_seconds
- normal_interval_min_seconds
- normal_interval_max_seconds
- cooldown_min_seconds
- cooldown_max_seconds
- burst_count
- cooldown_until
- last_success_at
- last_received_at
- last_error_at
- notes
- created_at
- updated_at

### Suggested migration
```php
Schema::create('sims', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('modem_id')->nullable()->constrained('modems');
    $table->string('slot_name')->nullable();
    $table->string('phone_number', 30)->unique();
    $table->string('carrier', 50)->nullable();
    $table->string('sim_label')->nullable();
    $table->enum('status', ['active', 'cooldown', 'disabled', 'error', 'offline'])->default('active');
    $table->enum('mode', ['NORMAL', 'BURST', 'COOLDOWN'])->default('NORMAL');
    $table->enum('operator_status', ['active', 'paused', 'blocked'])->default('active');
    $table->boolean('accept_new_assignments')->default(true);
    $table->boolean('disabled_for_new_assignments')->default(false);
    $table->unsignedInteger('daily_limit')->default(4000);
    $table->unsignedInteger('recommended_limit')->default(3000);
    $table->unsignedInteger('burst_limit')->default(30);
    $table->unsignedInteger('burst_interval_min_seconds')->default(2);
    $table->unsignedInteger('burst_interval_max_seconds')->default(3);
    $table->unsignedInteger('normal_interval_min_seconds')->default(5);
    $table->unsignedInteger('normal_interval_max_seconds')->default(8);
    $table->unsignedInteger('cooldown_min_seconds')->default(60);
    $table->unsignedInteger('cooldown_max_seconds')->default(120);
    $table->unsignedInteger('burst_count')->default(0);
    $table->timestamp('cooldown_until')->nullable();
    $table->timestamp('last_success_at')->nullable();
    $table->timestamp('last_received_at')->nullable();
    $table->timestamp('last_error_at')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->index(['company_id', 'status']);
});
```

Note:
- For rollout, new future SIMs should default to `accept_new_assignments = false`
- Existing active SIMs should not be broken during migration rollout

---

## 4.4 customer_sim_assignments

### Purpose
Sticky customer-to-SIM mapping.

### Fields
- id
- company_id
- customer_phone
- sim_id
- status
- assigned_at
- last_used_at
- last_inbound_at
- last_outbound_at
- has_replied
- safe_to_migrate
- migration_locked
- created_at
- updated_at

### Suggested migration
```php
Schema::create('customer_sim_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained('companies');
    $table->string('customer_phone', 30);
    $table->foreignId('sim_id')->constrained('sims');
    $table->enum('status', ['active', 'migrated', 'disabled'])->default('active');
    $table->timestamp('assigned_at');
    $table->timestamp('last_used_at')->nullable();
    $table->timestamp('last_inbound_at')->nullable();
    $table->timestamp('last_outbound_at')->nullable();
    $table->boolean('has_replied')->default(false);
    $table->boolean('safe_to_migrate')->default(false);
    $table->boolean('migration_locked')->default(false);
    $table->timestamps();

    $table->unique(['company_id', 'customer_phone']);
    $table->index(['company_id', 'sim_id']);
});
```

---

## 4.5 outbound_messages

### Purpose
Outbound truth table and message lifecycle log.

### Fields
- id
- uuid
- company_id
- sim_id
- customer_phone
- message
- message_type
- priority
- status
- scheduled_at
- queued_at
- sent_at
- failed_at
- failure_reason
- retry_count
- client_message_id
- campaign_id
- conversation_ref
- metadata
- created_at
- updated_at

### Suggested migration
```php
Schema::create('outbound_messages', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('company_id')->constrained('companies');
    $table->foreignId('sim_id')->nullable()->constrained('sims');
    $table->string('customer_phone', 30)->index();
    $table->text('message');
    $table->enum('message_type', ['CHAT', 'AUTO_REPLY', 'FOLLOW_UP', 'BLAST']);
    $table->unsignedTinyInteger('priority')->default(10);
    $table->enum('status', ['pending', 'queued', 'sending', 'sent', 'failed', 'cancelled'])->default('pending');
    $table->timestamp('scheduled_at')->nullable();
    $table->timestamp('queued_at')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->timestamp('failed_at')->nullable();
    $table->text('failure_reason')->nullable();
    $table->unsignedInteger('retry_count')->default(0);
    $table->string('client_message_id')->nullable()->index();
    $table->string('campaign_id')->nullable()->index();
    $table->string('conversation_ref')->nullable()->index();
    $table->json('metadata')->nullable();
    $table->timestamps();

    $table->index(['company_id', 'sim_id', 'status']);
    $table->index(['company_id', 'status', 'priority', 'created_at']);
});
```

---

## 4.6 inbound_messages

### Purpose
Inbound SMS record.

### Suggested fields
- id
- uuid
- company_id
- sim_id
- customer_phone
- message
- received_at
- relay_status
- relay_retry_count
- relay_last_error
- metadata
- created_at
- updated_at

---

## 4.7 api_clients

### Purpose
Authenticated client records for tenant-safe API usage.

### Suggested fields
- id
- company_id
- name
- api_key
- api_secret_hash
- status
- created_at
- updated_at

---

## 4.8 sim_daily_stats

### Purpose
Daily per-SIM counters and reporting.

### Suggested fields
- id
- sim_id
- stat_date
- sent_count
- sent_chat_count
- sent_auto_reply_count
- sent_follow_up_count
- sent_blast_count
- failed_count
- inbound_count
- created_at
- updated_at

---

## 4.9 sim_health_logs

### Purpose
Operational health snapshots and diagnostics.

### Suggested fields
- id
- sim_id
- status
- signal_strength
- network_state
- operator_status
- disabled_for_new_assignments
- notes
- created_at
- updated_at

---

# 5. REDIS QUEUE DESIGN

## 5.1 Per-SIM queue model

Each SIM has 3 Redis queues:

- `sms:queue:sim:{sim_id}:chat`
- `sms:queue:sim:{sim_id}:followup`
- `sms:queue:sim:{sim_id}:blasting`

Priority order checked by worker:
1. chat
2. followup
3. blasting

Mapping:
- CHAT → chat
- AUTO_REPLY → chat
- FOLLOW_UP → followup
- BLAST → blasting

## 5.2 Redis role
Redis is used for:
- queue transport
- queue coordination
- rebuild locks

Redis is not used for:
- truth
- long-term audit
- tenant truth
- retry truth

If Redis conflicts with DB:
- MySQL wins

## 5.3 Rebuild lock
Required worker-visible Redis lock:
- `sms:lock:rebuild:sim:{sim_id}`

Rule:
- worker must check rebuild lock before any `LPOP`
- rebuild must set the lock before queue clear/rebuild
- rebuild must clear the lock in `finally`

---

# 6. API INTAKE SEMANTICS

## 6.1 Tenant identity
- must come from authenticated context
- must not trust request body `company_id`

## 6.2 SIM selection
If sticky assignment exists:
- use same SIM

If no sticky assignment:
- choose SIM with fewer queued messages right now
- only from eligible SIMs

## 6.3 Operator-status behavior

### Active
- save to DB
- enqueue to Redis
- return 200 queued

### Paused
- save to DB
- do not enqueue
- return 202 accepted warning

### Blocked
- reject immediately
- no DB save
- no queue push
- return 503 blocked

Note:
- blocked affects new intake only
- old already-existing pending / queued messages may still drain

---

# 7. OUTBOUND PROCESSING FLOW

```text
Chat App / External System
    ↓ authenticated request
Laravel Gateway API
    ↓ tenant resolution from auth context
Sticky SIM lookup / new SIM selection
    ↓
Apply operator_status rules
    ↓
MySQL save (if allowed)
    ↓
Redis per-SIM queue push (if active)
    ↓
Per-SIM worker checks chat → followup → blasting
    ↓
Worker checks rebuild lock before LPOP
    ↓
Worker claims message
    ↓
SmsSenderInterface
    ↓
Python SMS Engine
    ↓
AT Commands / Modem Controller
    ↓
ttyUSB
    ↓
USB Hub
    ↓
SIM
    ↓
Carrier
    ↓
Customer
```

---

# 8. PYTHON EXECUTION LAYER

## Responsibilities
- modem discovery
- modem registry
- SIM identity resolution
- AT command sending
- hardware-level fallback / error normalization
- structured send result

## Must not own
- business retry policy
- tenant policy
- sticky assignment
- queue truth
- migration policy

## Required endpoints
- `/send`
- `/modems/discover`
- `/modems/health`

---

# 9. WORKER STRUCTURE

## 9.1 Per-SIM worker
One worker loop per SIM.

Worker responsibilities:
- respect SIM operator_status
- respect SIM availability
- respect rebuild lock
- pop from Redis in priority order
- claim from DB truth
- call `SmsSenderInterface`
- update DB and stats

## 9.2 Queue claim order
- chat
- followup
- blasting

## 9.3 Paused vs blocked
- paused: worker skips that SIM
- blocked: worker may continue draining old already-existing queue

---

# 10. RETRY SYSTEM

Final retry policy:
- every 5 minutes
- forever
- same SIM
- no auto-stop
- no auto-migration
- no automatic cross-SIM failover

If send fails:
- message stays with same SIM
- retry_count increments
- scheduled_at moves forward by 5 minutes

---

# 11. OPERATOR CONTROLS

## 11.1 operator_status
- active
- paused
- blocked

## 11.2 assignment controls
- accept_new_assignments
- disabled_for_new_assignments

## 11.3 manual migration
Operator can:
- migrate one customer
- bulk migrate all customers/messages from SIM A to SIM B

Migration moves:
- sticky assignments
- pending messages
- future traffic through new sticky assignment

---

# 12. MANUAL MIGRATION RULES

Migration is manual only.

No automatic failover.

## 12.1 DB-first migration
Migration must:
1. update DB first
2. clear Redis queues for affected SIM(s)
3. rebuild Redis queues from DB truth

Do not directly shuffle Redis items as primary migration strategy.

## 12.2 Safe rebuild scope
Rebuild from DB using:
- `status = pending` only

Do not directly requeue:
- `sending`

Recovery for stale `sending` must be separate.

---

# 13. HEALTH / MONITORING

## 13.1 Health basis
Use:
- `last_success_at`

## 13.2 Main health warning
If no real success in 30 minutes:
- mark SIM unhealthy for monitoring
- if company has >1 SIM, disable from new assignments only

## 13.3 Stuck-age warnings
- stuck_6h
- stuck_24h
- stuck_3d

## 13.4 Monitoring data per SIM
- queue depth
- counts by priority tier
- operator_status
- accept_new_assignments
- disabled_for_new_assignments
- last_success_at
- active customers
- modem/signal info where available

---

# 14. INBOUND FLOW

```text
Customer Reply
    ↓
Carrier / SIM / Modem
    ↓
Python SMS Engine
    ↓
Gateway inbound endpoint
    ↓
Store inbound in MySQL
    ↓
Relay to Chat App webhook
```

Inbound remains:
- transport-only
- tenant-safe
- deduplicated where needed

---

# 15. WHAT THIS SYSTEM DOES NOT DO

The gateway does NOT:
- generate AI responses
- interpret conversations
- own automation logic
- make business decisions
- perform RAG / memory orchestration

That belongs to the Chat App.

---

# 16. FINAL LOCKED PRINCIPLES

- Laravel controls
- Python executes
- MySQL is truth
- Redis transports
- queues are per SIM
- workers are per SIM
- sticky assignment stays
- migration is manual only
- retries stay on same SIM
- tenant identity comes from auth
- no automatic cross-SIM rescue
- gateway remains transport-only
