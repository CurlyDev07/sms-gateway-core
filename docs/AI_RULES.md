# AI DEVELOPMENT RULES

---

## CORE RULE

No task is complete unless documentation is updated.

---

## REQUIRED AFTER EVERY TASK

1. Update CHANGELOG.md  
2. Update ROADMAP.md  
3. If architecture changed → update SYSTEM.md  
4. If decision made → update DECISIONS.md  

---

## CODE RULES

- Follow SYSTEM.md architecture strictly  
- Do NOT break existing structure  
- Do NOT introduce logic outside gateway responsibility  

---

## ARCHITECTURE BOUNDARY (CRITICAL)

This project is a SMS Gateway (transport layer only).

---

### DO NOT IMPLEMENT:

- AI logic  
- RAG or memory systems  
- automation rules  
- conversation management  
- business decision logic  

---

### ALLOWED LOGIC ONLY:

- message transport (send / receive)  
- queue processing  
- SIM assignment  
- rate limiting (burst / cooldown)  
- retry and failover  
- inbound relay to Chat App  

---

## MESSAGE TYPE RULE

- message_type (CHAT, AUTO_REPLY, FOLLOW_UP, BLAST) are labels only  
- Used only for:
  - priority
  - scheduling behavior  

Gateway must NOT:
- interpret message meaning  
- apply business rules based on type  

---

## OUTPUT FORMAT (MANDATORY)

After every task, output:

1. Modified files list  
2. Code changes summary  
3. Documentation updates summary  

---

## SELF CHECK BEFORE FINISHING

- Is CHANGELOG updated?  
- Is ROADMAP updated?  
- Is SYSTEM still accurate?  
- Did I introduce any non-gateway logic?  
- Was a decision made?  

If YES → update DECISIONS.md  

---

## FINAL RULE

If unsure:

> DO NOT add intelligence into the gateway.  
> Keep it as a pure transport system.