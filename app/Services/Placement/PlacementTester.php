<?php

namespace App\Services\Placement;

use App\Enums\EmailAccountStatus;
use App\Enums\MailProvider;
use App\Jobs\SendPlacementTest;
use App\Models\EmailAccount;
use App\Models\PlacementResult;
use App\Models\PlacementTest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inbox placement tests: send one email from a mailbox to every seed
 * mailbox and see, per provider, whether it reached the inbox or spam.
 *
 * Seeds: the platform's seed workspace (PLACEMENT_SEED_WORKSPACE) and,
 * if asked, the workspace's own other mailboxes.
 */
class PlacementTester
{
    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * @return Collection<int, EmailAccount>
     */
    public function seeds(Workspace $workspace, EmailAccount $sender, bool $includeOwn): Collection
    {
        $seedWorkspace = $this->seedWorkspaceId();

        // Seeds live in another workspace: skip the panel's tenant scope.
        return EmailAccount::query()
            ->withoutGlobalScopes()
            ->where('status', EmailAccountStatus::Active->value)
            ->whereKeyNot($sender->getKey())
            ->where('email', '!=', $sender->email)
            ->where(fn ($query) => $query
                ->when($seedWorkspace !== null, fn ($query) => $query->orWhere('workspace_id', $seedWorkspace))
                ->when($includeOwn, fn ($query) => $query->orWhere('workspace_id', $workspace->getKey()))
                ->when($seedWorkspace === null && ! $includeOwn, fn ($query) => $query->whereRaw('1 = 0')))
            ->orderBy('id')
            ->limit((int) config('outreach.placement.max_seeds'))
            ->get();
    }

    /**
     * @throws PlacementException
     */
    public function start(Workspace $workspace, EmailAccount $sender, ?User $user, string $subject, string $body, bool $includeOwn = false): PlacementTest
    {
        if (! $this->entitlements->allowsModule($workspace, 'inbox_placement')) {
            throw new PlacementException('Inbox placement tests aren\'t included in your plan.');
        }

        if ($sender->workspace_id !== $workspace->getKey() || ! $sender->isActive()) {
            throw new PlacementException('Choose an active mailbox of this workspace.');
        }

        $seeds = $this->seeds($workspace, $sender, $includeOwn);

        if ($seeds->isEmpty()) {
            throw new PlacementException($includeOwn
                ? 'There are no seed mailboxes to test with. Connect another mailbox, or ask your administrator to set up seed accounts.'
                : 'No seed mailboxes are set up yet. Include your own mailboxes, or ask your administrator to set up seed accounts.');
        }

        $test = DB::transaction(function () use ($workspace, $sender, $user, $subject, $body, $seeds): PlacementTest {
            $test = PlacementTest::query()->create([
                'workspace_id' => $workspace->getKey(),
                'email_account_id' => $sender->getKey(),
                'created_by' => $user?->getKey(),
                'subject' => Str::limit($subject, 250, ''),
                'body' => $body,
            ]);

            foreach ($seeds as $seed) {
                $test->results()->create([
                    'seed_id' => $seed->getKey(),
                    'provider' => $this->provider($seed),
                    'token' => Str::random(40),
                ]);
            }

            return $test;
        });

        SendPlacementTest::dispatch($test->getKey());

        return $test;
    }

    public function provider(EmailAccount $seed): string
    {
        $domain = $seed->domain();

        return match (true) {
            $seed->provider === MailProvider::Google, in_array($domain, ['gmail.com', 'googlemail.com'], true) => 'Gmail',
            $seed->provider === MailProvider::Microsoft, (bool) preg_match('/^(outlook|hotmail|live|msn)\./', $domain) => 'Outlook',
            $seed->provider === MailProvider::Zoho, str_starts_with($domain, 'zoho') => 'Zoho',
            (bool) preg_match('/^(yahoo|ymail|aol)\./', $domain) => 'Yahoo',
            default => 'Other',
        };
    }

    /**
     * Counts per status, and the inbox rate of the emails that arrived.
     *
     * @return array{inbox: int, spam: int, missing: int, failed: int, pending: int, rate: ?int}
     */
    public function summary(PlacementTest $test): array
    {
        $counts = PlacementResult::query()
            ->where('placement_test_id', $test->getKey())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);

        $summary = collect(PlacementResult::STATUSES)->mapWithKeys(fn (string $status): array => [$status => $counts[$status] ?? 0])->all();
        $landed = $summary['inbox'] + $summary['spam'] + $summary['missing'];

        return [...$summary, 'rate' => $landed > 0 ? (int) round($summary['inbox'] / $landed * 100) : null];
    }

    protected function seedWorkspaceId(): ?int
    {
        $setting = config('outreach.placement.seed_workspace');

        if (blank($setting)) {
            return null;
        }

        $id = Workspace::query()
            ->where(fn ($query) => ctype_digit((string) $setting) ? $query->whereKey((int) $setting) : $query->where('slug', (string) $setting))
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
