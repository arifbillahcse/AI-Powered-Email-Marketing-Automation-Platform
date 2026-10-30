<?php

namespace App\Services\Leads;

use App\Enums\LeadActivityType;
use App\Filament\App\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Compliance\WorkspaceAlerts;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * "Follow up with this lead on <date>". The date is a calendar day in the
 * workspace's time zone, so "today" means the same thing to the whole team.
 * Due leads are notified once, in the workspace's morning.
 */
class FollowUps
{
    /** Local hour from which due follow-ups are announced. */
    public const NOTIFY_FROM_HOUR = 8;

    public function __construct(
        protected WorkspaceAlerts $alerts,
    ) {}

    /**
     * Today's date in the workspace's time zone, as Y-m-d.
     */
    public function today(Workspace $workspace): string
    {
        return CarbonImmutable::now($workspace->timezone ?: 'UTC')->toDateString();
    }

    public function set(Lead $lead, CarbonInterface|string $date, ?User $by = null): void
    {
        $date = CarbonImmutable::parse($date)->startOfDay();

        $lead->forceFill([
            'follow_up_on' => $date->toDateString(),
            'follow_up_by' => $by?->getKey(),
            'follow_up_notified_at' => null,
        ])->save();

        $lead->logActivity(LeadActivityType::FollowUpSet, 'Follow-up set for '.$date->format('M j, Y'), ['on' => $date->toDateString()]);
    }

    public function done(Lead $lead): void
    {
        if ($lead->follow_up_on === null) {
            return;
        }

        $lead->forceFill(['follow_up_on' => null, 'follow_up_by' => null, 'follow_up_notified_at' => null])->save();
        $lead->logActivity(LeadActivityType::FollowUpDone, 'Follow-up done');
    }

    /**
     * Leads whose follow-up date has come, in one workspace.
     *
     * @return Builder<Lead>
     */
    public function due(Workspace $workspace): Builder
    {
        return Lead::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereNotNull('follow_up_on')
            ->where('follow_up_on', '<=', $this->today($workspace));
    }

    /**
     * Announce follow-ups that came due, once each: to the person who set
     * them, or to the owners and admins when that person is gone.
     *
     * @return int Leads announced
     */
    public function notifyDue(): int
    {
        $announced = 0;

        $workspaceIds = Lead::query()
            ->whereNotNull('follow_up_on')
            ->whereNull('follow_up_notified_at')
            ->distinct()
            ->pluck('workspace_id');

        Workspace::query()->whereIn('id', $workspaceIds)->each(function (Workspace $workspace) use (&$announced): void {
            if (CarbonImmutable::now($workspace->timezone ?: 'UTC')->hour < self::NOTIFY_FROM_HOUR) {
                return;
            }

            $leads = $this->due($workspace)->whereNull('follow_up_notified_at')->orderBy('follow_up_on')->get(['id', 'email', 'first_name', 'last_name', 'follow_up_by']);

            foreach ($leads->groupBy(fn (Lead $lead): string => (string) $lead->follow_up_by) as $userId => $group) {
                $this->announce($workspace, $userId === '' ? null : (int) $userId, $group->all());
            }

            Lead::query()->whereKey($leads->modelKeys())->update(['follow_up_notified_at' => now()]);
            $announced += $leads->count();
        });

        return $announced;
    }

    /**
     * @param  list<Lead>  $leads
     */
    protected function announce(Workspace $workspace, ?int $userId, array $leads): void
    {
        $count = count($leads);
        $title = Number::format($count).' '.str('follow-up')->plural($count).' due';
        $names = collect($leads)->take(5)->map(fn (Lead $lead): string => $lead->fullName() ?: $lead->email)->implode(', ');
        $body = $names.($count > 5 ? ' and '.Number::format($count - 5).' more' : '');
        $url = LeadResource::getUrl('index', ['filters' => ['follow_up_due' => ['isActive' => true]]], panel: 'app', tenant: $workspace);
        $user = $userId ? User::query()->find($userId) : null;

        if ($user === null) {
            $this->alerts->send($workspace->getKey(), $title, $body, $url, 'Open leads', 'warning');

            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->warning()
            ->actions([Action::make('open')->label('Open leads')->url($url)])
            ->sendToDatabase($user);
    }
}
