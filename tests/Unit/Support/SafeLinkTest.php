<?php

use App\Support\SafeLink;

it('links http and https urls', function () {
    expect(SafeLink::parse('https://www.mainteractive.com/contact/'))
        ->toBe(['href' => 'https://www.mainteractive.com/contact/', 'label' => 'mainteractive.com/contact'])
        ->and(SafeLink::parse(' http://acme.test '))->toBe(['href' => 'http://acme.test', 'label' => 'acme.test']);
});

it('shortens very long urls for display only', function () {
    $url = 'https://www.google.com/maps/place/Multi+Arts+Interactive+Web+Design/data=!4m7!3m6!1s0x864c1e3cef4a330d:0x62ec1589c1861c33';
    $link = SafeLink::parse($url);

    expect($link['href'])->toBe($url)
        ->and(mb_strlen($link['label']))->toBeLessThanOrEqual(60);
});

it('links plain email addresses with mailto', function () {
    expect(SafeLink::parse('jane@acme.test'))->toBe(['href' => 'mailto:jane@acme.test', 'label' => 'jane@acme.test']);
});

it('never links anything else', function (?string $value) {
    expect(SafeLink::parse($value))->toBeNull();
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'ftp' => 'ftp://acme.test/file',
    'spaces' => 'https://acme.test/a b',
    'text' => 'web design company in Dallas, TX',
    'protocol only' => 'https://',
    'empty' => '',
    'null' => null,
]);
