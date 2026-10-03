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
        $line = '';

        // fgets stops at the buffer size, so keep reading until the line ends
        // (a SEARCH response can be longer than any buffer).
        do {
            $chunk = @fgets($this->socket, 8192);

            if ($chunk === false) {
                $this->fail();
            }

            $line .= $chunk;
        } while (! str_ends_with($chunk, "\n") && strlen($line) < 10_000_000);

        return rtrim($line, "\r\n");
    }

    public function read(int $bytes): string
    {
        $data = '';

        while (strlen($data) < $bytes) {
            $chunk = @fread($this->socket, min(65536, $bytes - strlen($data)));

            if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                $this->fail();
            }

            if ($chunk === '' && (stream_get_meta_data($this->socket)['timed_out'] ?? false)) {
                $this->fail();
            }

            $data .= $chunk;
        }

        return $data;
    }

    protected function fail(): never
    {
        $timedOut = (stream_get_meta_data($this->socket)['timed_out'] ?? false);

        throw new MailboxConnectionException($timedOut ? 'The IMAP server stopped responding.' : 'The IMAP server closed the connection.');
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
