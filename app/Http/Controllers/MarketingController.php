<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Marketing\HelpCenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public website: landing page, pricing, help center, legal pages and
 * sitemap. Off (straight to the app) when MARKETING_SITE_ENABLED=false,
 * and never shown on an agency's custom domain.
 */
class MarketingController extends Controller
{
    public const FEATURES = [
        ['title' => 'Unlimited mailboxes', 'text' => 'Connect Gmail, Microsoft 365 or any SMTP inbox and rotate sends across them at a human pace.', 'module' => null],
        ['title' => 'Inbox warmup', 'text' => 'Mailboxes exchange real conversations, get rescued from spam and ramp up volume day by day.', 'module' => 'warmup'],
        ['title' => 'AI personalization', 'text' => 'A first line, subject or whole email written for every lead, reviewed by you before it goes out.', 'module' => 'ai'],
        ['title' => 'One inbox for replies', 'text' => 'Every reply from every mailbox in one place, sorted by AI into interested, not now and out of office.', 'module' => null],
        ['title' => 'Deliverability built in', 'text' => 'SPF, DKIM and DMARC checks, blacklist monitoring, spam-word checks, placement tests and auto-pause on bounces.', 'module' => null],
        ['title' => 'Multichannel sequences', 'text' => 'Mix emails with LinkedIn and call steps. Manual steps become tasks for your team.', 'module' => 'multichannel'],
        ['title' => 'Find and verify leads', 'text' => 'Find people at your target companies, verify addresses and import CSV or Excel files.', 'module' => 'lead_finder'],
        ['title' => 'Made for agencies', 'text' => 'Client workspaces, your own brand and domain, and live client reports that need no login.', 'module' => 'white_label'],
    ];

    public function home(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectToApp($request)) {
            return $redirect;
        }

        return view('marketing.home', [
            'features' => array_values(array_filter(self::FEATURES, fn (array $feature): bool => $feature['module'] === null || modules()->enabled($feature['module']))),
            'hasPlans' => $this->plans()->isNotEmpty(),
        ]);
    }

    public function pricing(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectToApp($request)) {
            return $redirect;
        }

        return view('marketing.pricing', ['plans' => $this->plans()]);
    }

    public function help(Request $request, HelpCenter $help): View|RedirectResponse
    {
        if ($redirect = $this->redirectToApp($request, allowCustomDomain: true)) {
            return $redirect;
        }

        return view('marketing.help', ['articles' => $help->articles()]);
    }

    public function article(Request $request, HelpCenter $help, string $slug): View|RedirectResponse
    {
        if ($redirect = $this->redirectToApp($request, allowCustomDomain: true)) {
            return $redirect;
        }

        $article = $help->article($slug);

        abort_if($article === null, 404);

        return view('marketing.article', ['article' => $article, 'articles' => $help->articles()]);
    }

    public function legal(Request $request, string $page): View|RedirectResponse
    {
        if ($redirect = $this->redirectToApp($request, allowCustomDomain: true)) {
            return $redirect;
        }

        return view("marketing.{$page}");
    }

    public function sitemap(HelpCenter $help): Response
    {
        abort_unless(config('marketing.enabled'), 404);

        $urls = [
            route('home'), route('pricing'), route('help.index'), route('legal.terms'), route('legal.privacy'),
            ...$help->articles()->map(fn (array $article): string => route('help.article', $article['slug']))->all(),
        ];

        return response()->view('marketing.sitemap', ['urls' => $urls])->header('Content-Type', 'application/xml');
    }

    /**
     * Self-hosted installs and agencies' custom domains go to the app.
     */
    protected function redirectToApp(Request $request, bool $allowCustomDomain = false): ?RedirectResponse
    {
        if (! config('marketing.enabled')) {
            return redirect('/app');
        }

        $host = Str::lower($request->getHost());

        if (! $allowCustomDomain && $host !== Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST))
            && Workspace::query()->where('custom_domain', $host)->exists()) {
            return redirect('/app/login');
        }

        return null;
    }

    /**
     * @return Collection<int, Plan>
     */
    protected function plans()
    {
        return Plan::query()->where('is_active', true)->orderBy('sort')->orderBy('price_monthly')->get();
    }
}
