<?php

namespace App\Repositories;

use App\Repositories\Contracts\DatabaseHealthRepository;
use Illuminate\Database\ConnectionInterface;

class PostgresDatabaseHealthRepository implements DatabaseHealthRepository
{
    public function __construct(private ConnectionInterface $connection) {}

    public function isAvailable(): bool
    {
        return $this->connection->getDriverName() === 'pgsql'
            && (int) $this->connection->selectOne('SELECT 1 AS available')->available === 1;
    }
}
