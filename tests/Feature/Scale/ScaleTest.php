<?php

use App\Enums\EmailEventType;
use App\Jobs\SendCampaignEmail;
use App\Models\CampaignLead;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Services\Archive\EventArchiver;
use App\Services\Sending\SendScheduler;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('archives old events to gzipped files and deletes them', function () {
    Storage::fake('local');
    fakeTransport();
    ['mailbox' => $mailbox] = readyCampaign();
    SendCampaignEmail::dispatchSync(CampaignLead::sole()->id, $mailbox->id, 1);
    $message = EmailMessage::sole();

    $this->travelTo(now()->subDays(200));
    $old = $message->recordEvent(EmailEventType::Open);
    $this->travelBack();
    $recent = $message->recordEvent(EmailEventType::Click, 'https://softorio.com');

    $archived = app(EventArchiver::class)->run();

    expect($archived)->toBe(1)
        ->and(EmailEvent::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(EmailEvent::query()->whereKey($recent->id)->exists())->toBeTrue();

    $files = Storage::disk('local')->allFiles(EventArchiver::DIRECTORY);
    expect($files)->toHaveCount(1)
        ->and($files[0])->toContain($old->created_at->format('Y-m').'/');

    $line = json_decode(trim((string) gzdecode(Storage::disk('local')->get($files[0]))), true);
    expect((int) $line['id'])->toBe($old->id)->and($line['type'])->toBe('open');
});

it('can be switched off', function () {
    config(['outreach.archive.events_after_days' => 0]);

    expect(app(EventArchiver::class)->run())->toBe(0);
});

it('sends from the priority queue for workspaces with dedicated sending', function () {
    Queue::fake();
    ['workspace' => $workspace] = readyCampaign();
    $workspace->forceFill(['dedicated_sending' => true])->save();

    app(SendScheduler::class)->tick();

    Queue::assertPushedOn('sending-priority', SendCampaignEmail::class);
});
