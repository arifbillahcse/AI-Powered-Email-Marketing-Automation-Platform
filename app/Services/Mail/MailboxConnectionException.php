<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * A mailbox connection problem. The message is safe to show to the user.
 */
class MailboxConnectionException extends RuntimeException {}
