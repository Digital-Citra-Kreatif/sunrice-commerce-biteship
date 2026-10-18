<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use RuntimeException;

/** Biteship refused a request or couldn't be reached; the message is Biteship's own. */
class BiteshipException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $errorCode = null)
    {
        parent::__construct($message);
    }
}
