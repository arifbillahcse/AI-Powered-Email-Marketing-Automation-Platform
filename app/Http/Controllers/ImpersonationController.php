<?php

namespace App\Http\Controllers;

use App\Services\Admin\Impersonator;
use Illuminate\Http\RedirectResponse;

class ImpersonationController extends Controller
{
    public function stop(Impersonator $impersonator): RedirectResponse
    {
        $admin = $impersonator->stop();

        return redirect()->to($admin ? url('/admin/users') : url('/app'));
    }
}
