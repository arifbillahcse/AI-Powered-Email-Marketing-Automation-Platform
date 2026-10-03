<?php

namespace App\Filament\App\Resources\Concerns;

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filament scopes tenant resources with a runtime global scope, which does
 * not exist in queued jobs (exports serialize the query and run it later).
 * This writes the workspace condition into the query itself, so it travels
 * with the serialized query. Use it on every workspace-owned resource.
 */
trait ScopedToWorkspace
{
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $workspace = Filament::getTenant();

        if (! $workspace) {
            // Never fall back to "every workspace".
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->qualifyColumn('workspace_id'), $workspace->getKey());
    }
}
