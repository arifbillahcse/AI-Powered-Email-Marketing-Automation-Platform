<?php

namespace App\Console\Commands;

use App\Enums\CampaignLeadStatus;
use App\Enums\CampaignStatus;
use App\Enums\EmailMessageStatus;
use App\Enums\LeadStatus;
use App\Enums\WorkspaceRole;
use App\Models\Campaign;
use App\Models\CampaignLead;
use App\Models\CampaignStep;
use App\Models\EmailMessage;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SubscriptionManager;
use App\Services\Campaigns\TemplateLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A demo account with realistic data (leads, lists, a draft campaign and a
 * finished one with 30 days of results) for sales demos and screenshots.
 * Nothing is ever sent: there are no mailboxes and the finished campaign
 * is completed. Runs without Faker (production installs skip dev packages).
 */
class CreateDemo extends Command
{
    public const WORKSPACE = 'Demo Agency';

    protected $signature = 'app:demo
                            {--email=demo@example.com : Login email of the demo user}
                            {--password= : Password (random when omitted)}
                            {--fresh : Delete the demo workspace first and rebuild it}';

    protected $description = 'Create a demo user and workspace filled with sample data';

    protected const FIRST = ['Jane', 'Omar', 'Priya', 'Lucas', 'Aisha', 'Tom', 'Mei', 'Carlos', 'Sara', 'Rafiq', 'Emma', 'Noah', 'Fatima', 'Liam', 'Sofia', 'Arjun', 'Nadia', 'Ben', 'Hana', 'David'];

    protected const LAST = ['Doe', 'Rahman', 'Patel', 'Silva', 'Khan', 'Becker', 'Chen', 'Garcia', 'Ahmed', 'Hossain', 'Wilson', 'Kim', 'Ali', 'Murphy', 'Rossi', 'Mehta', 'Islam', 'Clark', 'Sato', 'Lopez'];

    protected const COMPANIES = ['Northwind', 'Brightlane', 'Pixelforge', 'Cloudnest', 'Greenleaf', 'Bluepeak', 'Swiftcart', 'Datavine', 'Hostwise', 'Learnly', 'Medicore', 'Shopora', 'Finlytic', 'Buildly', 'Travelix'];

    protected const TITLES = ['CEO', 'Founder', 'Head of Sales', 'Marketing Director', 'CTO', 'Operations Manager', 'Growth Lead', 'VP Sales'];

    public function handle(TemplateLibrary $library, SubscriptionManager $subscriptions): int
    {
        $email = Str::lower((string) $this->option('email'));
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Enter a valid --email.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user && ($existing = Workspace::query()->where('name', self::WORKSPACE)->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))->first())) {
            if (! $this->option('fresh')) {
                $this->components->warn('The demo workspace already exists. Use --fresh to rebuild it.');

                return self::SUCCESS;
            }

            $existing->delete();
        }

        DB::transaction(function () use ($email, $password, &$user, $library, $subscriptions): void {
            if (! $user) {
                $user = new User(['name' => 'Demo User', 'email' => $email, 'password' => $password]);
                $user->email_verified_at = now();
                $user->save();
            } elseif ($this->option('password')) {
                $user->forceFill(['password' => $password])->save();
            }

            $workspace = Workspace::query()->create([
                'name' => self::WORKSPACE,
                'timezone' => config('app.timezone', 'UTC'),
                'company_name' => 'Demo Agency Ltd',
                'address_line1' => 'House 12, Road 5, Banani',
                'city' => 'Dhaka',
                'postal_code' => '1213',
                'country' => 'BD',
            ]);
            $workspace->addMember($user, WorkspaceRole::Owner);
            $subscriptions->startTrial($workspace);

            $this->fill($workspace, $user, $library);
        });

        $this->components->info('Demo ready. Sign in at '.url('/app/login')." as {$email}".($this->option('password') ? '.' : " with password {$password}"));

        return self::SUCCESS;
    }

    protected function fill(Workspace $workspace, User $user, TemplateLibrary $library): void
    {
        $lists = collect(['SaaS founders', 'Agency owners'])->map(function (string $name) use ($workspace): LeadList {
            $list = new LeadList(['name' => $name]);
            $list->workspace_id = $workspace->getKey();
            $list->save();

            return $list;
        });

        $leads = collect(range(0, 59))->map(function (int $i) use ($workspace, $lists): Lead {
            $first = self::FIRST[$i % count(self::FIRST)];
            $last = self::LAST[intdiv($i, count(self::FIRST)) * 7 % count(self::LAST)];
            $company = self::COMPANIES[$i % count(self::COMPANIES)];
            $domain = Str::lower($company).'.example';

            $lead = new Lead([
                'email' => Str::lower("{$first}.{$last}{$i}@{$domain}"),
                'first_name' => $first,
                'last_name' => $last,
                'company' => $company,
                'title' => self::TITLES[$i % count(self::TITLES)],
                'website' => "https://{$domain}",
                'country' => 'US',
            ]);
            $lead->workspace_id = $workspace->getKey();
            $lead->source = 'import';
            $lead->save();
            $lead->lists()->attach($lists[$i % 2]->getKey());

            return $lead;
        });

        $library->create($workspace, 'cold-3-step', 'Cold outreach: SaaS founders (draft)', $user);

        // A finished campaign with 30 days of results for the analytics.
        $done = $library->create($workspace, 'saas-demo', 'Q3 demo requests', $user);
        $done->forceFill(['status' => CampaignStatus::Completed, 'launched_at' => now()->subDays(30), 'completed_at' => now()->subDay()])->save();
        $done->leadLists()->attach($lists[0]->getKey());
        $steps = $done->steps()->get()->keyBy('position');

        foreach ($leads->take(40)->values() as $i => $lead) {
            $this->results($done, $steps->all(), $lead, $i);
        }
    }

    /**
     * @param  array<int, CampaignStep>  $steps
     */
    protected function results(Campaign $campaign, array $steps, Lead $lead, int $i): void
    {
        // 4 to 28 days ago, so follow-ups (3 days later) are in the past too.
        $sentAt = now()->subDays(4 + ($i % 25))->setTime(9 + $i % 8, ($i * 7) % 60);
        $bounced = $i % 25 === 7;
        $replied = ! $bounced && $i % 6 === 2;
        $opened = ! $bounced && ($replied || $i % 2 === 0);
        $clicked = $opened && $i % 9 === 0;

        $campaignLead = new CampaignLead;
        $campaignLead->forceFill([
            'campaign_id' => $campaign->getKey(),
            'lead_id' => $lead->getKey(),
            'status' => $bounced ? CampaignLeadStatus::Bounced : ($replied ? CampaignLeadStatus::Replied : CampaignLeadStatus::Completed),
            'steps_sent' => $replied || $bounced ? 1 : 2,
            'last_sent_at' => $sentAt,
            'replied_at' => $replied ? $sentAt->copy()->addHours(5) : null,
        ])->save();

        foreach ([1, 2] as $position) {
            if ($position === 2 && ($replied || $bounced)) {
                break;
            }

            $at = $sentAt->copy()->addDays(($position - 1) * 3);
            $message = new EmailMessage;
            $message->forceFill([
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->getKey(),
                'campaign_lead_id' => $campaignLead->getKey(),
                'campaign_step_id' => $steps[$position]?->getKey(),
                'lead_id' => $lead->getKey(),
                'step_position' => $position,
                'token' => Str::random(40),
                'message_id' => Str::uuid().'@demo.invalid',
                'subject' => $position === 1 ? "{$lead->company} and faster demos" : "Re: {$lead->company} and faster demos",
                'status' => $bounced ? EmailMessageStatus::Bounced : EmailMessageStatus::Sent,
                'sent_at' => $at,
                'opened_at' => $opened && $position === 1 ? $at->copy()->addHours(2) : null,
                'open_count' => $opened && $position === 1 ? 1 + $i % 3 : 0,
                'clicked_at' => $clicked && $position === 1 ? $at->copy()->addHours(3) : null,
                'click_count' => $clicked && $position === 1 ? 1 : 0,
                'replied_at' => $replied ? $at->copy()->addHours(5) : null,
                'bounced_at' => $bounced ? $at : null,
            ])->save();
        }

        $lead->forceFill([
            'status' => $bounced ? LeadStatus::Bounced : ($replied ? ($i % 12 === 2 ? LeadStatus::Interested : LeadStatus::Replied) : LeadStatus::Contacted),
            'last_contacted_at' => $sentAt,
        ])->save();
    }
}
