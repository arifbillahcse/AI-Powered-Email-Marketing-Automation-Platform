<?php

namespace App\Filament\App\Actions;

use App\Models\Lead;
use App\Services\Leads\FollowUps;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * "Follow up": pick the day to come back to this lead. You get a
 * notification that morning (workspace time zone), and the lead shows
 * under the "Follow-up due" filter until you mark it done.
 */
class SetLeadFollowUpAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'setFollowUp';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Lead $record): string => $record->follow_up_on ? 'Change follow-up' : 'Follow up')
            ->icon(Heroicon::OutlinedBell)
            ->color('gray')
            ->authorize('update')
            ->modalHeading(fn (Lead $record): string => 'Follow up with '.($record->fullName() ?: $record->email))
            ->modalDescription('You\'ll get a notification on that day, and the lead shows under the "Follow-up due" filter.')
            ->modalSubmitActionLabel('Save follow-up')
            ->fillForm(function (Lead $record): array {
                $today = CarbonImmutable::parse(app(FollowUps::class)->today(Filament::getTenant()));
                $current = $record->follow_up_on ? CarbonImmutable::parse($record->follow_up_on) : null;

                return ['on' => ($current && $current->greaterThanOrEqualTo($today) ? $current : $today->addDays(3))->toDateString()];
            })
            ->schema(fn (Lead $record): array => [
                DatePicker::make('on')
                    ->label('Follow up on')
                    ->native(false)
                    ->minDate(app(FollowUps::class)->today(Filament::getTenant()))
                    ->required(),
            ])
            ->action(function (Lead $record, array $data): void {
                app(FollowUps::class)->set($record, $data['on'], Auth::user());

                Notification::make()->title('Follow-up set for '.CarbonImmutable::parse($data['on'])->format('M j, Y'))->success()->send();
            });
    }
}
