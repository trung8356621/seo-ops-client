<?php

return [
    App\Core\ClientCoreServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Help\HelpServiceProvider::class,
    // Business Filament panels register via addon manifests (register_early / services.is_active).
];
