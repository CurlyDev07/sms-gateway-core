<?php

namespace App\Services;

use App\Models\CustomerSimAssignment;
use App\Models\OutboundMessage;
use App\Models\Sim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SimMigrationService
{
    /**
     * Outbound message statuses that are safe to migrate in Phase 1.
     *
     * @var array<int, string>
     */
    private const MIGRATABLE_MESSAGE_STATUSES = [
        'pending',
        'queued',
    ];

    /**
     * Migrate one customer's sticky assignment and eligible outbound rows.
     *
     * Phase 1 scope:
     * - DB-first migration only
     * - no Redis queue operations
     * - no worker-side behavior changes
     *
     * @param int $companyId
     * @param int $fromSimId
     * @param int $toSimId
     * @param string $customerPhone
     * @return array<string, int|string>
     */
    public function migrateSingleCustomer(int $companyId, int $fromSimId, int $toSimId, string $customerPhone): array
    {
        $customerPhone = trim($customerPhone);

        if ($customerPhone === '') {
            throw new InvalidArgumentException('Customer phone is required for single-customer migration.');
        }

        return DB::transaction(function () use ($companyId, $fromSimId, $toSimId, $customerPhone) {
            [$fromSim, $toSim] = $this->lockAndValidateSims($companyId, $fromSimId, $toSimId);

            $assignments = CustomerSimAssignment::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $fromSim->id)
                ->where('customer_phone', $customerPhone)
                ->lockForUpdate()
                ->get();

            $assignmentsMoved = 0;

            foreach ($assignments as $assignment) {
                if ((int) $assignment->sim_id !== (int) $fromSim->id) {
                    continue;
                }

                $assignment->update([
                    'sim_id' => $toSim->id,
                    'last_used_at' => now(),
                ]);

                $assignmentsMoved++;
            }

            $messages = OutboundMessage::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $fromSim->id)
                ->where('customer_phone', $customerPhone)
                ->whereIn('status', self::MIGRATABLE_MESSAGE_STATUSES)
                ->lockForUpdate()
                ->get();

            $messagesMoved = 0;

            foreach ($messages as $message) {
                if ((int) $message->sim_id !== (int) $fromSim->id) {
                    continue;
                }

                if (!in_array((string) $message->status, self::MIGRATABLE_MESSAGE_STATUSES, true)) {
                    continue;
                }

                $message->update([
                    'sim_id' => $toSim->id,
                ]);

                $messagesMoved++;
            }

            Log::info('SIM migration completed for single customer', [
                'company_id' => $companyId,
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
                'customer_phone' => $customerPhone,
                'assignments_moved' => $assignmentsMoved,
                'messages_moved' => $messagesMoved,
                'message_scope_statuses' => self::MIGRATABLE_MESSAGE_STATUSES,
            ]);

            return [
                'company_id' => $companyId,
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
                'customer_phone' => $customerPhone,
                'assignments_moved' => $assignmentsMoved,
                'messages_moved' => $messagesMoved,
            ];
        });
    }

    /**
     * Bulk migrate sticky assignments and eligible outbound rows from one SIM to another.
     *
     * @param int $companyId
     * @param int $fromSimId
     * @param int $toSimId
     * @return array<string, int>
     */
    public function migrateBulk(int $companyId, int $fromSimId, int $toSimId): array
    {
        return DB::transaction(function () use ($companyId, $fromSimId, $toSimId) {
            [$fromSim, $toSim] = $this->lockAndValidateSims($companyId, $fromSimId, $toSimId);

            $assignments = CustomerSimAssignment::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $fromSim->id)
                ->lockForUpdate()
                ->get();

            $assignmentsMoved = 0;

            foreach ($assignments as $assignment) {
                if ((int) $assignment->sim_id !== (int) $fromSim->id) {
                    continue;
                }

                $assignment->update([
                    'sim_id' => $toSim->id,
                    'last_used_at' => now(),
                ]);

                $assignmentsMoved++;
            }

            $messages = OutboundMessage::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $fromSim->id)
                ->whereIn('status', self::MIGRATABLE_MESSAGE_STATUSES)
                ->lockForUpdate()
                ->get();

            $messagesMoved = 0;

            foreach ($messages as $message) {
                if ((int) $message->sim_id !== (int) $fromSim->id) {
                    continue;
                }

                if (!in_array((string) $message->status, self::MIGRATABLE_MESSAGE_STATUSES, true)) {
                    continue;
                }

                $message->update([
                    'sim_id' => $toSim->id,
                ]);

                $messagesMoved++;
            }

            Log::info('SIM bulk migration completed', [
                'company_id' => $companyId,
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
                'assignments_moved' => $assignmentsMoved,
                'messages_moved' => $messagesMoved,
                'message_scope_statuses' => self::MIGRATABLE_MESSAGE_STATUSES,
            ]);

            return [
                'company_id' => $companyId,
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
                'assignments_moved' => $assignmentsMoved,
                'messages_moved' => $messagesMoved,
            ];
        });
    }

    /**
     * Rebalance tenant-safe assignment load from one SIM to another.
     *
     * Conservative Phase 4 behavior:
     * - source/destination SIMs must belong to tenant company
     * - destination blocked SIM is rejected
     * - only assignment rows eligible for migration are moved:
     *   safe_to_migrate=true, migration_locked=false, status=active
     * - only pending/queued outbound rows for moved customers are moved
     *
     * @param int $companyId
     * @param int $fromSimId
     * @param int $toSimId
     * @return array<string, int>
     */
    public function rebalanceSafeAssignments(int $companyId, int $fromSimId, int $toSimId): array
    {
        return DB::transaction(function () use ($companyId, $fromSimId, $toSimId) {
            [$fromSim, $toSim] = $this->lockAndValidateSims($companyId, $fromSimId, $toSimId);

            $eligibleAssignments = CustomerSimAssignment::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $fromSim->id)
                ->where('status', 'active')
                ->where('safe_to_migrate', true)
                ->where('migration_locked', false)
                ->lockForUpdate()
                ->get();

            if ($eligibleAssignments->isEmpty()) {
                return [
                    'from_sim_id' => $fromSim->id,
                    'to_sim_id' => $toSim->id,
                    'assignments_moved' => 0,
                    'messages_moved' => 0,
                ];
            }

            $assignmentsMoved = 0;
            $customerPhones = [];

            foreach ($eligibleAssignments as $assignment) {
                $assignment->update([
                    'sim_id' => $toSim->id,
                    'last_used_at' => now(),
                ]);

                $assignmentsMoved++;
                $customerPhones[] = (string) $assignment->customer_phone;
            }

            $customerPhones = array_values(array_unique(array_filter($customerPhones, static function ($value) {
                return $value !== '';
            })));

            if ($customerPhones === []) {
                return [
                    'from_sim_id' => $fromSim->id,
                    'to_sim_id' => $toSim->id,
                    'assignments_moved' => $assignmentsMoved,
                    'messages_moved' => 0,
                ];
            }

            $messages = OutboundMessage::query()
                ->where('company_id', $companyId)
                ->where('sim_id', $fromSim->id)
                ->whereIn('customer_phone', $customerPhones)
                ->whereIn('status', self::MIGRATABLE_MESSAGE_STATUSES)
                ->lockForUpdate()
                ->get();

            $messagesMoved = 0;

            foreach ($messages as $message) {
                $message->update([
                    'sim_id' => $toSim->id,
                ]);

                $messagesMoved++;
            }

            Log::info('SIM rebalance completed', [
                'company_id' => $companyId,
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
                'assignments_moved' => $assignmentsMoved,
                'messages_moved' => $messagesMoved,
                'assignment_scope' => [
                    'status' => 'active',
                    'safe_to_migrate' => true,
                    'migration_locked' => false,
                ],
                'message_scope_statuses' => self::MIGRATABLE_MESSAGE_STATUSES,
            ]);

            return [
                'from_sim_id' => $fromSim->id,
                'to_sim_id' => $toSim->id,
                'assignments_moved' => $assignmentsMoved,
                'messages_moved' => $messagesMoved,
            ];
        });
    }

    /**
     * Lock and validate source/destination SIMs for tenant-safe migration.
     *
     * @param int $companyId
     * @param int $fromSimId
     * @param int $toSimId
     * @return array{0: \App\Models\Sim, 1: \App\Models\Sim}
     */
    protected function lockAndValidateSims(int $companyId, int $fromSimId, int $toSimId): array
    {
        if ($fromSimId === $toSimId) {
            throw new InvalidArgumentException('Source and destination SIM must be different.');
        }

        $sims = Sim::query()
            ->whereIn('id', [$fromSimId, $toSimId])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var \App\Models\Sim|null $fromSim */
        $fromSim = $sims->get($fromSimId);
        /** @var \App\Models\Sim|null $toSim */
        $toSim = $sims->get($toSimId);

        if ($fromSim === null) {
            throw new InvalidArgumentException('Source SIM not found.');
        }

        if ($toSim === null) {
            throw new InvalidArgumentException('Destination SIM not found.');
        }

        if ((int) $fromSim->company_id !== $companyId) {
            throw new InvalidArgumentException('Source SIM does not belong to the provided company.');
        }

        if ((int) $toSim->company_id !== $companyId) {
            throw new InvalidArgumentException('Destination SIM does not belong to the provided company.');
        }

        if ((string) $toSim->operator_status === 'blocked') {
            throw new InvalidArgumentException('Destination SIM is blocked and cannot receive migrated traffic.');
        }

        return [$fromSim, $toSim];
    }
}
