# SMS GATEWAY CORE – DECISIONS LOG

Last Updated: 2026-04-14

---

## Decision: Laravel as Control Layer

Date: 2026-03-25  
Updated: 2026-03-29

Rule:
- Laravel is the control layer
- Laravel owns queueing, SIM assignment, retry scheduling, migration, monitoring, and tenant isolation

Reason:
- Keeps business/control logic in one place
- Matches existing multi-tenant architecture
- Keeps hardware communication outside Laravel

Impact:
- Laravel remains the source of truth for orchestration
- Laravel owns message lifecycle and operator controls
- Laravel must not communicate with modem hardware directly

---

## Decision: Python as SMS Execution Layer

Date: 2026-03-25  
Updated: 2026-03-29

Rule:
- All modem communication is handled by a Python service
- Laravel Gateway communicates with Python via HTTP only

Reason:
- Python is better suited for hardware/serial communication
- Avoid blocking IO in Laravel
- Preserve strong control vs execution boundary

Impact:
- Requires Python API service
- Adds network call between Gateway and modem layer
- Improves scalability and system stability

---

## Decision: FastAPI as Web Framework

Date: 2026-03-25

Rule:
- Use FastAPI for HTTP service

Reason:
- Lightweight and fast
- Easy async support
- Clean request/response validation

Impact:
- Simple API layer
- Easy local and production deployment via uvicorn

---

## Decision: pyserial for Modem Communication

Date: 2026-03-25

Rule:
- Use pyserial for USB modem communication

Reason:
- Standard library for serial communication
- Stable and widely supported
- Works directly with ttyUSB devices

Impact:
- Direct control over AT commands
- Requires careful timeout and error handling

---

## Decision: Remove Static SIM Port Mapping as Primary Design

Date: 2026-03-29

Rule:
- Do not rely on static `sim_map.json` as the main modem routing model
- Python must use modem discovery / registry as the operational routing layer

Reason:
- Static ttyUSB mapping is fragile
- Port ordering can change
- Manual config mapping does not scale well for many modems

Impact:
- Python modem engine must maintain modem registry logic
- Discovery and stable SIM identity become core execution-layer concerns
- Static mapping may still exist as emergency fallback, but not as primary architecture

---

## Decision: Stable SIM-Centric Identity

Date: 2026-03-29

Rule:
- Message routing must be SIM-centric, not modem-path-centric
- SIM identity must remain stable even if ttyUSB ordering changes

Reason:
- Queueing, sticky assignment, monitoring, and manual migration are all SIM-based
- Modem port path is operational detail, not business identity

Impact:
- Gateway logic remains SIM-centric
- Python must resolve SIM identity to actual modem port safely
- Avoid brittle `hash(port)` / static port-only identity models

---

## Decision: Stateless Execution Layer

Date: 2026-03-25  
Updated: 2026-03-29

Rule:
- Python service must not own business state
- Python should not own queue state, tenant logic, retry policy, or business routing policy

Reason:
- Keep execution layer simple
- Avoid state duplication with Laravel/MySQL
- Prevent drift between control truth and execution truth

Impact:
- No business database ownership in Python
- Laravel/MySQL remains source of truth
- Python may keep short-lived operational registry/cache for modem execution only

---

## Decision: Retry Ownership Belongs to Gateway

Date: 2026-03-25  
Updated: 2026-03-29

Rule:
- Retry scheduling belongs to Laravel Gateway
- Python must not own business retry policy

Reason:
- Retry policy is a control-layer concern
- Prevent duplicate SMS caused by hidden retry ownership in Python
- Keeps all delivery-state policy centralized

Impact:
- Python returns structured send result
- Laravel schedules retries
- Gateway controls infinite 5-minute retry policy

Note:
- Python may still do hardware-safe execution behavior (such as hardware fallback or execution safety), but not business retry ownership

---

## Decision: Standardized Response Format

Date: 2026-03-25  
Updated: 2026-03-29

Rule:
- Python responses must follow consistent structure:
  - success
  - message_id
  - error
  - raw

Reason:
- Align with Laravel `SmsSendResult`
- Simplify integration
- Enable consistent error handling

Impact:
- No raw exceptions exposed directly to Laravel
- Errors remain normalized
- Response contract stays stable across execution-layer changes

---

## Decision: Short-Lived Modem Connections

Date: 2026-03-25

Rule:
- Open and close serial connection per request

Reason:
- Simpler implementation
- Avoid stale or locked ports
- Easier recovery from modem errors

Impact:
- Slight overhead per send
- More stable for initial and medium-scale versions

---

## Decision: No Direct Hardware Access from Laravel

Date: 2026-03-25

Rule:
- Laravel must not access ttyUSB devices or AT commands directly

Reason:
- Enforce architecture boundary
- Prevent blocking issues
- Keep Gateway portable and scalable

Impact:
- All modem communication goes through Python API
- Strong separation between layers remains enforced

---

## Decision: Execution Layer Only

Date: 2026-03-25  
Updated: 2026-03-29

Rule:
- Python service is strictly execution-only

It must not:
- contain business logic
- handle tenant policy
- own queue policy
- own retry policy
- manage conversations
- process AI or automation

Reason:
- Maintain clean architecture
- Avoid duplication of logic
- Keep system maintainable

Impact:
- Gateway remains control layer
- Python remains hardware execution layer

---

## Decision: Per-SIM Queue Architecture

Date: 2026-03-29

Rule:
- Queueing must be per SIM
- One SIM must not block another SIM

Reason:
- Business model is SIM-centric
- 1 modem = 1 SIM in this system
- Each SIM must act as an isolated delivery lane

Impact:
- Workers are per SIM
- Queue state is per SIM
- Monitoring is per SIM
- Migration is per SIM

---

## Decision: 3 Redis Queues Per SIM

Date: 2026-03-29

Rule:
- Each SIM uses 3 Redis queues:
  - chat
  - followup
  - blasting

Queue priority order:
1. chat
2. followup
3. blasting

Mapping:
- CHAT → chat
- AUTO_REPLY → chat
- FOLLOW_UP → followup
- BLAST → blasting

Reason:
- Matches business priority directly
- Easier to inspect and debug than sorted sets
- Keeps Redis behavior simple and operationally clear

Impact:
- Worker checks 3 queues in order
- No single FIFO queue per SIM
- No Redis sorted-set priority needed initially

---

## Decision: MySQL is Source of Truth, Redis is Transport Only

Date: 2026-03-29

Rule:
- MySQL is the authoritative source of truth
- Redis is queue transport / coordination only

Reason:
- Prevent queue truth drift
- Keep audit and reporting in DB
- Safer rebuild and migration behavior

Impact:
- Queue rebuilds must come from DB
- Redis items should be treated as disposable transport state
- Migration and resume logic must be DB-first

---

## Decision: DB-First Queue Rebuild

Date: 2026-03-29

Rule:
- During migration or paused→active resume:
  1. DB remains truth
  2. clear affected Redis queues
  3. rebuild Redis from DB truth

Reason:
- Safer than direct Redis shuffling
- Easier to recover from partial failure
- Avoids duplicate operational state

Impact:
- Queue rebuild service is required
- Resume / migration logic must respect rebuild locks
- Operational safety increases

---

## Decision: Pending-Only Safe Rebuild Scope

Date: 2026-03-29

Rule:
- Rebuild Redis queues using only `status = pending`

Reason:
- `sending` messages may already be in-flight
- Requeuing `sending` risks duplicate delivery
- Safer to recover stale `sending` through dedicated recovery logic

Impact:
- Rebuild path stays conservative
- Recovery path remains separate for in-flight messages

---

## Decision: Worker-Visible Rebuild Lock

Date: 2026-03-29

Rule:
- Queue rebuild must use a worker-visible Redis rebuild lock
- Worker must check rebuild lock before attempting any queue pop

Reason:
- Prevent race conditions during queue clear/rebuild
- Prevent duplicate queue claims during paused→active or migration rebuild

Impact:
- Rebuild must set and clear Redis lock explicitly
- Worker must wait while lock exists
- Lock timeout and `finally` cleanup are required

---

## Decision: Sticky Customer Assignment

Date: 2026-03-29

Rule:
- Once customer has history on a SIM, future messages stay on that SIM

Reason:
- Preserves continuity
- Matches business and operator expectations
- Avoids invisible customer reassignment

Impact:
- Sticky assignment remains core behavior
- No automatic move across SIMs during temporary failures
- Migration must be explicit

---

## Decision: New Customer Assignment by Lowest Current Queue Load

Date: 2026-03-29

Rule:
- When customer has no sticky SIM, assign to SIM with fewer queued messages right now

Reason:
- Current load is more useful than historical customer counts
- Better reflects present delivery pressure

Impact:
- SIM selection service must use current queued load
- Historical customer totals should not dominate new assignment decisions

---

## Decision: New SIM Enablement is Manual

Date: 2026-03-29

Rule:
- New SIMs must not automatically receive new customer assignments
- Operator or super admin must explicitly enable them

Reason:
- Gives operators control over rollout/load
- Prevents accidental new-customer flow to unprepared SIMs

Impact:
- `accept_new_assignments` flag required
- Existing active SIMs should not be retroactively broken during rollout

---

## Decision: Operator Status Model

Date: 2026-03-29

Rule:
- Each SIM has operator-controlled status:
  - active
  - paused
  - blocked

Reason:
- Operators need explicit delivery controls
- Different operational states have different intake / queue semantics

Impact:
- Intake and worker behavior must respect operator status
- Dashboard and controls must surface this clearly

---

## Decision: Paused SIM Behavior

Date: 2026-03-29

Rule:
- paused SIM:
  - accepts intake
  - saves message to DB
  - does not enqueue
  - worker skips processing
  - API returns accepted warning

Reason:
- Allows maintenance / temporary pause without data loss
- Preserves message history and future resume path

Impact:
- paused→active must auto-requeue pending messages safely
- API should return warning-style accepted response

---

## Decision: Blocked SIM Behavior

Date: 2026-03-29

Rule:
- blocked SIM:
  - rejects new intake
  - does not save new outbound message
  - does not enqueue new work
  - worker may continue draining old already-existing queued work

Reason:
- Hard stop for new intake
- Avoid orphaning old work already in system

Impact:
- Intake returns immediate error
- Old queue drain may continue
- blocked is intake-gate behavior, not full historical drain-stop

---

## Decision: Retry Every 5 Minutes Forever

Date: 2026-03-29

Rule:
- Retry every 5 minutes
- Retry forever
- Do not auto-stop

Reason:
- No message should be lost
- Temporary SIM problems (no load, no signal, etc.) should self-recover when issue is resolved

Impact:
- Retry service becomes fixed-interval
- No max attempts
- Dashboard must surface stuck-age visibility for operators

---

## Decision: Health Based on Last Real Successful Send

Date: 2026-03-29

Rule:
- Health must be based on `last_success_at`

Reason:
- Success is the real operational signal
- Avoid ambiguity of `last_sent_at`

Impact:
- Track `last_success_at`
- Use it for 30-minute health checks and stuck-age warnings

---

## Decision: Auto-Disable for New Assignments After 30 Minutes Without Success

Date: 2026-03-29

Rule:
- If no success in 30 minutes, disable SIM from **new assignments only**
- Apply only if company has more than 1 SIM

Reason:
- Protect new-customer assignment away from unhealthy SIMs
- Do not punish 1-SIM tenants with nowhere else to go

Impact:
- `disabled_for_new_assignments` flag required
- Does not move sticky customers
- Does not stop old queue
- Does not stop retries

---

## Decision: Manual Migration Only

Date: 2026-03-29

Rule:
- No automatic cross-SIM failover
- Migration is operator-controlled only

Reason:
- Operator wants manual control
- Temporary problems should not silently move customers
- Sticky assignment must remain predictable

Impact:
- Automatic failover orchestration must be removed
- Manual migration workflow must exist
- Reusable failover internals may be repurposed for manual migration

---

## Decision: Keep and Repurpose Failover Internals

Date: 2026-03-29

Rule:
- Keep reusable failover internals where useful
- Remove automatic failover behavior
- Repurpose core logic for manual migration

Reason:
- Avoid unnecessary rewrite
- Reuse safe existing internals

Impact:
- failover commands may be removed
- service internals can be retained / renamed / reused

---

## Decision: Tenant Identity From Auth Context Only

Date: 2026-03-29

Rule:
- tenant/company identity must always come from auth / middleware / tenant context
- never trust `company_id` from request body

Reason:
- Multi-tenant safety
- Prevent request tampering and cross-tenant misuse

Impact:
- request body company_id must be ignored
- controller logic must resolve company via authenticated context only

---

## Decision: Inbound Runtime Identity Is Laravel-Resolved (Python Remains Generic)

Date: 2026-04-13

Rule:
- Python inbound payloads should remain runtime-native and transport-focused.
- Laravel owns mapping from runtime SIM identity (IMSI/runtime_sim_id/device identity) to tenant `sims.id`.
- Python must not be customized per-tenant for DB SIM ID translation logic.

Reason:
- Preserves clean control/execution boundary.
- Keeps Python engine reusable as a standalone transport component.
- Avoids tenant/business mapping drift outside Laravel.

Impact:
- Inbound contract should support runtime identity fields (`runtime_sim_id` / `imsi`) and Laravel-side resolution.
- Laravel remains authoritative for tenant-safe SIM mapping.
- Current strict `sim_id` integer-only paths should be evolved toward Laravel-resolved runtime identity handling.

---

## Decision: Inbound Reliability Uses ACK-Gated Delete + Durable Spool + Idempotency

Date: 2026-04-13

Rule:
- Do not delete inbound SMS from SIM storage until delivery is safely acknowledged.
- Preferred safe paths:
  - delete after successful Laravel ACK, or
  - write to local durable spool first, then delete from SIM, then retry-deliver from spool.
- Inbound delivery retries must include idempotency keys.

Reason:
- Prevent inbound message loss during Laravel/network outages.
- Prevent duplicate inbound DB rows under retry conditions.
- Support high-volume inbound reliability.

Impact:
- Python inbound listener requires durable local buffering and retry/backoff behavior.
- Laravel inbound ingest should enforce idempotent writes (same key => safe duplicate handling).
- Laravel DB remains the long-term system of record; spool is temporary reliability buffer only.

---

## Decision: Keep Telco/System Inbound Messages (No Blanket Sender Filtering)

Date: 2026-04-14

Rule:
- Do not apply blanket sender filtering for inbound messages (for example, dropping `8080` or carrier/service senders by default).
- Preserve these messages in Laravel inbound storage as system truth/events.

Reason:
- Carrier/system SMS can contain operationally relevant notices (load expiry, balance/billing, service advisories).
- Blanket filtering risks discarding useful operational and audit context.

Impact:
- Inbound pipeline stores both customer and telco/system messages by default.
- Product/UI may classify or present categories differently, but transport/storage layer should not silently discard these messages.
- Sender filtering, if ever introduced, must be explicit policy with tenant/operator visibility and auditability.

---

## ARCHITECTURE PRINCIPLE

Gateway = Control Plane  
Python SMS Engine = Execution Plane  
Chat App = Intelligence Plane
