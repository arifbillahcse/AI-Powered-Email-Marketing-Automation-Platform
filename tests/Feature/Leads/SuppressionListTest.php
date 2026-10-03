<?php

use App\Enums\SuppressionReason;
use App\Enums\SuppressionType;
use App\Models\Lead;
use App\Models\Workspace;
use App\Services\Leads\SuppressionList;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->list = app(SuppressionList::class);
});

it('detects emails and domains', function () {
    expect($this->list->parse(' Jane@Acme.com '))->toBe([SuppressionType::Email, 'jane@acme.com'])
        ->and($this->list->parse('@acme.com'))->toBe([SuppressionType::Domain, 'acme.com'])
        ->and($this->list->parse('ACME.com'))->toBe([SuppressionType::Domain, 'acme.com']);
});

it('rejects junk', function (string $entry) {
    $this->list->parse($entry);
})->with(['not an email', 'jane@', 'acme'])->throws(InvalidArgumentException::class);

it('suppresses by email and by whole domain', function () {
    $this->list->add($this->workspace->id, 'jane@acme.com');
    $this->list->add($this->workspace->id, 'blocked.com');

    expect($this->list->isSuppressed($this->workspace->id, 'JANE@acme.com'))->toBeTrue()
        ->and($this->list->isSuppressed($this->workspace->id, 'bob@acme.com'))->toBeFalse()
        ->and($this->list->isSuppressed($this->workspace->id, 'anyone@blocked.com'))->toBeTrue()
        ->and($this->list->isSuppressed(Workspace::factory()->create()->id, 'jane@acme.com'))->toBeFalse();
});

it('excludes suppressed leads from the sendable scope', function () {
    $ok = Lead::factory()->for($this->workspace)->create(['email' => 'ok@fine.com']);
    $byEmail = Lead::factory()->for($this->workspace)->create(['email' => 'jane@acme.com']);
    $byDomain = Lead::factory()->for($this->workspace)->create(['email' => 'ceo@blocked.com']);
    $otherWorkspace = Lead::factory()->create(['email' => 'jane@acme.com']);

    $this->list->add($this->workspace->id, 'jane@acme.com', SuppressionReason::Unsubscribed);
    $this->list->add($this->workspace->id, 'blocked.com');

    expect(Lead::whereNotSuppressed()->pluck('id')->sort()->values()->all())->toBe([$ok->id, $otherWorkspace->id])
        ->and(Lead::whereSuppressed()->pluck('id')->sort()->values()->all())->toBe([$byEmail->id, $byDomain->id]);
});

it('adds many at once, skipping duplicates and invalid lines', function () {
    $result = $this->list->addMany($this->workspace->id, "a@x.com\nb@x.com, a@x.com\nnope\n@y.com", SuppressionReason::Manual);

    expect($result)->toBe(['added' => 3, 'invalid' => ['nope']]);
});
