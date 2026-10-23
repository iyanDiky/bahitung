<?php

namespace App\Services;

use App\Repositories\Contracts\DatabaseHealthRepository;
use Throwable;

class HealthService
{
    public function __construct(private DatabaseHealthRepository $database) {}

    public function databaseIsAvailable(): bool
    {
        try {
            return $this->database->isAvailable();
        } catch (Throwable) {
            return false;
        }
    }
}
