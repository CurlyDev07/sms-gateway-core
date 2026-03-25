<?php

namespace App\Services;

use App\Models\Sim;
use App\Models\SimDailyStat;
use Illuminate\Support\Facades\Log;

class SimStateService
{
    private const IDLE_SLEEP_SECONDS = 2;
    private const COOLDOWN_SLEEP_SECONDS = 5;
    private const INACTIVE_SLEEP_SECONDS = 5;
    private const DAILY_LIMIT_SLEEP_SECONDS = 30;

    /**
     * Check if SIM can currently send messages.
     *
     * @param \App\Models\Sim $sim
     * @return bool
     */
    public function canSend(Sim $sim): bool
    {
        if (!$sim->isActive()) {
            return false;
        }

        if ($this->isInCooldown($sim)) {
            Log::info('SIM send blocked: cooldown active', [
                'sim_id' => $sim->id,
                'cooldown_until' => optional($sim->cooldown_until)->toDateTimeString(),
            ]);

            return false;
        }

        if ($this->hasReachedDailyLimit($sim)) {
            Log::info('SIM send blocked: daily limit reached', [
                'sim_id' => $sim->id,
                'daily_limit' => $sim->daily_limit,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Check if SIM is in cooldown. Expired cooldowns are normalized to NORMAL.
     *
     * @param \App\Models\Sim $sim
     * @return bool
     */
    public function isInCooldown(Sim $sim): bool
    {
        if (!$sim->isCoolingDown()) {
            if ($sim->currentMode() === 'COOLDOWN') {
                $sim->update([
                    'mode' => 'NORMAL',
                    'cooldown_until' => null,
                    'burst_count' => 0,
                ]);

                Log::info('SIM cooldown exited and normalized', ['sim_id' => $sim->id]);
            }

            return false;
        }

        return true;
    }

    /**
     * Check if SIM has reached today's daily sending limit.
     *
     * @param \App\Models\Sim $sim
     * @return bool
     */
    public function hasReachedDailyLimit(Sim $sim): bool
    {
        $sentToday = SimDailyStat::query()
            ->where('sim_id', $sim->id)
            ->whereDate('stat_date', today())
            ->value('sent_count') ?? 0;

        return $sentToday >= (int) $sim->daily_limit;
    }

    /**
     * Determine whether a message type should use burst mode timing.
     *
     * @param string $messageType
     * @return bool
     */
    public function shouldUseBurstMode(string $messageType): bool
    {
        return in_array($messageType, ['CHAT', 'AUTO_REPLY'], true);
    }

    /**
     * Update SIM state fields after successful send.
     *
     * @param \App\Models\Sim $sim
     * @param string $messageType
     * @return void
     */
    public function markSendSuccess(Sim $sim, string $messageType): void
    {
        $sim->last_sent_at = now();

        if ($this->shouldUseBurstMode($messageType)) {
            $nextBurstCount = (int) $sim->burst_count + 1;
            $sim->burst_count = $nextBurstCount;

            if ($sim->currentMode() !== 'BURST') {
                Log::info('SIM mode transitioned to BURST', ['sim_id' => $sim->id]);
            }

            $sim->mode = 'BURST';

            if ($nextBurstCount >= (int) $sim->burst_limit) {
                $sim->save();
                $this->enterCooldown($sim);
                return;
            }

            $sim->save();
            return;
        }

        if ($sim->currentMode() !== 'NORMAL') {
            Log::info('SIM mode normalized to NORMAL', ['sim_id' => $sim->id]);
        }

        $sim->mode = 'NORMAL';
        $sim->burst_count = 0;
        $sim->cooldown_until = null;
        $sim->save();
    }

    /**
     * Enter cooldown mode using configured SIM cooldown random interval.
     *
     * @param \App\Models\Sim $sim
     * @return void
     */
    public function enterCooldown(Sim $sim): void
    {
        $cooldownSeconds = $this->randomSeconds((int) $sim->cooldown_min_seconds, (int) $sim->cooldown_max_seconds);

        $sim->update([
            'mode' => 'COOLDOWN',
            'cooldown_until' => now()->addSeconds($cooldownSeconds),
            'burst_count' => 0,
        ]);

        Log::info('SIM entered cooldown', [
            'sim_id' => $sim->id,
            'cooldown_seconds' => $cooldownSeconds,
        ]);
    }

    /**
     * Get send sleep seconds by message type and SIM interval config.
     *
     * @param \App\Models\Sim $sim
     * @param string $messageType
     * @return int
     */
    public function getSleepSecondsForMessageType(Sim $sim, string $messageType): int
    {
        if ($this->shouldUseBurstMode($messageType)) {
            return $this->randomSeconds((int) $sim->burst_interval_min_seconds, (int) $sim->burst_interval_max_seconds);
        }

        return $this->randomSeconds((int) $sim->normal_interval_min_seconds, (int) $sim->normal_interval_max_seconds);
    }

    /**
     * @return int
     */
    public function getIdleSleepSeconds(): int
    {
        return self::IDLE_SLEEP_SECONDS;
    }

    /**
     * @return int
     */
    public function getCooldownSleepSeconds(): int
    {
        return self::COOLDOWN_SLEEP_SECONDS;
    }

    /**
     * @return int
     */
    public function getInactiveSleepSeconds(): int
    {
        return self::INACTIVE_SLEEP_SECONDS;
    }

    /**
     * @return int
     */
    public function getDailyLimitSleepSeconds(): int
    {
        return self::DAILY_LIMIT_SLEEP_SECONDS;
    }

    /**
     * Generate randomized interval using safe bounds.
     *
     * @param int $min
     * @param int $max
     * @return int
     */
    protected function randomSeconds(int $min, int $max): int
    {
        $safeMin = max(1, min($min, $max));
        $safeMax = max($safeMin, $max);

        return random_int($safeMin, $safeMax);
    }
}
