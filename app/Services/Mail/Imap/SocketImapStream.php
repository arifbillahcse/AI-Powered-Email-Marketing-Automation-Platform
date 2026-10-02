<?php

namespace App\Services\Mail\Imap;

use App\Services\Mail\MailboxConnectionException;

class SocketImapStream implements ImapStream
{
    /**
     * @param  resource  $socket
     */
    public function __construct(
        protected $socket,
    ) {}

    public function write(string $line): void
    {
        if (@fwrite($this->socket, $line."\r\n") === false) {
            throw new MailboxConnectionException('The IMAP server closed the connection.');
        }
    }

    public function readLine(): string
    {
        $line = @fgets($this->socket, 8192);

        if ($line === false) {
            $timedOut = (stream_get_meta_data($this->socket)['timed_out'] ?? false);

            throw new MailboxConnectionException($timedOut ? 'The IMAP server stopped responding.' : 'The IMAP server closed the connection.');
        }

        return rtrim($line, "\r\n");
    }

    public function enableTls(): void
    {
        if (! @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new MailboxConnectionException('Couldn\'t start a secure (STARTTLS) IMAP connection.');
        }
    }

    public function close(): void
    {
        @fclose($this->socket);
    }
}
