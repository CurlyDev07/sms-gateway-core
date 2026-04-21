<?php

namespace App\Services;

use App\Models\GatewaySetting;
use Illuminate\Support\Facades\Cache;

class GatewaySettingService
{
    private const CACHE_KEY_VALUES = 'gateway:settings:values:v1';
    private const CACHE_TTL_SECONDS = 10;

    /**
     * @return array<string,array<string,mixed>>
     */
    public function definitions(): array
    {
        return [
            'outbound_retry_base_delay_seconds' => [
                'label' => 'Outbound Retry Base Delay (seconds)',
                'type' => 'int',
                'min' => 1,
                'max' => 3600,
                'default' => (int) config('services.gateway.outbound_retry_base_delay_seconds', 10),
                'description' => 'Delay before retrying one failed outbound SMS.',
                'hint' => 'Used by outbound retry flow after a send attempt fails. Lower value retries faster, higher value gives modem/network more recovery time.',
                'scenario' => 'Scenario: ChatApp sends "Hello". Gateway tries to send via SIM 251, but runtime is temporarily unreachable. Row is moved back to pending and scheduled using this delay. If set to 10, retry happens about 10 seconds later. If set to 60, retry happens about 1 minute later.',
            ],
            'outbound_retry_all_failures' => [
                'label' => 'Retry All Outbound Failures',
                'type' => 'bool',
                'default' => (bool) config('services.gateway.outbound_retry_all_failures', true),
                'description' => 'Force all outbound failures to be retried automatically.',
                'hint' => 'When true, even failures that are normally non-retryable (for example carrier/network-layer rejections) are returned to retry flow.',
                'scenario' => 'Scenario: SIM 252 returns SEND_FAILED / CMS error while sending to customer phone. With this enabled, message does not stop at failed; it is rescheduled and retried until sent or manually managed.',
            ],
            'outbound_stale_lock_seconds' => [
                'label' => 'Outbound Stale Lock Timeout (seconds)',
                'type' => 'int',
                'min' => 30,
                'max' => 3600,
                'default' => (int) config('services.gateway.outbound_stale_lock_seconds', 300),
                'description' => 'Timeout to recover stuck outbound rows in sending state.',
                'hint' => 'If one SMS stays status=sending with locked_at too long, gateway assumes worker/runtime died mid-send and auto-recovers it.',
                'scenario' => 'Scenario: 10:00:00 message #900 set to sending and locked. At 10:00:05 python runtime crashes before success/fail response. Row is stuck. If timeout is 300, at about 10:05:00 stale-lock recovery changes row back to pending, clears lock, and schedules retry so message can continue delivery pipeline.',
            ],
            'infotxt_send_rate_limit_per_minute' => [
                'label' => 'InfoText Send Rate Limit (per minute)',
                'type' => 'int',
                'min' => 1,
                'max' => 100000,
                'default' => 1000,
                'description' => 'Rate limit for ChatApp fast-path send endpoint (/api/v2/send.php).',
                'hint' => 'Higher value allows larger bursts per tenant (UserID) before HTTP 429. Lower value adds stricter intake protection.',
                'scenario' => 'Scenario: Tenant A blasts 900 requests in one minute. If limit is 1000, requests are accepted and queued. If limit is 600, roughly 300 may be throttled (429) and ChatApp may record failed rows with smsid=null for those rejected attempts.',
            ],
            'inbound_relay_retry_max_attempts' => [
                'label' => 'Inbound Relay Max Attempts',
                'type' => 'int',
                'min' => 1,
                'max' => 100,
                'default' => (int) config('services.gateway.inbound_relay_retry_max_attempts', 3),
                'description' => 'Maximum attempts for Gateway to relay one inbound SMS to ChatApp.',
                'hint' => 'After this limit, inbound relay row is marked failed and waits for manual retry-all action.',
                'scenario' => 'Scenario: Customer replies to SIM 250. Gateway stores inbound row, then tries POST to ChatApp /api/infotxt/inbox. ChatApp returns HTTP 500 repeatedly. If max attempts is 3, gateway tries 3 times then marks relay_status=failed.',
            ],
            'inbound_relay_retry_base_delay_seconds' => [
                'label' => 'Inbound Relay Base Delay (seconds)',
                'type' => 'int',
                'min' => 1,
                'max' => 3600,
                'default' => (int) config('services.gateway.inbound_relay_retry_base_delay_seconds', 30),
                'description' => 'Base retry delay for inbound relay failures.',
                'hint' => 'Inbound relay uses exponential backoff. Attempt 1 uses this base delay, later attempts increase delay.',
                'scenario' => 'Scenario: Customer SMS is received by python and stored in gateway, but ChatApp endpoint is temporarily down. If base delay is 30, next relay retry starts around +30s, then grows on later attempts.',
            ],
            'inbound_relay_retry_max_delay_seconds' => [
                'label' => 'Inbound Relay Max Delay (seconds)',
                'type' => 'int',
                'min' => 1,
                'max' => 86400,
                'default' => (int) config('services.gateway.inbound_relay_retry_max_delay_seconds', 300),
                'description' => 'Maximum cap for inbound relay retry delay.',
                'hint' => 'Prevents exponential backoff from becoming too long while ChatApp is unavailable.',
                'scenario' => 'Scenario: ChatApp stays down for long period. Retries back off but never exceed this value. With max=300, no single retry gap will exceed 5 minutes.',
            ],
            'inbound_relay_lock_seconds' => [
                'label' => 'Inbound Relay Lock Seconds',
                'type' => 'int',
                'min' => 10,
                'max' => 3600,
                'default' => (int) config('services.gateway.inbound_relay_lock_seconds', 120),
                'description' => 'Lock duration for one inbound relay row while being processed.',
                'hint' => 'Prevents duplicate workers from relaying same inbound message at the same time.',
                'scenario' => 'Scenario: Scheduler and manual retry command run close together. Lock ensures only one process handles inbound row #123 now; second process skips until lock expires.',
            ],
            'runtime_failure_window_minutes' => [
                'label' => 'Runtime Failure Window (minutes)',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => (int) config('services.gateway.runtime_failure_window_minutes', 15),
                'description' => 'Lookback window to count runtime failures for cooldown decision.',
                'hint' => 'GATEWAY_RUNTIME_FAILURE_WINDOW_MINUTES: time window to count failures.',
                'scenario' => 'Scenario: For SIM 252, gateway checks errors in last N minutes. If window is 15, only failures inside the last 15 minutes are counted for suppression trigger.',
            ],
            'runtime_failure_threshold' => [
                'label' => 'Runtime Failure Threshold',
                'type' => 'int',
                'min' => 1,
                'max' => 1000,
                'default' => (int) config('services.gateway.runtime_failure_threshold', 3),
                'description' => 'Failure count required to trigger SIM runtime cooldown.',
                'hint' => 'GATEWAY_RUNTIME_FAILURE_THRESHOLD: number of failures needed to trigger cooldown.',
                'scenario' => 'Scenario: Threshold=3. If one SIM gets 3 runtime failures within the active window, gateway switches that SIM to cooldown mode temporarily.',
            ],
            'runtime_suppression_minutes' => [
                'label' => 'Runtime Cooldown Duration (minutes)',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => (int) config('services.gateway.runtime_suppression_minutes', 15),
                'description' => 'How long SIM remains in cooldown after threshold is reached.',
                'hint' => 'GATEWAY_RUNTIME_SUPPRESSION_MINUTES: cooldown duration.',
                'scenario' => 'Scenario: SIM 252 hits failure threshold at 14:00. If suppression is 15, SIM stays cooldown until around 14:15 and is deprioritized for new assignment during that period.',
            ],
            'sim_selection_hysteresis_hold_seconds' => [
                'label' => 'SIM Selection Hold (seconds)',
                'type' => 'int',
                'min' => 30,
                'max' => 3600,
                'default' => (int) config('services.gateway.sim_selection_hysteresis_hold_seconds', 300),
                'description' => 'Temporary hold duration to reduce SIM flapping in new assignment.',
                'hint' => 'If SIM looks unstable/overloaded, it is held for this many seconds before being considered again.',
                'scenario' => 'Scenario: Non-sticky new outbound assignment is choosing between SIM 250 and 251. SIM 250 just crossed load/failure pressure. Gateway puts SIM 250 on hold for 300s and routes new assignments to 251 first.',
            ],
            'sim_selection_failure_window_minutes' => [
                'label' => 'Selection Failure Window (minutes)',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => (int) config('services.gateway.sim_selection_failure_window_minutes', 15),
                'description' => 'Lookback window for failure-based SIM selection pressure.',
                'hint' => 'Used by selection logic for non-sticky balancing; does not permanently disable SIM.',
                'scenario' => 'Scenario: If window is 15, only failed outbound rows in last 15 minutes affect hold decision for new assignments.',
            ],
            'sim_selection_failure_hold_threshold' => [
                'label' => 'Selection Failure Hold Threshold',
                'type' => 'int',
                'min' => 1,
                'max' => 1000,
                'default' => (int) config('services.gateway.sim_selection_failure_hold_threshold', 3),
                'description' => 'Failure count threshold that triggers temporary hold in selection.',
                'hint' => 'When recent failures reach this count inside selection window, SIM is temporarily deprioritized for new non-sticky assignments.',
                'scenario' => 'Scenario: Threshold=3. SIM 250 gets 3 failed sends quickly. Gateway holds SIM 250 for hysteresis duration and routes new traffic to other healthy SIMs.',
            ],
            'sim_selection_queue_hold_threshold' => [
                'label' => 'Selection Queue Hold Threshold',
                'type' => 'int',
                'min' => 1,
                'max' => 100000,
                'default' => (int) config('services.gateway.sim_selection_queue_hold_threshold', 100),
                'description' => 'Queue depth threshold that triggers temporary hold in selection.',
                'hint' => 'When one SIM queue becomes too deep, new non-sticky assignments are shifted to less-loaded SIMs.',
                'scenario' => 'Scenario: SIM 252 queue depth reaches 240 while SIM 250 has depth 10. Gateway sees queue threshold breach and holds SIM 252 from new non-sticky assignment until pressure drops/hold expires.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function values(): array
    {
        if (app()->environment('testing')) {
            return $this->resolveValues();
        }

        return Cache::remember(self::CACHE_KEY_VALUES, now()->addSeconds(self::CACHE_TTL_SECONDS), function () {
            return $this->resolveValues();
        });
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function valuesForOps(): array
    {
        $definitions = $this->definitions();
        $values = $this->values();
        $rows = [];

        foreach ($definitions as $key => $definition) {
            $rows[$key] = [
                'key' => $key,
                'label' => (string) $definition['label'],
                'type' => (string) $definition['type'],
                'value' => $values[$key] ?? $definition['default'],
                'default' => $definition['default'],
                'description' => (string) $definition['description'],
                'hint' => (string) $definition['hint'],
                'scenario' => (string) ($definition['scenario'] ?? ''),
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param string $key
     * @param int $fallback
     * @return int
     */
    public function int(string $key, int $fallback): int
    {
        $values = $this->values();
        $value = $values[$key] ?? $fallback;

        return (int) $value;
    }

    /**
     * @param string $key
     * @param bool $fallback
     * @return bool
     */
    public function bool(string $key, bool $fallback): bool
    {
        $values = $this->values();
        $value = $values[$key] ?? $fallback;

        return (bool) $value;
    }

    /**
     * @param array<string,mixed> $updates
     * @return array{updated:array<string,mixed>,errors:array<string,string>}
     */
    public function updateMany(array $updates): array
    {
        $definitions = $this->definitions();
        $updated = [];
        $errors = [];

        foreach ($updates as $key => $rawValue) {
            if (!array_key_exists($key, $definitions)) {
                $errors[$key] = 'unknown_setting';
                continue;
            }

            $definition = $definitions[$key];
            $parsed = $this->parseInputValue($rawValue, $definition);

            if (!$parsed['ok']) {
                $errors[$key] = (string) $parsed['error'];
                continue;
            }

            $normalized = $parsed['value'];
            GatewaySetting::query()->updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => $this->toStorageValue($normalized, $definition)]
            );
            $updated[$key] = $normalized;
        }

        if ($updated !== []) {
            $this->flushCache();
        }

        return [
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    /**
     * @return void
     */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY_VALUES);
    }

    /**
     * @return array<string,mixed>
     */
    protected function resolveValues(): array
    {
        $definitions = $this->definitions();
        $stored = GatewaySetting::query()
            ->whereIn('setting_key', array_keys($definitions))
            ->pluck('setting_value', 'setting_key')
            ->all();

        $resolved = [];
        foreach ($definitions as $key => $definition) {
            $default = $definition['default'];

            if (array_key_exists($key, $stored)) {
                $resolved[$key] = $this->normalizeValue((string) $stored[$key], $definition, $default);
                continue;
            }

            $resolved[$key] = $default;
        }

        return $resolved;
    }

    /**
     * @param string $raw
     * @param array<string,mixed> $definition
     * @param mixed $default
     * @return mixed
     */
    protected function normalizeValue(string $raw, array $definition, $default)
    {
        $type = (string) ($definition['type'] ?? 'string');

        if ($type === 'bool') {
            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        }

        if ($type === 'int') {
            $value = is_numeric($raw) ? (int) $raw : (int) $default;
            $min = isset($definition['min']) ? (int) $definition['min'] : null;
            $max = isset($definition['max']) ? (int) $definition['max'] : null;

            if ($min !== null) {
                $value = max($min, $value);
            }

            if ($max !== null) {
                $value = min($max, $value);
            }

            return $value;
        }

        return $raw;
    }

    /**
     * @param mixed $rawValue
     * @param array<string,mixed> $definition
     * @return array{ok:bool,value?:mixed,error?:string}
     */
    protected function parseInputValue($rawValue, array $definition): array
    {
        $type = (string) ($definition['type'] ?? 'string');

        if ($type === 'bool') {
            if (is_bool($rawValue)) {
                return ['ok' => true, 'value' => $rawValue];
            }

            $normalized = strtolower(trim((string) $rawValue));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return ['ok' => true, 'value' => true];
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return ['ok' => true, 'value' => false];
            }

            return ['ok' => false, 'error' => 'invalid_boolean'];
        }

        if ($type === 'int') {
            if (!is_numeric($rawValue)) {
                return ['ok' => false, 'error' => 'invalid_integer'];
            }

            $value = (int) $rawValue;
            $min = isset($definition['min']) ? (int) $definition['min'] : null;
            $max = isset($definition['max']) ? (int) $definition['max'] : null;

            if ($min !== null && $value < $min) {
                return ['ok' => false, 'error' => 'below_min_'.$min];
            }

            if ($max !== null && $value > $max) {
                return ['ok' => false, 'error' => 'above_max_'.$max];
            }

            return ['ok' => true, 'value' => $value];
        }

        return ['ok' => true, 'value' => (string) $rawValue];
    }

    /**
     * @param mixed $value
     * @param array<string,mixed> $definition
     * @return string
     */
    protected function toStorageValue($value, array $definition): string
    {
        $type = (string) ($definition['type'] ?? 'string');

        if ($type === 'bool') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
