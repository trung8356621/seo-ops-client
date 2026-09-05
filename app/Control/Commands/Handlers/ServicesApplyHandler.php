<?php

declare(strict_types=1);

namespace App\Control\Commands\Handlers;

use App\Control\Commands\ControlCommandResult;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ops-server → client replace snapshot.
 *
 * Canonical active entry:
 *   { "slug": "...", "service_key": "...", "config": { ... } }
 *
 * service_key is optional during migration (legacy snapshots).
 * Revoked services clear service_key.
 */
final class ServicesApplyHandler implements ControlCommandHandler
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): ControlCommandResult
    {
        $mode = strtolower(trim((string) ($payload['mode'] ?? '')));
        if ($mode !== 'replace') {
            return ControlCommandResult::failed('invalid_mode');
        }

        if (! array_key_exists('revision', $payload) || ! is_numeric($payload['revision'])) {
            return ControlCommandResult::failed('invalid_revision');
        }

        $revision = (int) $payload['revision'];
        $activeServices = $payload['active_services'] ?? null;
        if (! is_array($activeServices)) {
            return ControlCommandResult::failed('invalid_active_services');
        }

        /** @var array<string, array{config: array<string, mixed>, service_key: ?string, has_service_key: bool}> $wanted */
        $wanted = [];
        foreach ($activeServices as $item) {
            if (! is_array($item)) {
                return ControlCommandResult::failed('invalid_active_services');
            }

            $slug = trim((string) ($item['slug'] ?? ''));
            if ($slug === '') {
                return ControlCommandResult::failed('invalid_active_services');
            }

            $config = $item['config'] ?? [];
            if (! is_array($config)) {
                return ControlCommandResult::failed('invalid_active_services');
            }

            // Never accept service_key buried in config JSON.
            unset($config['service_key']);

            $hasKey = array_key_exists('service_key', $item);
            $key = null;
            if ($hasKey) {
                $raw = $item['service_key'];
                $key = $raw === null ? null : trim((string) $raw);
                if ($key === '') {
                    $key = null;
                }
            }

            $wanted[$slug] = [
                'config' => $config,
                'service_key' => $key,
                'has_service_key' => $hasKey,
            ];
        }

        $unknown = [];
        $activated = [];
        $deactivated = [];
        $hasServiceKeyColumn = Schema::hasColumn('services', 'service_key');

        DB::transaction(function () use (
            $wanted,
            $revision,
            $hasServiceKeyColumn,
            &$unknown,
            &$activated,
            &$deactivated,
        ): void {
            $catalog = Service::query()->get()->keyBy(fn (Service $service): string => (string) $service->slug);

            foreach ($wanted as $slug => $entry) {
                $service = $catalog->get($slug);
                if (! $service instanceof Service) {
                    $unknown[] = $slug;
                    continue;
                }

                $service->is_active = true;
                $service->config = $entry['config'];
                if ($hasServiceKeyColumn && $entry['has_service_key']) {
                    $service->service_key = $entry['service_key'];
                }
                $service->save();
                $activated[] = $slug;
            }

            foreach ($catalog as $slug => $service) {
                if (isset($wanted[$slug])) {
                    continue;
                }

                if ((bool) $service->is_active === false) {
                    continue;
                }

                $service->is_active = false;
                if ($hasServiceKeyColumn) {
                    $service->service_key = null;
                }
                $service->save();
                $deactivated[] = $slug;
            }

            $state = ClientControlState::query()->orderBy('id')->lockForUpdate()->first();
            if ($state instanceof ClientControlState && $state->status !== ClientControlStatus::Unregistered) {
                $state->services_revision = $revision;
                $state->save();
            }
        });

        return ControlCommandResult::completed([
            'revision' => $revision,
            'mode' => 'replace',
            'activated' => array_values($activated),
            'deactivated' => array_values($deactivated),
            'unknown_slugs' => array_values($unknown),
        ]);
    }
}
