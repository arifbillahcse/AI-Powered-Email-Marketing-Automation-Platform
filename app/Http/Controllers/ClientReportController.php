<?php

namespace App\Http\Controllers;

use App\Services\Agency\ClientReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public, read-only client report behind a secret link.
 */
class ClientReportController extends Controller
{
    public function __invoke(Request $request, ClientReport $reports, string $token): Response
    {
        $workspace = $reports->find($token);

        abort_if($workspace === null, 404);

        return response()
            ->view('reports.client', [
                'report' => $reports->data($workspace, (int) $request->query('days', '30')),
                'token' => $token,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
