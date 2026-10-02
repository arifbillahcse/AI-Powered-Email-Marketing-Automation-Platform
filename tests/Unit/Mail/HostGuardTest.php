<?php

use App\Services\Mail\HostGuard;
use App\Services\Mail\MailboxConnectionException;

it('blocks private, loopback, link-local and reserved addresses', function (string $host) {
    (new HostGuard)->assertAllowed($host);
})->with([
    '127.0.0.1',
    '10.0.0.5',
    '172.16.3.4',
    '192.168.1.1',
    '169.254.169.254', // cloud metadata
    '0.0.0.0',
    '::1',
])->throws(MailboxConnectionException::class);

it('allows public addresses', function () {
    (new HostGuard)->assertAllowed('8.8.8.8');

    expect(true)->toBeTrue();
});

it('rejects malformed hostnames', function () {
    (new HostGuard)->assertAllowed('smtp.example.com/../evil');
})->throws(MailboxConnectionException::class, 'valid server hostname');

it('allows anything when private hosts are enabled (local dev)', function () {
    (new HostGuard(allowPrivateHosts: true))->assertAllowed('127.0.0.1');

    expect(true)->toBeTrue();
});
