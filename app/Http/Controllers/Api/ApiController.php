<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }
}
