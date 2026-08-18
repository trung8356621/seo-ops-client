<?php

declare(strict_types=1);

namespace App\Core\Operations;

/**
 * Reusable structured progress for long-running jobs (Site Sync, Link Health, …).
 * Persist as JSON; never store a raw status sentence as the only progress.
 *
 * @phpstan-type Substep array{key: string, label: string, status: string, current?: int|null, total?: int|null}
 * @phpstan-type Metrics array<string, int|float|string>
 */
final class LongRunningProgress
{
    public const MIN_RECORD_INTERVAL = 20;

    public const MIN_SECONDS_INTERVAL = 3;

    /**
     * @param  array<string, int|float|string>  $metrics
     * @param  list<array<string, mixed>>  $substeps
     */
    public function __construct(
        public readonly string $status = 'running',
        public readonly ?string $phase = null,
        public readonly int $step = 0,
        public readonly int $totalSteps = 0,
        public readonly ?int $current = null,
        public readonly ?int $total = null,
        public readonly array $metrics = [],
        public readonly ?string $message = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $lastActivityAt = null,
        public readonly ?string $finishedAt = null,
        public readonly array $substeps = [],
        public readonly ?int $attempt = null,
        public readonly ?int $maxAttempts = null,
        public readonly ?int $batch = null,
        public readonly ?int $batchTotal = null,
        public readonly ?float $batchDurationSeconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $metrics = [];
        if (is_array($data['metrics'] ?? null)) {
            foreach ($data['metrics'] as $key => $value) {
                if (is_int($value) || is_float($value) || is_string($value)) {
                    $metrics[(string) $key] = $value;
                }
            }
        }

        $substeps = [];
        if (is_array($data['substeps'] ?? null)) {
            foreach ($data['substeps'] as $row) {
                if (is_array($row)) {
                    $substeps[] = $row;
                }
            }
        }

        return new self(
            status: (string) ($data['status'] ?? 'running'),
            phase: isset($data['phase']) ? (string) $data['phase'] : null,
            step: max(0, (int) ($data['step'] ?? 0)),
            totalSteps: max(0, (int) ($data['total_steps'] ?? $data['totalSteps'] ?? 0)),
            current: self::nullableInt($data['current'] ?? null),
            total: self::nullableInt($data['total'] ?? null),
            metrics: $metrics,
            message: isset($data['message']) ? (string) $data['message'] : null,
            startedAt: isset($data['started_at']) ? (string) $data['started_at'] : (isset($data['startedAt']) ? (string) $data['startedAt'] : null),
            lastActivityAt: isset($data['last_activity_at']) ? (string) $data['last_activity_at'] : (isset($data['lastActivityAt']) ? (string) $data['lastActivityAt'] : null),
            finishedAt: isset($data['finished_at']) ? (string) $data['finished_at'] : (isset($data['finishedAt']) ? (string) $data['finishedAt'] : null),
            substeps: $substeps,
            attempt: self::nullableInt($data['attempt'] ?? null),
            maxAttempts: self::nullableInt($data['max_attempts'] ?? $data['maxAttempts'] ?? null),
            batch: self::nullableInt($data['batch'] ?? null),
            batchTotal: self::nullableInt($data['batch_total'] ?? $data['batchTotal'] ?? null),
            batchDurationSeconds: isset($data['batch_duration_seconds']) && is_numeric($data['batch_duration_seconds'])
                ? (float) $data['batch_duration_seconds']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'phase' => $this->phase,
            'step' => $this->step,
            'total_steps' => $this->totalSteps,
            'current' => $this->current,
            'total' => $this->total,
            'percentage' => $this->percentage(),
            'metrics' => $this->metrics,
            'message' => $this->message,
            'started_at' => $this->startedAt,
            'last_activity_at' => $this->lastActivityAt,
            'finished_at' => $this->finishedAt,
            'substeps' => $this->substeps,
            'attempt' => $this->attempt,
            'max_attempts' => $this->maxAttempts,
            'batch' => $this->batch,
            'batch_total' => $this->batchTotal,
            'batch_duration_seconds' => $this->batchDurationSeconds,
        ];
    }

    public function percentage(): ?int
    {
        if ($this->total === null || $this->total <= 0 || $this->current === null) {
            return null;
        }

        return max(0, min(100, (int) floor(($this->current / $this->total) * 100)));
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function merge(array $patch, ?string $nowIso = null): self
    {
        $incoming = self::fromArray([...$this->toArray(), ...$patch]);
        $now = $nowIso ?? gmdate('c');

        $current = $this->current;
        if ($incoming->current !== null) {
            $current = $current === null ? $incoming->current : max($current, $incoming->current);
        }

        $metrics = $this->metrics;
        foreach ($incoming->metrics as $key => $value) {
            $metrics[$key] = $value;
        }

        return new self(
            status: $incoming->status !== '' ? $incoming->status : $this->status,
            phase: $incoming->phase ?? $this->phase,
            step: $incoming->step > 0 ? $incoming->step : $this->step,
            totalSteps: $incoming->totalSteps > 0 ? $incoming->totalSteps : $this->totalSteps,
            current: $current,
            total: $incoming->total ?? $this->total,
            metrics: $metrics,
            message: $incoming->message ?? $this->message,
            startedAt: $this->startedAt ?? $incoming->startedAt,
            lastActivityAt: $now,
            finishedAt: $incoming->finishedAt ?? $this->finishedAt,
            substeps: $incoming->substeps !== [] ? $incoming->substeps : $this->substeps,
            attempt: $incoming->attempt ?? $this->attempt,
            maxAttempts: $incoming->maxAttempts ?? $this->maxAttempts,
            batch: $incoming->batch ?? $this->batch,
            batchTotal: $incoming->batchTotal ?? $this->batchTotal,
            batchDurationSeconds: $incoming->batchDurationSeconds ?? $this->batchDurationSeconds,
        );
    }

    public function shouldPersist(?self $previous, int $minRecords = self::MIN_RECORD_INTERVAL, int $minSeconds = self::MIN_SECONDS_INTERVAL): bool
    {
        if ($previous === null) {
            return true;
        }

        if ($previous->status !== $this->status || $previous->phase !== $this->phase || $previous->step !== $this->step) {
            return true;
        }

        $prevCurrent = $previous->current ?? 0;
        $current = $this->current ?? 0;
        if (($current - $prevCurrent) >= $minRecords) {
            return true;
        }

        if ($this->batch !== null && $this->batch !== $previous->batch) {
            return true;
        }

        $prevTs = $previous->lastActivityAt !== null ? strtotime($previous->lastActivityAt) : false;
        $nowTs = $this->lastActivityAt !== null ? strtotime($this->lastActivityAt) : false;
        if ($prevTs === false || $nowTs === false) {
            return true;
        }

        return ($nowTs - $prevTs) >= $minSeconds;
    }

    public function isStuck(int $thresholdSeconds, ?string $nowIso = null): bool
    {
        if (! in_array($this->status, ['pending', 'running', 'queued'], true)) {
            return false;
        }
        if ($this->lastActivityAt === null || $this->lastActivityAt === '') {
            return false;
        }
        $last = strtotime($this->lastActivityAt);
        $now = strtotime($nowIso ?? gmdate('c'));
        if ($last === false || $now === false) {
            return false;
        }

        return ($now - $last) >= $thresholdSeconds;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
