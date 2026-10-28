<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HealthCheckServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    HealthCheckServiceProvider::class,
    BroadcastServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
];
