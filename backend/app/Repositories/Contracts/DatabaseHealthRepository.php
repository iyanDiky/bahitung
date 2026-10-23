<?php

namespace App\Repositories\Contracts;

interface DatabaseHealthRepository
{
    public function isAvailable(): bool;
}
