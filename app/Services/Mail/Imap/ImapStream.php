<?php

namespace App\Services\Mail\Imap;

interface ImapStream
{
    public function write(string $line): void;

    /**
     * One full line without the CRLF, however long.
     */
    public function readLine(): string;

    /**
     * Exactly $bytes bytes (an IMAP literal).
     */
    public function read(int $bytes): string;

    public function enableTls(): void;

    public function close(): void;
}
