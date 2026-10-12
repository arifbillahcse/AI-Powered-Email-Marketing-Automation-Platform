<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Admin resources answer to super admins only, never to the customer-facing
 * model policies (which are about workspace roles).
 */
trait SuperAdminOnly
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_super_admin ? Response::allow() : Response::deny();
    }
}
