<?php

namespace App\Http\Middleware;

use App\Services\Agency\Branding;
use Closure;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Swaps the panel's primary color for the white-label brand color. Runs
 * after the panel registers its own colors; the closure is evaluated when
 * the page renders, once the workspace (tenant) is known.
 */
class ApplyBranding
{
    public function handle(Request $request, Closure $next): Response
    {
        FilamentColor::register(function (): array {
            $color = app(Branding::class)->current()?->color;

            return $color === null ? [] : ['primary' => Color::hex($color)];
        });

        return $next($request);
    }
}
