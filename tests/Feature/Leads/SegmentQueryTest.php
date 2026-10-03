<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadList;
use App\Models\Segment;
use App\Models\Workspace;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->lead = fn (array $attributes) => Lead::factory()->for($this->workspace)->create($attributes);
});

function segmentIds(Segment $segment): array
{
    return $segment->leadsQuery()->orderBy('leads.id')->pluck('leads.id')->all();
}

it('matches text rules case-insensitively', function () {
    $acme = ($this->lead)(['company' => 'ACME Corp', 'title' => 'Founder']);
    ($this->lead)(['company' => 'Globex', 'title' => 'CTO']);

    $segment = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'company', 'operator' => 'contains', 'value' => 'acme'],
    ]]);

    expect(segmentIds($segment))->toBe([$acme->id]);
});

it('combines rules with all or any', function () {
    $both = ($this->lead)(['country' => 'Bangladesh', 'title' => 'Founder']);
    $countryOnly = ($this->lead)(['country' => 'Bangladesh', 'title' => 'Engineer']);
    ($this->lead)(['country' => 'Germany', 'title' => 'Engineer']);

    $rules = [
        ['field' => 'country', 'operator' => 'equals', 'value' => 'bangladesh'],
        ['field' => 'title', 'operator' => 'starts_with', 'value' => 'Found'],
    ];

    $all = Segment::factory()->for($this->workspace)->create(['match' => 'all', 'rules' => $rules]);
    $any = Segment::factory()->for($this->workspace)->create(['match' => 'any', 'rules' => $rules]);

    expect(segmentIds($all))->toBe([$both->id])
        ->and(segmentIds($any))->toBe([$both->id, $countryOnly->id]);
});

it('treats % and _ in values literally', function () {
    $literal = ($this->lead)(['company' => '100% Growth']);
    ($this->lead)(['company' => '1000 Growth']);

    $segment = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'company', 'operator' => 'contains', 'value' => '100%'],
    ]]);

    expect(segmentIds($segment))->toBe([$literal->id]);
});

it('filters on status, lists, tags, dates and emptiness', function () {
    $list = LeadList::factory()->for($this->workspace)->create();
    $match = ($this->lead)(['status' => LeadStatus::Interested, 'phone' => null, 'company' => 'Has Co']);
    $match->lists()->attach($list);
    $match->syncTagNames(['hot']);

    $other = ($this->lead)(['status' => LeadStatus::New, 'company' => '']);
    $other->syncTagNames(['cold']);

    $segment = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'status', 'operator' => 'equals', 'value' => 'interested'],
        ['field' => 'list', 'operator' => 'in', 'value' => (string) $list->id],
        ['field' => 'tag', 'operator' => 'has', 'value' => 'HOT'],
        ['field' => 'created_at', 'operator' => 'after', 'value' => now()->subDay()->toDateString()],
        ['field' => 'company', 'operator' => 'is_not_empty'],
    ]]);

    $empty = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'company', 'operator' => 'is_empty'],
        ['field' => 'tag', 'operator' => 'not_has', 'value' => 'hot'],
    ]]);

    expect(segmentIds($segment))->toBe([$match->id])
        ->and(segmentIds($empty))->toBe([$other->id]);
});

it('filters on custom fields', function () {
    $big = ($this->lead)(['custom_fields' => ['company_size' => '51-200']]);
    ($this->lead)(['custom_fields' => ['company_size' => '1-10']]);
    ($this->lead)(['custom_fields' => null]);

    $segment = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'custom', 'key' => 'Company Size', 'operator' => 'equals', 'value' => '51-200'],
    ]]);

    expect(segmentIds($segment))->toBe([$big->id]);
});

it('never includes another workspace\'s leads', function () {
    Lead::factory()->create(['company' => 'Acme']);

    $segment = Segment::factory()->for($this->workspace)->create(['rules' => [
        ['field' => 'company', 'operator' => 'contains', 'value' => 'acme'],
    ]]);

    expect(segmentIds($segment))->toBe([]);
});
