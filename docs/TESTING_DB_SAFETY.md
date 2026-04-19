# Testing DB Safety

Last Updated: 2026-04-19

## Why this exists

`RefreshDatabase` tests can run migrations/reset on whatever DB your test process points to.
If tests point to runtime DB, data loss can happen.

## Enforced protections

1. `phpunit.xml` forces:
   - `APP_ENV=testing`
   - `DB_CONNECTION=sqlite`
   - `DB_DATABASE=:memory:`
2. `tests/TestCase.php` hard-fails test bootstrap when:
   - `APP_ENV != testing`
   - non-sqlite DB does not contain `test` in name
   - DB name is `sms_gateway_core`
3. `.env.testing.example` provides a safe baseline config.

## Safe test command

```bash
cd ~/Documents/WebDev/sms-gateway-core
docker compose exec -T sms-app php artisan test
```

## Never do this

- Do not run tests with runtime DB values such as:
  - `DB_DATABASE=sms_gateway_core`

