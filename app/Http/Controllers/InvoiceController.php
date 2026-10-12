<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A printable invoice (the browser's "Save as PDF" makes the PDF). Members
 * of the invoice's workspace and super admins only.
 */
class InvoiceController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice): View
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->is_super_admin || $user->roleIn($invoice->workspace_id) !== null, 404);

        return view('billing.invoice', [
            'invoice' => $invoice,
            'workspace' => Workspace::query()->findOrFail($invoice->workspace_id),
            'seller' => config('billing.seller'),
        ]);
    }
}
