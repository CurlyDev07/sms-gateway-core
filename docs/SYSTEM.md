# SYSTEM ARCHITECTURE

Last Updated: 2026-03-29

---

## OVERVIEW

This system is a multi-tenant SMS Gateway platform with:

- SMS Gateway (modem-based)
- Queue system
- SIM management
- API integration with Chat App (external system)
- External SMS Execution Layer (Python Modem Engine)
- Redis-based queue coordination for scale

Note:  
AI, RAG, automation, and chat logic are handled by a separate Chat App.

This system remains a **transport-only** platform. It is responsible for SMS intake, queueing, SIM assignment, retry scheduling, delivery execution, inbound relay, and operational controls. It does **not** own conversation intelligence or business automation.

---

## CORE COMPONENTS

### 1. SMS GATEWAY
- Handles sending/receiving SMS
- Manages queue, assignment, retry, migration, and health control
- Transport-only system (no intelligence)

---

### 2. BACKEND (Laravel)
- Multi-tenant system
- API + control / logic layer
- Queue orchestration
- Sticky SIM assignment
- Retry scheduling
- Manual migration logic
- Health monitoring logic
- Calls `SmsSenderInterface` (transport abstraction)

Laravel is the **control layer**.

It owns:
- tenant isolation
- message creation
- SIM selection
- customer sticky assignment
- retry policy
- queue routing
- operator controls
- monitoring state
- migration workflows

Laravel must **not** communicate with modem hardware directly.

---

### 3. MESSAGE TRANSPORT TYPES

- CHAT
- AUTO_REPLY
- FOLLOW_UP
- BLAST

Note:  
These are transport labels only.  
They are provided by the Chat App and used for queue prioritization.

Priority order is:

1. CHAT
2. AUTO_REPLY
3. FOLLOW_UP
4. BLAST

Operational queue grouping:

- CHAT and AUTO_REPLY share the same highest-priority queue tier
- FOLLOW_UP uses the medium-priority queue tier
- BLAST uses the lowest-priority queue tier

---

### 4. SMS EXECUTION LAYER (Python Modem Engine)

- Receives HTTP requests from Laravel Gateway
- Resolves stable SIM identity to real modem port
- Sends SMS via AT commands
- Returns standardized response
- Manages modem discovery and modem health at hardware level

Responsibilities:
- AT command execution
- modem communication
- SIM routing
- hardware-level retry / fallback
- hardware-level error handling
- modem discovery and modem registry
- normalized send result response back to Laravel

IMPORTANT:
- This layer handles **all modem communication**
- Laravel **must not** interact with modem directly

Python is the **execution layer**.

---

### 5. REDIS (QUEUE + COORDINATION LAYER)

Redis is used for:
- per-SIM queue transport
- worker-visible queue coordination
- rebuild locks during queue rebuild
- future high-speed scaling support

Redis is **not** the source of truth.

Redis does **not** own:
- tenant truth
- message truth
- retry truth
- reporting truth
- long-term audit trail

That remains in MySQL.

---

## MESSAGE FLOW

### Incoming SMS

USB Modem (SIM)  
→ Python SMS Engine  
→ Gateway inbound API (`/gateway/inbound`)  
→ Store inbound message  
→ Relay to Chat App via webhook

---

### Outgoing SMS

Chat App  
→ Gateway API (`/messages/send`)  
→ Resolve tenant from authenticated API client  
→ Assign SIM (sticky)  
→ Store outbound message in MySQL  
→ Route message into per-SIM Redis queue (if SIM is active)  
→ Per-SIM worker processes message  
→ `SmsSenderInterface`  
→ Python SMS Engine  
→ USB Modem  
→ SIM Card  
→ Customer

---

## MULTI-TENANT MODEL

This system is multi-tenant.

Rules:
- one company can have multiple SIMs
- one modem = one SIM only
- SIM is the operational unit of queueing and delivery
- all queueing, assignment, health, and monitoring are SIM-centric

Important:
- this system is **not modem-centric**
- it is **SIM-centric**
- modem and SIM are effectively 1:1 in this design

---

## QUEUE SYSTEM

### Core rule

Queueing is **per SIM**, not per company and not per modem group.

Each SIM has its own isolated sending lane:

- its own queue
- its own prioritization
- its own worker
- its own retry cycle
- its own health state

One SIM must never block another SIM.

Example:
- SIM 2 may be overloaded or broken
- SIM 3 must still continue sending immediately
- SIM 3 must not wait for SIM 2

---

### Redis queue structure

Each SIM has 3 Redis queues:

- `sms:queue:sim:{sim_id}:chat`
- `sms:queue:sim:{sim_id}:followup`
- `sms:queue:sim:{sim_id}:blasting`

Queue priority check order in worker:

1. chat
2. followup
3. blasting

Mapping:

- CHAT → `chat`
- AUTO_REPLY → `chat`
- FOLLOW_UP → `followup`
- BLAST → `blasting`

This preserves business priority while keeping queue inspection simple.

---

### MySQL remains source of truth

`outbound_messages` remains the authoritative message record.

Redis is only the fast transport layer.

If Redis needs to be rebuilt:
- DB is the source of truth
- queues are rebuilt from DB
- Redis should never become the primary truth source

---

## CUSTOMER STICKY ASSIGNMENT

Customer stickiness is required.

Rule:
- once a customer has history on a SIM, all future messages must continue to use that same SIM

History can mean:
- past outbound message
- successful outbound message
- inbound/outbound conversation history
- active assignment row

This means:
- if a customer belongs to SIM 1, future messages stay on SIM 1
- no automatic reassignment during temporary issues
- migration is operator-controlled only

---

## NEW CUSTOMER SIM SELECTION

When a customer has no existing sticky assignment:

Select the SIM with:
- fewer queued messages right now

This is preferred over:
- old historical customer count
- old historical load

Reason:
- current queue pressure is the most useful balancing signal for new assignment

---

### New SIM enablement rule

New SIMs do **not** automatically start receiving new customer assignments.

Rule:
- newly added SIMs must be explicitly enabled for new assignments by operator or super admin

This is controlled by:

- `accept_new_assignments`

Important rollout rule:
- this applies to new future SIMs
- existing active SIMs should continue to work without forced re-enable during rollout

---

## OPERATOR STATUS MODEL

Each SIM has an operator-controlled status:

- `active`
- `paused`
- `blocked`

These are operator controls, separate from health / modem / signal state.

---

### `active`
- accept new messages
- save to DB
- enqueue to Redis
- worker processes normally

Use case:
- normal operation

---

### `paused`
- accept new messages
- save to DB
- do **not** enqueue to Redis
- worker does **not** process that SIM

Use case:
- maintenance
- operator pause
- temporary hold while preserving history

API behavior:
- return **202 Accepted**
- message is saved
- message is not queued yet
- include warning in response

When SIM changes from `paused → active`:
- pending messages are auto-requeued from DB truth
- Redis queues are rebuilt safely

---

### `blocked`
- reject new intake immediately
- do **not** save new outbound message to DB
- do **not** enqueue new outbound message
- worker may continue draining already queued / already pending old messages

Use case:
- stop new intake immediately
- preserve ongoing drain of already existing queue
- emergency intake stop without orphaning old work

API behavior:
- return **503 Service Unavailable**
- no DB save
- no queue push

Important:
- blocked is a hard stop for **new intake**
- blocked is **not** a full hard stop for old pending messages already in system

---

## RETRY SYSTEM

Retry policy is SIM-local.

Rules:
- retry every 5 minutes
- retry forever
- do not auto-stop
- do not auto-migrate
- no message should be lost

This is especially important for:
- no load
- temporary no signal
- temporary modem issue
- temporary carrier issue

Messages remain on the same SIM until:
- success
- or operator manually migrates them

Retry truth remains in MySQL using `scheduled_at` / `retry_count`.

---

## MANUAL MIGRATION

Migration is **manual only**.

There is no automatic cross-SIM rescue.

If SIM 1 fails or operator wants to move load, operator can migrate:

- one customer at a time
- or bulk migrate all customers on SIM 1 → SIM 2

Bulk migration must move:
- sticky customer assignments
- pending messages
- future messages automatically through new sticky assignment

Migration is permanent until manually changed again.

---

### DB-first migration rule

Manual migration must follow DB-first truth:

1. update DB first
2. clear affected Redis queues
3. rebuild Redis queues from DB truth

Do **not** directly shuffle Redis items one by one.

Reason:
- DB is source of truth
- safer
- easier to recover
- lower duplicate risk

---

### Safe rebuild scope

When rebuilding queues from DB:
- include only `status = pending`
- do **not** requeue `sending` messages directly
- `sending` recovery is handled separately by stale lock recovery / recovery command

This minimizes duplicate-send risk.

---

### Rebuild lock rule

Queue rebuild must be protected by a worker-visible Redis lock.

Pattern:
- set rebuild lock
- worker must check rebuild lock before any `LPOP`
- clear queues
- rebuild from DB pending truth
- release lock in `finally`

This prevents:
- queue rebuild races
- worker pop during rebuild
- duplicate queue entries during resume / migration

---

## HEALTH & MONITORING

### Main red-flag rule

A SIM should be considered operationally unhealthy if:
- no real successful send in 30 minutes

This is the main warning / critical trigger.

Health checks should run:
- every 5 minutes

---

### Health basis

Health must be based on:

- `last_success_at`

Not:
- dispatch attempt time
- attempted send time
- ambiguous `last_sent_at`

`last_success_at` means:
- updated only when a real successful send happens

---

### Stuck-age warnings

In dashboard, compute warnings such as:
- stuck_6h
- stuck_24h
- stuck_3d

These should be visible for operators while retries continue forever.

---

### Auto-disable from new assignments

If a SIM has:
- no success in 30 minutes
- and company has more than 1 SIM

Then:
- disable that SIM from **new assignments only**

This does **not**:
- move sticky customers
- clear old queue
- stop retries
- migrate messages

It only prevents new customers from being assigned there.

This is represented by:

- `disabled_for_new_assignments`

---

## OPERATOR CONTROLS

### Operator / super admin must be able to:

- enable SIM for new assignments
- disable SIM for new assignments
- set SIM operator status (`active`, `paused`, `blocked`)
- manually migrate customers/messages from one SIM to another
- inspect per-SIM queue load and health
- pause delivery without losing intake history
- block new intake immediately when necessary

---

## TENANT IDENTITY RULE

Tenant identity must always come from authenticated context.

Rules:
- do not trust `company_id` from request body
- resolve tenant/company through auth middleware / tenant context
- worker uses message company_id from DB truth
- all admin operations must enforce tenant/company boundaries

This is critical for multi-tenant safety.

---

## RULES (CRITICAL)

- Higher priority messages are processed first **inside each SIM**
- CHAT and AUTO_REPLY are highest-priority queue tier
- FOLLOW_UP is medium-priority queue tier
- BLAST is lowest-priority queue tier
- Message type affects scheduling behavior
- Always return 200 OK in webhook
- Deduplicate inbound processing where required

- Gateway does **not** interpret message meaning
- Gateway does **not** execute business logic
- Gateway only handles transport and delivery

---

## DO NOT BREAK

- Message type separation (labels only)
- Queue priority system
- Multi-tenant isolation
- Transport abstraction (`SmsSenderInterface`)
- Sticky customer assignment
- Per-SIM isolation
- DB as source of truth
- Laravel/Python boundary
- Manual-only migration rule

---

## ARCHITECTURE BOUNDARY (CRITICAL)

This system is a **transport layer only**.

---

### IT DOES NOT:

- generate AI responses
- perform automation
- manage conversations
- process business logic
- store AI memory or RAG data
- communicate directly with hardware from Laravel

---

### IT ONLY:

- sends SMS (via abstraction layer)
- receives SMS
- assigns SIMs
- enforces SIM-centric queueing
- enforces retry scheduling
- tracks delivery and health
- exposes operator controls
- performs manual migration workflows

---

### EXTERNAL SYSTEMS

#### 1. Chat App (separate project)
Handles:
- AI
- RAG
- automation
- conversation logic

#### 2. Python SMS Engine
Handles:
- modem communication
- modem discovery
- AT command execution
- SIM-to-port mapping
- hardware-level delivery
- hardware-level error handling

---

## CURRENT HIGH-LEVEL ARCHITECTURE

```text
Chat App / External System
→ Laravel Gateway API
→ MySQL (source of truth)
→ Redis per-SIM priority queues
→ Per-SIM Laravel worker
→ SmsSenderInterface
→ Python SMS Engine
→ USB Modem
→ SIM
→ Customer