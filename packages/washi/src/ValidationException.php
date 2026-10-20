<?php

namespace Washi;

/**
 * Thrown by `$request->validate([...])` / `Validator::orFail()` when input is invalid.
 * App turns it into: JSON 422 `{error, errors}` when the client wants JSON, otherwise the validator's back()
 * (errors + input flashed, redirect to the referer). Not logged — invalid input is not an incident.
 */
class ValidationException extends HttpException
{
    public function __construct(public readonly Validator $validator)
    {
        parent::__construct(422, (string)$validator->firstError());
    }

    public function toResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => $this->getMessage(), 'errors' => $this->validator->errors(), 'status' => 422], 422);
        }
        return $this->validator->back();
    }
}
