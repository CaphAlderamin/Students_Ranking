<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Model\Application;

/**
 * Репозиторий заявок (контракт: contracts.md).
 */
interface ApplicationRepositoryInterface
{
    /** @return Application[] */
    public function findAll(): array;
}