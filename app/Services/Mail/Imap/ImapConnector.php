<?php

namespace App\Services\Mail\Imap;

use App\Enums\MailEncryption;
use App\Services\Mail\MailboxConnectionException;

class ImapConnector
{
    public function __construct(
        protected int $timeout = 10,
    ) {}

    public function connect(string $host, int $port, MailEncryption $encryption): ImapStream
    {
        $scheme = $encryption === MailEncryption::Ssl ? 'ssl' : 'tcp';

        $socket = @stream_socket_client(
            "{$scheme}://{$host}:{$port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]),
        );

        if ($socket === false) {
            throw new MailboxConnectionException("Couldn't connect to {$host}:{$port} ({$errstr}).");
        }

        stream_set_timeout($socket, $this->timeout);

        return new SocketImapStream($socket);
    }
}
