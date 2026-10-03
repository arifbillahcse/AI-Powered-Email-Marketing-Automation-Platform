<?php

namespace App\Http\Controllers;

use App\Models\EmailMessage;
use App\Models\Workspace;
use App\Services\Sending\EngagementRecorder;
use App\Services\Sending\TrackingUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Public endpoints hit by email clients: open pixel, click redirect and
 * unsubscribe. No session, no cookies, no login.
 */
class TrackingController extends Controller
{
    /** 1×1 transparent GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function __construct(
        protected EngagementRecorder $engagement,
        protected TrackingUrls $urls,
    ) {}

    public function open(string $token): Response
    {
        if ($message = $this->message($token)) {
            $this->engagement->open($message);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $token): RedirectResponse
    {
        $url = (string) $request->query('u', '');
        $signature = (string) $request->query('s', '');

        // Only signed http(s) links: never an open redirect.
        abort_unless(
            preg_match('#^https?://#i', $url) && $this->urls->verify($token, $url, $signature),
            404,
        );

        if ($message = $this->message($token)) {
            $this->engagement->click($message, $url);
        }

        return redirect()->away($url);
    }

    public function showUnsubscribe(string $token): View
    {
        $message = $this->message($token);

        return view('tracking.unsubscribe', [
            'token' => $token,
            'sender' => $this->senderName($message),
            'done' => false,
            'valid' => $message !== null,
        ]);
    }

    /**
     * Handles both the page's button and RFC 8058 one-click requests that
     * mail providers send directly (POST, body "List-Unsubscribe=One-Click").
     */
    public function unsubscribe(Request $request, string $token): View|Response
    {
        $message = $this->message($token);

        if ($message) {
            $this->engagement->unsubscribe($message);
        }

        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response('', $message ? 200 : 404);
        }

        return view('tracking.unsubscribe', [
            'token' => $token,
            'sender' => $this->senderName($message),
            'done' => $message !== null,
            'valid' => $message !== null,
        ]);
    }

    protected function message(string $token): ?EmailMessage
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            return null;
        }

        return EmailMessage::query()->where('token', $token)->first();
    }

    protected function senderName(?EmailMessage $message): ?string
    {
        if (! $message) {
            return null;
        }

        $workspace = Workspace::query()->find($message->workspace_id);

        return $workspace?->company_name ?: $workspace?->name;
    }
}
