<?php

declare(strict_types=1);

namespace CloudControl\Shared\Http;

use CloudControl\Shared\Error\CloudException;
use support\Response;

final class ApiErrorResponder
{
    public function respond(CloudException $exception, string $requestId): Response
    {
        return json([
            'code' => 0,
            'error_code' => $exception->errorCode->value,
            'msg' => $exception->safeMessage(),
            'request_id' => $requestId,
            'retryable' => $exception->retryable,
            'data' => $exception->data,
        ])->withStatus($exception->httpStatus);
    }
}
