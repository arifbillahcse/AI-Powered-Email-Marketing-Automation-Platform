<?php

namespace App\Services\Mail\Imap;

interface ImapStream
{
    public function write(string $line): void;

    public function readLine(): string;

    public function enableTls(): void;

    public function close(): void;
}
