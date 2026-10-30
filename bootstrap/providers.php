<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\TravelServiceProvider;
use App\Support\AdminPanelLoading;

return [
    // The admin panel (Filament, Livewire) only where it's served: AdminPanelLoading.
    ...AdminPanelLoading::providers(),
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    TravelServiceProvider::class,
];
