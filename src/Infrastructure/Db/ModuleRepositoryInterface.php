<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Model\Assignment;
use App\Domain\Model\Module;

/**
 * Репозиторий модулей (контракт: contracts.md).
 */
interface ModuleRepositoryInterface
{
    /** @return Module[] */
    public function findAll(): array;

    /**
     * Сохраняет итоговые назначения студентов на модули (таблица assignments).
     *
     * @param Assignment ...$a назначения финального распределения (B3-05, только final)
     */
    public function saveAssignments(Assignment ...$a): void;
}