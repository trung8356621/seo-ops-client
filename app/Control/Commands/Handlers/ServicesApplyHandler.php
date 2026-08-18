<?php

declare(strict_types=1);

namespace App\Control\Commands\Handlers;

use App\Control\Commands\ControlCommandResult;
use App\Enums\ClientControlStatus;
use App\Models\ClientControlState;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

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

            $wanted[$slug] = $config;
        }

        $unknown = [];
        $activated = [];
        $deactivated = [];

        DB::transaction(function () use ($wanted, $revision, &$unknown, &$activated, &$deactivated): void {
            $catalog = Service::query()->get()->keyBy(fn (Service $service): string => (string) $service->slug);

            foreach ($wanted as $slug => $config) {
                $service = $catalog->get($slug);
                if (! $service instanceof Service) {
                    $unknown[] = $slug;
                    continue;
                }

                $service->is_active = true;
                $service->config = $config;
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
