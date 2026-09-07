<?php

namespace Washi;

/**
 * HTTP error carrying a status code. Thrown anywhere; App turns it into an error response.
 * Use the helper `abort(404, 'message')` in page code.
 */
class HttpException extends \RuntimeException
{
    private const TITLES = [
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict', 410 => 'Gone', 419 => 'Page Expired',
        422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
    ];

    public function __construct(public readonly int $status = 500, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : self::title($status), $status, $previous);
    }

    public static function title(int $status): string
    {
        return self::TITLES[$status] ?? 'Error';
    }
}
