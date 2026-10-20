<?php

namespace Washi;

/**
 * HTTP error carrying a status code. Thrown anywhere; App turns it into an error response.
 * Use the helper `abort(404, 'message')` in page code.
 */
class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status = 500, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : self::title($status), $status, $previous);
    }

    public static function title(int $status): string
    {
        return Response::reason($status) ?: 'Error';
    }
}
