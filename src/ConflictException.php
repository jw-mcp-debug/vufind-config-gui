<?php

declare(strict_types=1);

namespace VuFindConfigGui;

/** The file changed since the client loaded it (HTTP 409). */
class ConflictException extends HttpException
{
    /** @param array<string, scalar> $params */
    public function __construct(string $messageKey, array $params = [])
    {
        parent::__construct($messageKey, $params, 409);
    }
}
