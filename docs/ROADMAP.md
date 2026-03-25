# ROADMAP

---

## CURRENT PHASE
Phase 2 – Multi-SIM + Stability

---

## DONE
- SMS webhook design
- Message storage schema
- System architecture (v3.1)
- Task 001: Database + Models (companies, modems, sims, assignments, outbound/inbound, stats, health logs, api clients)
- Task 002: SIM assignment (sticky customer-to-SIM mapping with reassignment rules)
- Task 003: Single SIM worker loop (claim, send, update status, burst/cooldown timing, daily stats)
- Task 004: Formal SIM state engine (mode transitions, cooldown entry/exit, rate-limit checks, centralized sleep timing)
- Task 005: Inbound message handling + relay to Chat App webhook
- Task 006A: Retry + recovery reliability foundation (outbound retry backoff, inbound relay retry backoff, stale lock recovery commands/services)
- Task 007: Failover + SIM reassignment (company-isolated replacement selection, pending message reassignment, sticky assignment failover commands)
- Task 009: Multi-SIM support core implementation (selection, availability checks, failover-ready flow)
- Task 011: Multi-tenant security layer (API client authentication, tenant resolution middleware, tenant-isolated route protection)
- Task 011 hardening: hashed API secret verification and global tenant container context
- SMS sender abstraction layer (driver-based transport adapter: python API + queue bridge)
- SMS sender hardening and observability improvements
  (full trace metadata, provider-status validation, structured transport logs)
- Queue processing and prioritization system
  (single outbound queue, message type priority, scheduled handling)
- Concurrency safety and reliability hardening
  (row locking, `SKIP LOCKED`, stale lock recovery, retry correctness)
- Inbound pipeline hardening
  (duplicate guard, async relay dispatch, `received_at` dedupe)

Phase 1 Status:
- Complete

---

## IN PROGRESS
- Reliability hardening rollout (operational scheduling of recovery/retry commands)
- Multi-SIM failover operational tuning and monitoring
- Command scheduling/ops runbooks for retry/recovery/failover operations

---

## NEXT

### Phase 2 (Multi-SIM + Stability)

#### SMS Execution Layer (Python Modem Engine)  ← ✅ ADDED
- Python API server (/send endpoint)
- SIM → ttyUSB mapping system
- AT command SMS sending (AT+CMGS)
- Multi-modem support (USB hub)
- Modem health check (AT ping)
- Error handling (timeout, SIM failure, signal issues)

#### Advanced failover + routing
- Advanced failover routing policies
- Operational monitoring for multi-SIM balancing outcomes

---

### Phase 3 (Monitoring + Control)
- SIM health monitoring
- Daily stats tracking
- Admin APIs (SIM status, assignment, rebalance)
- Logging and error tracking

---

## FUTURE

### Phase 4 (Scaling Infrastructure)
- Multi-modem orchestration
- Load balancing across SIMs
- Distributed workers
- Queue optimization

---

### Phase 5 (Operational Tools)
- Dashboard (gateway monitoring only)
- Alerting system (SIM failure, errors)
- Performance analytics (send rate, failure rate)

---

## NOTE

This roadmap is for SMS Gateway only.

All AI, RAG, automation, SaaS, and chat logic are handled in the Chat App (separate project).

---

## ARCHITECTURE REMINDER  ← ✅ ADDED

Gateway = Control Layer  
Python SMS Engine = Execution Layer  
Chat App = Intelligence Layer