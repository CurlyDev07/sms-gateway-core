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
                'description' => 'Base seconds before the gateway retries a failed outbound send.',
                'hint' => 'Lower = faster retries. Higher = less pressure on modem/carrier after failures.',
            ],
            'outbound_retry_all_failures' => [
                'label' => 'Retry All Outbound Failures',
                'type' => 'bool',
                'default' => (bool) config('services.gateway.outbound_retry_all_failures', true),
                'description' => 'When enabled, even carrier/network rejections are retried automatically.',
                'hint' => 'Use true when you want no SMS left behind and prefer queue persistence over strict fail-fast.',
            ],
            'outbound_stale_lock_seconds' => [
                'label' => 'Outbound Stale Lock Timeout (seconds)',
                'type' => 'int',
                'min' => 30,
                'max' => 3600,
                'default' => (int) config('services.gateway.outbound_stale_lock_seconds', 300),
                'description' => 'If a send stays locked too long, it is recovered and returned to retry flow.',
                'hint' => 'Protects against stuck "sending" rows when worker/runtime crashes mid-send.',
            ],
            'inbound_relay_retry_max_attempts' => [
                'label' => 'Inbound Relay Max Attempts',
                'type' => 'int',
                'min' => 1,
                'max' => 100,
                'default' => (int) config('services.gateway.inbound_relay_retry_max_attempts', 3),
                'description' => 'Maximum retry attempts for Gateway -> ChatApp inbound relay.',
                'hint' => 'After this count, relay_status becomes failed until manual retry.',
            ],
            'inbound_relay_retry_base_delay_seconds' => [
                'label' => 'Inbound Relay Base Delay (seconds)',
                'type' => 'int',
                'min' => 1,
                'max' => 3600,
                'default' => (int) config('services.gateway.inbound_relay_retry_base_delay_seconds', 30),
                'description' => 'Base delay used by exponential backoff for inbound relay retries.',
                'hint' => 'Attempt 1 uses this delay, later attempts back off up to max delay.',
            ],
            'inbound_relay_retry_max_delay_seconds' => [
                'label' => 'Inbound Relay Max Delay (seconds)',
                'type' => 'int',
                'min' => 1,
                'max' => 86400,
                'default' => (int) config('services.gateway.inbound_relay_retry_max_delay_seconds', 300),
                'description' => 'Upper cap for inbound relay retry backoff delay.',
                'hint' => 'Prevents exponential backoff from growing indefinitely.',
            ],
            'inbound_relay_lock_seconds' => [
                'label' => 'Inbound Relay Lock Seconds',
                'type' => 'int',
                'min' => 10,
                'max' => 3600,
                'default' => (int) config('services.gateway.inbound_relay_lock_seconds', 120),
                'description' => 'Lock TTL while one worker handles one inbound relay row.',
                'hint' => 'Avoids duplicate processing on the same inbound message.',
            ],
            'runtime_failure_window_minutes' => [
                'label' => 'Runtime Failure Window (minutes)',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => (int) config('services.gateway.runtime_failure_window_minutes', 15),
                'description' => 'Time window used to count runtime failures per SIM.',
                'hint' => 'GATEWAY_RUNTIME_FAILURE_WINDOW_MINUTES = time window to count failures.',
            ],
            'runtime_failure_threshold' => [
                'label' => 'Runtime Failure Threshold',
                'type' => 'int',
                'min' => 1,
                'max' => 1000,
                'default' => (int) config('services.gateway.runtime_failure_threshold', 3),
                'description' => 'Number of runtime failures required before SIM cooldown is triggered.',
                'hint' => 'GATEWAY_RUNTIME_FAILURE_THRESHOLD = number of failures needed to trigger cooldown.',
            ],
            'runtime_suppression_minutes' => [
                'label' => 'Runtime Cooldown Duration (minutes)',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => (int) config('services.gateway.runtime_suppression_minutes', 15),
                'description' => 'How long SIM stays in cooldown after threshold breach.',
                'hint' => 'GATEWAY_RUNTIME_SUPPRESSION_MINUTES = cooldown duration.',
            ],
            'sim_selection_hysteresis_hold_seconds' => [
                'label' => 'SIM Selection Hold (seconds)',
                'type' => 'int',
                'min' => 30,
                'max' => 3600,
                'default' => (int) config('services.gateway.sim_selection_hysteresis_hold_seconds', 300),
                'description' => 'Temporary hold duration to reduce rapid SIM flapping on new assignments.',
                'hint' => 'A held SIM is deprioritized briefly, then re-enters candidate pool automatically.',
            ],
            'sim_selection_failure_window_minutes' => [
                'label' => 'Selection Failure Window (minutes)',
                'type' => 'int',
                'min' => 1,
                'max' => 1440,
                'default' => (int) config('services.gateway.sim_selection_failure_window_minutes', 15),
                'description' => 'Window used to count recent failed outbound rows for SIM selection pressure.',
                'hint' => 'Used only for non-sticky assignment balancing, not hard disabling.',
            ],
            'sim_selection_failure_hold_threshold' => [
                'label' => 'Selection Failure Hold Threshold',
                'type' => 'int',
                'min' => 1,
                'max' => 1000,
                'default' => (int) config('services.gateway.sim_selection_failure_hold_threshold', 3),
                'description' => 'Recent failure count threshold that triggers temporary selection hold.',
                'hint' => 'If failures hit this value inside the failure window, SIM is temporarily deprioritized.',
            ],
            'sim_selection_queue_hold_threshold' => [
                'label' => 'Selection Queue Hold Threshold',
                'type' => 'int',
                'min' => 1,
                'max' => 100000,
                'default' => (int) config('services.gateway.sim_selection_queue_hold_threshold', 100),
                'description' => 'Queue depth threshold that triggers temporary selection hold.',
                'hint' => 'Protects overloaded SIMs by pushing new non-sticky assignments to less-loaded SIMs.',
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
