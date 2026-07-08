<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiErrorResponder
{
    /**
     * Standard API error envelope.
     *
     * @param array<string, mixed> $extra
     */
    public static function error(
        string $message,
        int $status = 500,
        ?string $code = null,
        array $extra = [],
    ): JsonResponse {
        $payload = array_merge([
            'success' => false,
            'message' => $message,
        ], $extra);

        if ($code) {
            $payload['code'] = $code;
        }

        return response()->json($payload, $status);
    }

    /**
     * Convert a Throwable into a user-friendly API error response.
     */
    public static function fromThrowable(Throwable $e, string $fallbackMessage): JsonResponse
    {
        // Validation (when not already handled by controllers)
        if ($e instanceof ValidationException) {
            return self::error(
                message: $e->validator->errors()->first(),
                status: 422,
                code: 'validation_failed',
                extra: ['errors' => $e->errors()],
            );
        }

        // Http exceptions (404, 403, etc.)
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $message = $status === 404
                ? 'Not found.'
                : ($fallbackMessage ?: 'Request failed.');

            return self::error($message, $status, 'http_error');
        }

        // Gemini / network connectivity
        if ($e instanceof ConnectionException || str_contains(strtolower($e->getMessage()), 'generativelanguage.googleapis.com')) {
            return self::error(
                message: 'Generation is temporarily unavailable because the AI service could not be reached. Please try again in a few minutes.',
                status: 503,
                code: 'ai_unavailable',
            );
        }

        // Default (do not leak internal error details in production)
        $debug = config('app.debug') === true;
        $message = $debug
            ? ($e->getMessage() ?: $fallbackMessage)
            : ($fallbackMessage ?: 'An unexpected error occurred. Please try again.');

        return self::error($message, 500, 'unexpected_error');
    }
}

