# FULL SYSTEM FLOW

Last Updated: 2026-03-29

---

## PURPOSE

This file shows the full operational flow of the SMS Gateway Core using the final locked architecture.

This system is:
- multi-tenant
- transport-only
- SIM-centric
- Laravel control layer
- Python execution layer
- MySQL source of truth
- Redis queue transport / coordination

This system is **not**:
- AI logic
- conversation engine
- automation engine
- business workflow engine

---

## HIGH-LEVEL LAYER FLOW

```text
Chat App / External System
    ↓
Laravel Gateway API (Control Layer)
    ↓
MySQL (Source of Truth)
    ↓
Redis Per-SIM Queues (Transport / Coordination)
    ↓
Per-SIM Laravel Worker
    ↓
SmsSenderInterface
    ↓
Python SMS Engine (Execution Layer)
    ↓
Modem Controller / AT Commands
    ↓
ttyUSB Port
    ↓
USB Hub
    ↓
SIM Card
    ↓
Carrier Network
    ↓
Real SMS to Customer
```

---

## OUTBOUND MESSAGE FLOW

### 1. External system sends message request
The Chat App or external system sends an authenticated request to Laravel Gateway.

Important:
- tenant/company identity is resolved from authenticated context
- request body `company_id` must not be trusted

---

### 2. Laravel validates tenant and request
Laravel:
- validates payload
- resolves tenant from auth context
- determines message type:
  - CHAT
  - AUTO_REPLY
  - FOLLOW_UP
  - BLAST

---

### 3. Laravel selects SIM
Laravel applies SIM-centric logic:

#### If customer already has sticky assignment:
- keep customer on same SIM

#### If customer is new:
- select SIM with fewer queued messages right now
- only from SIMs that:
  - are active enough for assignment
  - accept new assignments
  - are not disabled for new assignments

---

### 4. Laravel applies operator status rules

Each SIM has `operator_status`:

- `active`
- `paused`
- `blocked`

#### If SIM is `active`
- create outbound message in DB
- enqueue to Redis
- return normal queued response

#### If SIM is `paused`
- create outbound message in DB
- do not enqueue
- return `202 Accepted` warning response

#### If SIM is `blocked`
- reject new intake immediately
- do not create outbound DB record
- do not enqueue
- return error response

---

### 5. Laravel writes outbound message to MySQL
MySQL is always source of truth.

Stored data includes:
- company_id
- sim_id
- customer_phone
- message
- message_type
- status
- priority
- retry_count
- scheduled_at
- timestamps

---

### 6. Laravel routes to Redis per-SIM queue
If SIM is active, Laravel pushes message ID into the correct Redis queue.

Per-SIM queue structure:

- `sms:queue:sim:{sim_id}:chat`
- `sms:queue:sim:{sim_id}:followup`
- `sms:queue:sim:{sim_id}:blasting`

Queue mapping:

- CHAT → `chat`
- AUTO_REPLY → `chat`
- FOLLOW_UP → `followup`
- BLAST → `blasting`

---

### 7. Per-SIM worker checks queues in priority order
Each SIM has its own worker loop.

Worker priority order:

1. `chat`
2. `followup`
3. `blasting`

This guarantees:
- one SIM never blocks another SIM
- priority is enforced inside that SIM only

---

### 8. Worker checks rebuild lock before any queue pop
Before any `LPOP`, the worker must check for a worker-visible rebuild lock.

If rebuild lock exists:
- worker waits
- no `LPOP` happens
- queue rebuild can complete safely

This protects:
- paused → active auto-requeue
- manual migration rebuild
- queue repair / recovery rebuild

---

### 9. Worker claims message and sends through transport abstraction
The per-SIM worker:
- loads the DB record
- verifies it is safe to claim
- marks it as sending
- calls `SmsSenderInterface`

Laravel still does **not** talk to hardware directly.

---

### 10. SmsSenderInterface calls Python execution layer
Laravel uses `SmsSenderInterface` as transport abstraction.

That abstraction calls Python API.

This preserves:
- swappable sender behavior
- clean Laravel/Python separation
- transport-only boundary

---

### 11. Python resolves SIM to real modem execution path
Python execution layer:
- uses modem registry / modem discovery
- resolves SIM identity to actual modem route
- avoids full scan during send path
- normalizes modem/hardware errors

Python is execution-only.

---

### 12. Python sends via modem controller
Python:
- opens modem communication
- sends AT commands
- targets correct ttyUSB port
- sends SMS via modem / SIM

Flow:

```text
Python API
    ↓
Modem Controller
    ↓
AT Commands
    ↓
ttyUSB Port
    ↓
USB Hub
    ↓
SIM Card
    ↓
Carrier Network
    ↓
Customer receives SMS
```

---

### 13. Python returns structured result to Laravel
Python returns normalized result like:
- success
- error
- raw modem response
- hardware / network details if available

Laravel then decides:
- mark sent
- schedule retry
- leave on same SIM
- or wait for manual migration

Python does **not** own retry policy.

---

### 14. Laravel updates message state
If send succeeds:
- mark outbound as sent
- update stats
- update `last_success_at`

If send fails:
- schedule retry
- keep message on same SIM
- no auto migration

---

## RETRY FLOW

Retry is controlled by Laravel only.

Final retry rules:
- every 5 minutes
- forever
- on same SIM
- no auto-stop
- no auto cross-SIM failover

If send fails:
- outbound stays under same SIM
- retry_count increments
- scheduled_at is moved forward by 5 minutes
- message retries until success or operator manually migrates

---

## PAUSED SIM FLOW

When SIM is paused:

### Intake behavior
- accept request
- save message to DB
- do not enqueue
- return warning-style accepted response

### Worker behavior
- worker skips processing for that SIM

### Resume behavior
When SIM changes from `paused → active`:
- rebuild lock is set
- Redis queues for that SIM are cleared
- Redis queues are rebuilt from DB truth (`pending` only)
- rebuild lock is removed
- worker resumes processing

This avoids duplicate queue items.

---

## BLOCKED SIM FLOW

When SIM is blocked:

### New intake behavior
- reject immediately
- do not save new outbound message
- do not enqueue

### Existing historical queue behavior
- already-existing queued/pending work may continue draining
- blocked is a new-intake stop, not necessarily a historical drain-stop

---

## MANUAL MIGRATION FLOW

Migration is operator-controlled only.

No automatic failover.

### Bulk migration flow
Operator triggers migration from SIM A → SIM B.

System does:

1. update DB first
2. move sticky assignments
3. move pending messages logically in DB
4. clear/rebuild Redis queues from DB truth
5. old SIM queue empties
6. new SIM queue receives migrated pending work

Important:
- MySQL is truth
- Redis is rebuilt from DB
- direct Redis shuffling is not the primary strategy

---

## REBUILD SAFETY FLOW

During queue rebuild:

1. set worker-visible rebuild lock
2. worker checks lock before LPOP
3. clear Redis queues for that SIM
4. load `pending` messages from DB truth
5. repush into correct Redis queues
6. release rebuild lock in `finally`

This guarantees:
- no duplicate queue rebuild
- no queue-pop during rebuild
- safe paused→active resume
- safe migration rebuild

---

## HEALTH / MONITORING FLOW

### Health basis
Use:
- `last_success_at`

Not:
- attempt time
- dispatch time only

### Core warning rule
If no real successful send in 30 minutes:
- SIM becomes health-warning candidate
- if company has >1 SIM, disable from new assignments only

### Stuck-age visibility
Dashboard should compute:
- stuck_6h
- stuck_24h
- stuck_3d

### Monitoring should show per SIM
- queue depth
- message counts by type
- last_success_at
- active customers
- operator_status
- assignment flags
- health warnings
- modem/signal data where available

---

## INBOUND FLOW

Inbound remains simpler than outbound.

Flow:

```text
Customer SMS Reply
    ↓
Carrier / SIM / Modem
    ↓
Python SMS Engine
    ↓
Gateway inbound endpoint
    ↓
Store inbound message in MySQL
    ↓
Relay to Chat App webhook
```

Inbound remains:
- transport-only
- tenant-safe
- deduplicated where required

---

## FINAL DESIGN PRINCIPLES

- Laravel controls
- Python executes
- MySQL is truth
- Redis transports
- queues are per SIM
- workers are per SIM
- sticky assignment stays
- migration is manual only
- retries stay on same SIM
- no message should be lost
- tenant identity comes from auth
- gateway remains transport-only
