<?php

declare(strict_types=1);

namespace MemiPwa;

final class HttpError extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 400,
        private readonly string $errorCode = 'invalid_request'
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return max(400, min(599, $this->httpStatus));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
