<?php

use App\Support\Modules\ModuleRegistry;

it('reports module state from config', function () {
    config(['modules.warmup.enabled' => false, 'modules.ai.enabled' => true]);

    $modules = app(ModuleRegistry::class);

    expect($modules->enabled('ai'))->toBeTrue()
        ->and($modules->disabled('warmup'))->toBeTrue()
        ->and($modules->label('warmup'))->toBe('Inbox Warmup');
});

it('is available through the modules() helper', function () {
    expect(modules())->toBeInstanceOf(ModuleRegistry::class);
});

it('rejects unknown modules', function () {
    modules()->enabled('teleportation');
})->throws(InvalidArgumentException::class);

it('treats unknown modules as off in production instead of failing the page', function () {
    // e.g. a config cache from before an update that added the module.
    app()->detectEnvironment(fn (): string => 'production');

    expect(modules()->enabled('teleportation'))->toBeFalse()
        ->and(modules()->disabled('teleportation'))->toBeTrue();
});
