<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SSOT paid lock: api_connections.paid_locked + paid_lock_reasons.
 * Backfills manual_free_only from historical Free-only UI locks and
 * budget_limited from legacy ai_runtime_health_states.paid_locked.
 *
 * ai_runtime_health_states.paid_locked remains as deprecated observability only.
 */
return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('api_connections')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('api_connections', 'paid_lock_reasons')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->json('paid_lock_reasons')->nullable()->after('paid_locked');
            });
        }

        $this->backfillManualFreeOnly();
        $this->backfillBudgetLimitedFromHealth();
        $this->reconcilePaidLockedFlags();
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('api_connections')) {
            return;
        }

        if (Schema::connection($this->connection)->hasColumn('api_connections', 'paid_lock_reasons')) {
            Schema::connection($this->connection)->table('api_connections', function (Blueprint $table): void {
                $table->dropColumn('paid_lock_reasons');
            });
        }
    }

    private function backfillManualFreeOnly(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('api_connections', 'paid_locked')) {
            return;
        }

        // Historical writers of api_connections.paid_locked were Free-only UI only
        // (SetAiConnectionFreeOnly + Filament form toggle) — not health budget locks.
        $rows = DB::connection($this->connection)
            ->table('api_connections')
            ->where('paid_locked', true)
            ->get(['id', 'paid_lock_reasons']);

        foreach ($rows as $row) {
            $reasons = $this->decodeReasons($row->paid_lock_reasons ?? null);
            if (! in_array('manual_free_only', $reasons, true)) {
                $reasons[] = 'manual_free_only';
            }
            DB::connection($this->connection)
                ->table('api_connections')
                ->where('id', $row->id)
                ->update(['paid_lock_reasons' => json_encode(array_values($reasons))]);
        }
    }

    private function backfillBudgetLimitedFromHealth(): void
    {
        if (! Schema::connection($this->connection)->hasTable('ai_runtime_health_states')) {
            return;
        }

        $healthRows = DB::connection($this->connection)
            ->table('ai_runtime_health_states')
            ->where('subject_type', 'connection')
            ->where('paid_locked', true)
            ->where('health_status', 'budget_limited')
            ->get(['subject_id', 'api_connection_id']);

        $connectionIds = [];
        foreach ($healthRows as $health) {
            $id = (int) ($health->api_connection_id ?: $health->subject_id);
            if ($id > 0) {
                $connectionIds[$id] = true;
            }
        }

        foreach (array_keys($connectionIds) as $connectionId) {
            $row = DB::connection($this->connection)
                ->table('api_connections')
                ->where('id', $connectionId)
                ->first(['id', 'paid_lock_reasons']);
            if ($row === null) {
                continue;
            }
            $reasons = $this->decodeReasons($row->paid_lock_reasons ?? null);
            if (! in_array('budget_limited', $reasons, true)) {
                $reasons[] = 'budget_limited';
            }
            DB::connection($this->connection)
                ->table('api_connections')
                ->where('id', $connectionId)
                ->update([
                    'paid_lock_reasons' => json_encode(array_values($reasons)),
                    'paid_locked' => true,
                ]);
        }
    }

    private function reconcilePaidLockedFlags(): void
    {
        $rows = DB::connection($this->connection)
            ->table('api_connections')
            ->get(['id', 'paid_locked', 'paid_lock_reasons']);

        foreach ($rows as $row) {
            $reasons = $this->decodeReasons($row->paid_lock_reasons ?? null);
            $shouldLock = $reasons !== [];
            $locked = (bool) $row->paid_locked;
            if ($locked === $shouldLock && ($row->paid_lock_reasons !== null || ! $shouldLock)) {
                continue;
            }
            DB::connection($this->connection)
                ->table('api_connections')
                ->where('id', $row->id)
                ->update([
                    'paid_locked' => $shouldLock,
                    'paid_lock_reasons' => $shouldLock ? json_encode(array_values($reasons)) : json_encode([]),
                ]);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeReasons(mixed $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return [];
            }
        } else {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }
};
