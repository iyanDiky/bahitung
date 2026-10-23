<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class IdempotencyConflictException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'Kunci permintaan sudah dipakai untuk data lain. Gunakan kunci baru.');
    }
}
