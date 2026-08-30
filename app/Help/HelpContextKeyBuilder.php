<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Build Help context keys: {group.context_prefix}.{suffix_from_title}
 */
final class HelpContextKeyBuilder
{
    public static function suffixFromTitle(string $title): string
    {
        $slug = mb_strtolower(trim($title));
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '_', $slug) ?? $slug;
        $slug = trim($slug, '_');
        $slug = preg_replace('/_+/', '_', $slug) ?? $slug;

        return $slug !== '' ? $slug : 'topic';
    }

    public static function fromGroupAndTitle(string $groupId, string $title): string
    {
        $prefix = HelpGroupRegistry::contextPrefix($groupId);
        $suffix = self::suffixFromTitle($title);

        return $prefix.'.'.$suffix;
    }

    /**
     * Normalize updated_at from YAML/JSON (string | int timestamp | DateTime).
     */
    public static function normalizeUpdatedAt(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (is_int($value) || is_float($value)) {
            $ts = (int) $value;
            if ($ts <= 0) {
                return null;
            }

            // Date-only midnight → show date; otherwise datetime
            if ($ts % 86400 === 0) {
                return date('Y-m-d', $ts);
            }

            return date('Y-m-d H:i', $ts);
        }
        if (! is_string($value)) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        if (ctype_digit($raw)) {
            return self::normalizeUpdatedAt((int) $raw);
        }

        return $raw;
    }

    /**
     * Admin list display (vi locale preference: d/m/Y H:i).
     */
    public static function formatUpdatedAtForAdmin(mixed $updatedAt): string
    {
        $normalized = self::normalizeUpdatedAt($updatedAt);
        if ($normalized === null || $normalized === '') {
            return '—';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $normalized);

            return $dt instanceof \DateTimeImmutable ? $dt->format('d/m/Y') : $normalized;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $normalized) === 1) {
            $dt = date_create($normalized);

            return $dt instanceof \DateTimeInterface ? $dt->format('d/m/Y H:i') : $normalized;
        }

        if (ctype_digit($normalized)) {
            return self::formatUpdatedAtForAdmin((int) $normalized);
        }

        return $normalized;
    }
}
