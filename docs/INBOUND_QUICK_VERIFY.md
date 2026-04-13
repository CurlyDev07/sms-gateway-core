# Inbound Quick Verify (60 Seconds)

Purpose:
- Confirm live inbound reply flow: modem -> Python listener -> Laravel webhook -> `inbound_messages`.

## 1) Send one real SMS reply to your SIM
- Use a known sender phone you can recognize.

## 2) Confirm Python listener events
```bash
sudo journalctl -u sms-engine -n 120 --no-pager | grep -E "INBOUND_RECEIVED|INBOUND_DELIVERED|INBOUND_ACK_FALSE|INBOUND_WEBHOOK_RESPONSE"
```

Expected:
- at least one `INBOUND_RECEIVED`
- one `INBOUND_DELIVERED` for the same key/sender

## 3) Confirm Laravel DB insert by idempotency key
```bash
cd ~/Documents/WebDev/sms-gateway-core
KEY="<paste-key-from-INBOUND_DELIVERED>"

docker compose exec -T sms-app php artisan tinker --execute="
\$m=\App\Models\InboundMessage::query()
  ->where('idempotency_key', '$KEY')
  ->first(['id','company_id','sim_id','runtime_sim_id','customer_phone','message','received_at','idempotency_key','created_at']);
dump(\$m ? \$m->toArray() : null);
"
```

Expected:
- non-null row with matching `idempotency_key`

## 4) Quick latest inbound snapshot
```bash
docker compose exec -T sms-app php artisan tinker --execute='
dump(
  \App\Models\InboundMessage::query()
    ->latest("id")->limit(10)
    ->get(["id","runtime_sim_id","customer_phone","message","idempotency_key","created_at"])
    ->toArray()
);
'
```

Notes:
- Carrier/system senders (for example `8080`) are retained by policy and may appear in this list.
- This is expected behavior and can be classified in UI/reporting later.

