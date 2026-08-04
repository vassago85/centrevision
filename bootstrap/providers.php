<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PlatformSettingsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    // Registered after AppServiceProvider so it can override the config keys
    // that provider defines defaults for (payment gateway resolution, etc).
    PlatformSettingsServiceProvider::class,
];
