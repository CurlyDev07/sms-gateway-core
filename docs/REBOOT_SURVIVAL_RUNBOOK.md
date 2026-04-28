# Reboot Survival Runbook (Gateway + ChatApp)

Last Updated: 2026-04-22

Purpose:
- Ensure a host reboot does not leave SMS services down.
- Ensure queued SMS resumes automatically after reboot.
- Provide a single command check to confirm reboot health.

## What "Survive Reboot" Means

After reboot:
- Gateway containers auto-start:
  - `sms-app`
  - `sms-worker`
  - `sms-scheduler`
  - `sms-sim-supervisor`
  - `sms-db`
  - `sms-redis`
- ChatApp containers auto-start:
  - `smschatapp_nuc_app`
  - `smschatapp_nuc_worker`
  - `smschatapp_nuc_scheduler`
  - `smschatapp_nuc_db`
- Python engine auto-starts:
  - `sms-engine` systemd service
- Gateway UI remains reachable:
  - `http://127.0.0.1:8081/ops`
- Outbound queue resumes draining automatically:
  - workers continue processing `queued/pending` rows
  - stale locks are recovered by scheduler flow

## One-Time Hardening

Run once on host:

```bash
docker update --restart unless-stopped sms-app sms-worker sms-scheduler sms-sim-supervisor sms-db sms-redis
docker update --restart always smschatapp_nuc_app smschatapp_nuc_worker smschatapp_nuc_scheduler smschatapp_nuc_db

sudo systemctl enable docker
sudo systemctl enable sms-engine
```

Verify:

```bash
docker inspect -f '{{.Name}} restart={{.HostConfig.RestartPolicy.Name}}' \
  sms-app sms-worker sms-scheduler sms-sim-supervisor sms-db sms-redis \
  smschatapp_nuc_app smschatapp_nuc_worker smschatapp_nuc_scheduler smschatapp_nuc_db

sudo systemctl is-enabled docker
sudo systemctl is-enabled sms-engine
```

## Post-Reboot Validation (Single Command)

From gateway repo:

```bash
cd ~/Documents/WebDev/sms-gateway-core
./scripts/ops/reboot_health_check.sh
```

Pass criteria:
- `/ops` and `/ops/data` return HTTP 200
- no stale `sending` locks
- no old `queued` rows (older than 10 minutes)
- services/container restart policies are correct
- `sms-engine` is active

## Why Queued SMS Auto-Resends After Reboot

1. Containers/services restart automatically by policy/systemd.
2. Gateway worker (`sms-worker`) resumes queue consumption.
3. Scheduler (`sms-scheduler`) resumes maintenance/retry flow.
4. Redis queue persists via `sms_redis_data` volume.
5. DB truth (`outbound_messages` status) allows recovery paths for stuck rows.

This combination makes reboot behavior "resume and continue" rather than "drop and stop".

## If Validation Fails

1. Check containers:
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose ps
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | grep -E 'sms-|smschatapp'
```

2. Check gateway logs:
```bash
docker compose exec -T sms-app sh -lc 'tail -n 200 storage/logs/laravel.log'
```

3. Check python runtime:
```bash
sudo systemctl status sms-engine --no-pager | head -n 30
sudo journalctl -u sms-engine --since "15 min ago" --no-pager | tail -n 200
```

4. Recover app services:
```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose up -d sms-app sms-worker sms-scheduler sms-sim-supervisor
docker compose exec -T sms-app php artisan optimize:clear
```

