<?php

use App\Support\Modules\ModuleRegistry;

if (! function_exists('modules')) {
    function modules(): ModuleRegistry
    {
        return app(ModuleRegistry::class);
    }
}
