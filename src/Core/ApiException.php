<?php

/**
 * An error the API reports to the client in the standard format:
 *   { "success": false, "error": { "code": "...", "message": "...", "fields": {...} } }
 *
 * Throw it from anywhere (controller, service, middleware), e.g.:
 *   throw ApiException::notFound('Bidhaa haikupatikana.');
 *   throw ApiException::validation(['phone' => 'Namba ya simu si sahihi.']);
 *   throw ApiException::conflict('OUT_OF_STOCK', 'Bidhaa hii imeisha.');
 */
class ApiException extends RuntimeException
{
    public function __construct(
        private int $status,
        private string $errorCode,
        string $message,
        private array $fields = []
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    // --- Common errors -------------------------------------------------------

    /** 400 — the request itself is broken (e.g. invalid JSON). */
    public static function badRequest(string $code = 'BAD_REQUEST', string $message = 'Ombi si sahihi.'): self
    {
        return new self(400, $code, $message);
    }

    /** 401 — not logged in, or the token/session expired. */
    public static function unauthenticated(string $message = 'Tafadhali ingia kwanza.'): self
    {
        return new self(401, 'UNAUTHENTICATED', $message);
    }

    /** 403 — logged in, but not allowed to do this. */
    public static function forbidden(string $message = 'Huna ruhusa ya kufanya hivi.'): self
    {
        return new self(403, 'FORBIDDEN', $message);
    }

    /** 404 — the thing does not exist (or does not belong to this user). */
    public static function notFound(string $message = 'Hatukupata ulichokitafuta.', string $code = 'NOT_FOUND'): self
    {
        return new self(404, $code, $message);
    }

    /** 405 — the address exists but not with this HTTP method. */
    public static function methodNotAllowed(): self
    {
        return new self(405, 'METHOD_NOT_ALLOWED', 'Njia hii ya ombi haikubaliki.');
    }

    /** 409 — the request conflicts with the current state (price changed, out of stock …). */
    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    /** 422 — one or more fields are invalid. $fields = ['field' => 'message']. */
    public static function validation(array $fields, string $message = 'Tafadhali rekebisha taarifa zilizokosewa.'): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $fields);
    }

    /** 429 — too many requests in a short time. */
    public static function tooManyRequests(string $message = 'Maombi mengi mno. Tafadhali subiri kidogo kisha ujaribu tena.'): self
    {
        return new self(429, 'RATE_LIMITED', $message);
    }
}
