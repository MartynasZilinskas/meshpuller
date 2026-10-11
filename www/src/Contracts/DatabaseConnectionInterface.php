<?php

namespace MeshCore\Contracts;

use PDO;

interface DatabaseConnectionInterface
{
    public function getPdo(): PDO;
    public function getConfig(): array;
    public function getDriver(): string;
}
