<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use RuntimeException;

/** Biteship refused a request or couldn't be reached; the message is Biteship's own. */
class BiteshipException extends RuntimeException
{
    /** The order reference was used before (details.order_id is that booking). */
    public const REFERENCE_USED = 40002060;

    /** Rates: no courier serves the route, or Biteship doesn't know the postal code. */
    public const NO_RATES = [40001001, 40001010];

    /** @param  array<string, mixed>  $details */
    public function __construct(string $message, public readonly ?int $errorCode = null, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
