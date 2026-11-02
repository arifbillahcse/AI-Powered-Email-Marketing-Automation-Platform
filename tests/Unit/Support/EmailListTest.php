<?php

use App\Support\EmailList;

it('reads every usable address out of a cell', function () {
    expect(EmailList::all('Info@Acme.com; sales@acme.com, <support@acme.com>  info@acme.com'))
        ->toBe(['info@acme.com', 'sales@acme.com', 'support@acme.com']);
});

it('drops website placeholders, file names and version numbers', function () {
    expect(EmailList::all('name@company.com; ionicons@4.2.2; logo@2x.png; user@example.com; real@acme.io; bad@@x.com; plain'))
        ->toBe(['real@acme.io']);
});

it('picks the first address that is not a complaints desk', function () {
    expect(EmailList::primary('abuse@acme.com; privacy@acme.com; rohit@acme.com; sales@acme.com'))->toBe('rohit@acme.com')
        ->and(EmailList::primary('dpo+eu@acme.com; sales@acme.com'))->toBe('sales@acme.com');
});

it('falls back to a desk address when it is the only one', function () {
    expect(EmailList::primary('abuse@acme.com; tac@acme.com'))->toBe('abuse@acme.com');
});

it('returns nothing when there is no usable address', function () {
    expect(EmailList::primary('n/a'))->toBeNull()
        ->and(EmailList::primary(''))->toBeNull()
        ->and(EmailList::primary(null))->toBeNull()
        ->and(EmailList::all(null))->toBe([]);
});
