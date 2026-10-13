<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Enums\StepType;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Ready-made sequences to start a campaign from. Short, plain, personal
 * copy (what gets replies and stays out of spam), with fallbacks for every
 * variable so nothing renders empty.
 */
class TemplateLibrary
{
    public const TEMPLATES = [
        'cold-3-step' => [
            'name' => 'Classic cold outreach (3 emails)',
            'description' => 'A short first email, a bump in the same thread, and a polite break-up.',
            'steps' => [
                ['type' => 'email', 'delay_days' => 0, 'subject' => '{Quick question|Question}, {{first_name|there}}',
                    'body' => '<p>{Hi|Hello} {{first_name|there}},</p><p>I noticed {{company|your team}} is growing and wanted to ask: how are you handling [problem you solve] today?</p><p>We help companies like yours [result in one line]. Worth a short chat?</p>'],
                ['type' => 'email', 'delay_days' => 3, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, just bumping this to the top of your inbox. Would a 15-minute call next week make sense?</p>'],
                ['type' => 'email', 'delay_days' => 5, 'subject' => null,
                    'body' => '<p>{{first_name|Hi}}, I don\'t want to clutter your inbox, so this is my last note. If [problem] becomes a priority, just reply and I\'ll send over a few ideas.</p>'],
            ],
        ],
        'agency-services' => [
            'name' => 'Agency: website and SEO services',
            'description' => 'For web, SEO and marketing agencies offering a free audit.',
            'steps' => [
                ['type' => 'email', 'delay_days' => 0, 'subject' => 'Idea for {{company|your website}}',
                    'body' => '<p>Hi {{first_name|there}},</p><p>I had a look at {{website|your website}} and spotted two quick wins that could bring in more enquiries: [win 1] and [win 2].</p><p>Happy to send a free 5-minute video audit. Interested?</p>'],
                ['type' => 'email', 'delay_days' => 4, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, should I record that short audit for {{company|you}}? No strings attached.</p>'],
                ['type' => 'email', 'delay_days' => 6, 'subject' => 'Closing the loop',
                    'body' => '<p>Hi {{first_name|there}}, I\'ll close the loop here. If improving {{company|your}} website is on the list for later this year, reply anytime.</p>'],
            ],
        ],
        'saas-demo' => [
            'name' => 'SaaS: book a demo',
            'description' => 'Problem, proof and a soft ask for a demo, over three emails.',
            'steps' => [
                ['type' => 'email', 'delay_days' => 0, 'subject' => '{{company|Your team}} and [outcome]',
                    'body' => '<p>Hi {{first_name|there}},</p><p>Teams like [similar customer] use [product] to [outcome] without [pain].</p><p>Would it be worth a 20-minute demo to see if it fits {{company|your team}}?</p>'],
                ['type' => 'email', 'delay_days' => 3, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, one number that might help: [customer] saved [result] in their first month. Open to a quick look?</p>'],
                ['type' => 'email', 'delay_days' => 7, 'subject' => null,
                    'body' => '<p>{{first_name|Hi}}, is someone else at {{company|your company}} the right person for this? Happy to reach out to them instead.</p>'],
            ],
        ],
        'recruiting' => [
            'name' => 'Recruiting: reach a candidate',
            'description' => 'A respectful outreach to a passive candidate, with one follow-up.',
            'steps' => [
                ['type' => 'email', 'delay_days' => 0, 'subject' => '{{title|Your experience}} at {{company|your company}}',
                    'body' => '<p>Hi {{first_name|there}},</p><p>Your work as {{title|a specialist}} at {{company|your company}} caught my eye. We\'re hiring a [role] at [company] to [mission].</p><p>Open to a confidential chat this week?</p>'],
                ['type' => 'email', 'delay_days' => 5, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, no worries if the timing isn\'t right. If you know someone who might be a fit, I\'d appreciate an intro.</p>'],
            ],
        ],
        'event-invite' => [
            'name' => 'Webinar or event invitation',
            'description' => 'Invite, reminder and last call for a webinar or event.',
            'steps' => [
                ['type' => 'email', 'delay_days' => 0, 'subject' => 'Invitation: [event] on [date]',
                    'body' => '<p>Hi {{first_name|there}},</p><p>We\'re hosting [event] on [date], about [topic]. I thought it could be useful for {{company|your team}}.</p><p>Want me to save you a seat?</p>'],
                ['type' => 'email', 'delay_days' => 4, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, a few seats are left for [event]. Should I add you?</p>'],
            ],
        ],
        'multichannel' => [
            'name' => 'Multichannel: LinkedIn and email',
            'description' => 'View their LinkedIn profile, connect, then email. Needs multichannel steps.',
            'steps' => [
                ['type' => 'linkedin_visit', 'delay_days' => 0, 'subject' => 'View {{first_name|their}} LinkedIn profile', 'body' => null],
                ['type' => 'linkedin_connect', 'delay_days' => 1, 'subject' => 'Connect with {{first_name|them}}',
                    'body' => '<p>Hi {{first_name|there}}, I work with teams like {{company|yours}} on [topic]. Would be glad to connect.</p>'],
                ['type' => 'email', 'delay_days' => 2, 'subject' => 'Following up from LinkedIn',
                    'body' => '<p>Hi {{first_name|there}},</p><p>I just sent you a connection request on LinkedIn. In short: we help {{company|companies like yours}} [result]. Worth a quick chat?</p>'],
                ['type' => 'email', 'delay_days' => 4, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, bumping this up. Happy to share a short case study if that\'s easier.</p>'],
            ],
        ],
        're-engage' => [
            'name' => 'Re-engage old leads',
            'description' => 'For leads who went quiet months ago.',
            'steps' => [
                ['type' => 'email', 'delay_days' => 0, 'subject' => 'Still looking at [topic], {{first_name|there}}?',
                    'body' => '<p>Hi {{first_name|there}},</p><p>We spoke a while ago about [topic]. A lot has changed on our side since: [new thing].</p><p>Is it worth catching up?</p>'],
                ['type' => 'email', 'delay_days' => 5, 'subject' => null,
                    'body' => '<p>Hi {{first_name|there}}, should I close your file for now? A one-word reply is perfect.</p>'],
            ],
        ],
    ];

    public function __construct(
        protected Entitlements $entitlements,
    ) {}

    /**
     * Templates this workspace can use (manual steps need multichannel).
     *
     * @return array<string, array{name: string, description: string, steps: list<array<string, mixed>>}>
     */
    public function available(Workspace $workspace): array
    {
        $multichannel = $this->entitlements->allowsModule($workspace, 'multichannel');

        return array_filter(self::TEMPLATES, fn (array $template): bool => $multichannel
            || collect($template['steps'])->every(fn (array $step): bool => $step['type'] === StepType::Email->value));
    }

    /**
     * A new draft campaign with the template's sequence and safe defaults.
     */
    public function create(Workspace $workspace, string $key, string $name, ?User $by = null): Campaign
    {
        $template = $this->available($workspace)[$key] ?? throw new InvalidArgumentException('That template isn\'t available.');

        return DB::transaction(function () use ($workspace, $template, $name, $by): Campaign {
            $campaign = new Campaign([
                'name' => $name,
                'timezone' => $workspace->timezone ?: 'UTC',
                'send_days' => [1, 2, 3, 4, 5],
                'send_window_start' => '09:00',
                'send_window_end' => '17:00',
                'daily_limit' => 100,
            ]);
            $campaign->workspace_id = $workspace->getKey();
            $campaign->status = CampaignStatus::Draft;
            $campaign->created_by = $by?->getKey();
            $campaign->save();

            foreach ($template['steps'] as $index => $step) {
                $campaign->steps()->create([...$step, 'position' => $index + 1]);
            }

            return $campaign;
        });
    }
}
