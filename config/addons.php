<?php
declare(strict_types=1);
// Thin shell override ? discovery roots only. Package defaults merge first.
return [
    'addons_path' => env('OMNICHANNEL_ADDONS_PATH', 'addons'),
    'discovery_roots' => array_values(array_filter([
        env('OMNICHANNEL_ADDONS_PATH', 'addons'),
    ])),
];
