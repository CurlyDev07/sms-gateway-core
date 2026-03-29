# AI_RULES

Last Updated: 2026-03-29

---

## PURPOSE

This file defines the non-negotiable implementation rules for the SMS Gateway Core.

Any AI assistant, coding agent, or engineer working on this project must follow these rules strictly.

These rules exist to:
- preserve architecture boundaries
- prevent undocumented redesigns
- avoid business-logic creep
- keep implementation aligned with the locked system design

If a requested change conflicts with these rules, the change must be rejected or escalated before implementation.

---

## PRIMARY ARCHITECTURE RULE

This system is a **transport-only SMS Gateway**.

It is **not**:
- an AI system
- a chatbot engine
- an automation engine
- a business workflow engine
- a conversation manager
- a rules engine for customer decisions

It **is only**:
- message intake
- message queueing
- SIM assignment
- delivery execution
- retry scheduling
- inbound relay
- operational controls
- monitoring / observability

---

## SYSTEM LAYER OWNERSHIP

### Laravel = Control Layer
Laravel owns:
- tenant isolation
- authenticated API intake
- message creation
- SIM selection
- sticky customer assignment
- retry scheduling
- manual migration logic
- queue orchestration
- operator controls
- monitoring state
- DB truth

Laravel must **not**:
- touch modem hardware directly
- send AT commands directly
- own serial port logic

---

### Python = Execution Layer
Python owns:
- modem discovery
- modem registry
- serial / ttyUSB communication
- AT commands
- SMS execution
- hardware-level error normalization
- modem-level send response

Python must **not**:
- own business logic
- own tenant policy
- own retry policy
- own queue truth
- own migration policy
- own sticky assignment policy

---

### Redis = Coordination / Queue Transport Layer
Redis is used for:
- per-SIM queue transport
- queue coordination
- rebuild locks
- fast worker consumption

Redis must **not** become:
- source of truth
- audit log
- retry truth
- reporting truth
- tenant truth

---

### MySQL = Source of Truth
MySQL remains the authoritative source of truth for:
- outbound messages
- inbound messages
- assignments
- SIM state history
- retry schedule truth
- tenant data
- operator state
- monitoring data

If Redis and MySQL disagree:
- MySQL wins

---

## MULTI-TENANT RULES

Tenant identity must always come from **authenticated context**.

### Must do
- resolve company/tenant from auth middleware / tenant context
- use authenticated company id internally

### Must not do
- trust `company_id` from request body
- let request payload override tenant context
- allow cross-tenant SIM or message access

Any API implementation that trusts request-body `company_id` is wrong.

---

## SIM-CENTRIC DESIGN RULE

The system is **SIM-centric**, not modem-centric.

Rules:
- one company can have multiple SIMs
- one modem = one SIM only
- queueing is per SIM
- workers are per SIM
- monitoring is per SIM
- retry is per SIM
- migration is per SIM
- customer stickiness is per SIM

AI assistants must not redesign this into:
- modem-group queueing
- company-wide single-worker queueing
- global cross-SIM balancing
- automatic cross-SIM rescue

---

## STICKY ASSIGNMENT RULES

Sticky customer assignment is mandatory.

Once a customer has SIM history, future messages must continue to use that same SIM.

History may include:
- past outbound
- successful outbound
- inbound/outbound conversation history
- active assignment row

AI assistants must not introduce:
- silent reassignment
- automatic reassignment because another SIM is healthier
- automatic load balancing for already-sticky customers

Only manual migration may move a sticky customer.

---

## NEW CUSTOMER ASSIGNMENT RULES

When customer has no sticky assignment:
- select SIM with fewer queued messages right now

New SIMs must not automatically receive new customer assignments unless explicitly enabled.

Required controls:
- `accept_new_assignments`
- `disabled_for_new_assignments`

AI assistants must not:
- auto-enable newly added SIMs by default
- use historical total customers as primary balancing signal
- assign new customers to disabled SIMs

---

## QUEUE ARCHITECTURE RULES

### Final queue model
Per SIM, use 3 Redis queues:

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

### Must not
- replace this with single FIFO queue
- silently collapse all traffic into one queue
- introduce company-wide queue that blocks SIM isolation
- use global priority queue that breaks per-SIM isolation without explicit approval

---

## MESSAGE TYPE RULES

Message types are transport labels only.

They may affect:
- priority
- send timing
- queue tier

They must **not** affect:
- business decisions
- conversation logic
- AI interpretation
- customer segmentation logic inside gateway

---

## OPERATOR STATUS RULES

Each SIM has operator-controlled delivery state:

- `active`
- `paused`
- `blocked`

These are explicit operator controls and must be respected.

### active
- accept intake
- save to DB
- enqueue
- worker processes

### paused
- accept intake
- save to DB
- do not enqueue
- worker skips processing
- API returns accepted warning response
- when resumed, pending messages auto-requeue safely

### blocked
- reject new intake
- do not save new outbound message
- do not enqueue new work
- old already-existing queued/pending work may still drain

AI assistants must not redefine these semantics without explicit approval.

---

## RETRY RULES

Final retry policy:
- every 5 minutes
- forever
- no automatic stop
- no max-attempt auto-abandon
- no auto cross-SIM failover

Messages remain on same SIM until:
- success
- or operator manually migrates them

AI assistants must not reintroduce:
- exponential backoff
- capped retry attempts
- automatic final failure due only to retry count
- auto-migrate-on-failure logic

---

## HEALTH RULES

Health must be based on:
- `last_success_at`

Not:
- attempted send time
- dispatch time
- ambiguous `last_sent_at`

Rules:
- no success in 30 minutes = unhealthy warning condition
- check every 5 minutes
- compute stuck-age warnings:
  - 6h
  - 24h
  - 3d

If company has more than 1 SIM:
- SIM may be disabled from **new assignments only**

This must not:
- move sticky customers
- stop retries
- clear queue
- migrate traffic automatically

---

## MANUAL MIGRATION RULES

Migration is manual only.

Allowed:
- migrate one customer
- bulk migrate all customers/messages from SIM A to SIM B

Manual migration must move:
- sticky assignments
- pending messages
- future traffic automatically via new sticky assignment

### DB-first rule
Migration must:
1. update DB first
2. rebuild Redis queues from DB truth

Must not:
- directly shuffle Redis items as primary migration strategy
- treat Redis as truth during migration

---

## QUEUE REBUILD RULES

Queue rebuild is allowed for:
- paused → active resume
- manual migration
- queue repair / recovery

Rules:
- MySQL is source of truth
- clear Redis first
- rebuild from DB truth
- rebuild scope = `pending` only
- do not directly requeue `sending`
- `sending` recovery must use stale lock recovery / recovery flow

---

## REBUILD LOCK RULES

Rebuild must use a worker-visible Redis lock.

Required pattern:
- set rebuild lock before queue clear/rebuild
- worker must check lock before any `LPOP`
- release lock in `finally`
- lock timeout must exist
- worker wait behavior must not deadlock forever

AI assistants must not assume DB row lock alone is enough unless worker explicitly respects the same lock path.

---

## FAILOVER RULES

Automatic failover is **not** the final architecture.

Do not introduce:
- automatic customer reassignment
- automatic pending-message failover
- auto cross-SIM routing rescue

Allowed:
- keep reusable old failover internals if useful
- repurpose internals for manual migration only

Any proposal that revives automatic failover must be treated as architecture drift.

---

## BLOCKED VS PAUSED RULES

AI assistants must keep these distinct:

### paused
- save new message
- do not enqueue
- warning response
- worker stops for that SIM

### blocked
- reject new intake
- no DB save for new intake
- no new enqueue
- old queued/pending work may continue draining

Do not collapse these into one concept.

---

## API RESPONSE RULES

### Active SIM
- normal success response
- queued = true

### Paused SIM
- accepted warning response
- queued = false
- message saved

### Blocked SIM
- immediate error response
- no DB save
- no queue push

Do not return misleading “queued” success for paused or blocked states.

---

## PYTHON EXECUTION RULES

Python modem engine must:
- expose `/send`
- expose discovery / health endpoints as needed
- normalize hardware errors
- return structured result
- avoid full discovery scan during send path

Python must not:
- become queue owner
- implement business retry policy
- decide tenant routing
- own migration logic

---

## DOCUMENTATION LOCK RULE

When implementation changes a locked system behavior, documentation must be updated.

At minimum, any architecture-affecting change must be reflected in:

- `SYSTEM.md`
- `DECISIONS.md`
- `ROADMAP.md`
- `TASKS.md`
- `CHANGELOG.md`
- `AI_RULES.md`

No major implementation should proceed while these docs are knowingly out of sync.

---

## IMPLEMENTATION SAFETY RULE

AI-generated code must prefer:
- minimal-risk changes
- preserving existing architecture
- explicit boundaries
- DB truth over cache truth
- operator control over hidden automation

AI-generated code must avoid:
- hidden redesigns
- “smart” auto behavior not explicitly approved
- mixing control logic into Python
- mixing hardware logic into Laravel
- introducing new queue semantics without doc alignment

---

## IF UNSURE, DEFAULT TO THESE PRINCIPLES

1. Laravel controls  
2. Python executes  
3. MySQL is truth  
4. Redis transports  
5. SIM is the unit  
6. sticky assignment stays  
7. migration is manual  
8. retries stay on same SIM  
9. tenant comes from auth  
10. gateway remains transport-only