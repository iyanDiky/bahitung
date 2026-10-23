<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FinancialException extends HttpException
{
    public function __construct(public readonly string $errorCode, string $message, int $status = 422)
    {
        parent::__construct($status, $message);
    }
}
