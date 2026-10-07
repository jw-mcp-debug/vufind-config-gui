<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/**
 * Error with an HTTP status. The message is a translation key; the API
 * translates it into the user's language before sending it.
 */
class HttpException extends \RuntimeException
{
    /** @param array<string, scalar> $params */
    public function __construct(string $messageKey, public readonly array $params = [], int $status = 400)
    {
        parent::__construct($messageKey, $status);
    }
}
