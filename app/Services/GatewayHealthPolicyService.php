<?php

namespace App\Services;

use App\Models\GatewaySetting;

class GatewayHealthPolicyService
{
    /**
     * @var array<string,array<string,int|string>>
     */
    private const DEFINITIONS = [
        'sim_health_unhealthy_threshold_minutes' => [
            'default' => 30,
            'min' => 5,
            'max' => 1440,
        ],
        'sim_health_runtime_failure_window_minutes' => [
            'default' => 15,
            'min' => 1,
            'max' => 240,
        ],
        'sim_health_runtime_failure_threshold' => [
            'default' => 3,
            'min' => 1,
            'max' => 20,
        ],
        'sim_health_runtime_suppression_minutes' => [
            'default' => 15,
            'min' => 1,
            'max' => 240,
        ],
        'runtime_sync_disable_after_not_ready_checks' => [
            'default' => 1,
            'min' => 1,
            'max' => 10,
        ],
        'runtime_sync_enable_after_ready_checks' => [
            'default' => 1,
            'min' => 1,
            'max' => 10,
        ],
    ];

    /**
     * @var array<string,int>|null
     */
    private $resolved = null;

    /**
     * @return array<string,int>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach (self::DEFINITIONS as $key => $def) {
            $defaults[$key] = (int) $def['default'];
        }

        return $defaults;
    }

    /**
     * @return array<string,array<int|string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach (self::DEFINITIONS as $key => $def) {
            $rules[$key] = [
                'required',
                'integer',
                'min:'.$def['min'],
                'max:'.$def['max'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string,int>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $defaults = $this->defaults();
        $stored = GatewaySetting::query()
            ->whereIn('key', array_keys($defaults))
            ->pluck('value', 'key')
            ->toArray();

        $resolved = $defaults;

        foreach ($stored as $key => $value) {
            if (!array_key_exists($key, self::DEFINITIONS)) {
                continue;
            }

            $resolved[$key] = $this->normalizeIntValue($key, $value);
        }

        $this->resolved = $resolved;

        return $resolved;
    }

    /**
     * @param string $key
     * @return int
     */
    public function getInt(string $key): int
    {
        $all = $this->all();

        if (!array_key_exists($key, $all)) {
            return 0;
        }

        return (int) $all[$key];
    }

    /**
     * @param array<string,mixed> $validated
     * @return array<string,int>
     */
    public function update(array $validated): array
    {
        foreach (self::DEFINITIONS as $key => $def) {
            $value = $this->normalizeIntValue($key, $validated[$key] ?? $def['default']);

            GatewaySetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        $this->resolved = null;

        return $this->all();
    }

    /**
     * @param string $key
     * @param mixed $raw
     * @return int
     */
    private function normalizeIntValue(string $key, $raw): int
    {
        $def = self::DEFINITIONS[$key] ?? null;

        if ($def === null) {
            return (int) $raw;
        }

        $value = (int) $raw;
        $value = max((int) $def['min'], $value);
        $value = min((int) $def['max'], $value);

        return $value;
    }
}
