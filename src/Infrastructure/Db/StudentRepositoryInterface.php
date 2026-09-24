<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Model\Student;

/**
 * Репозиторий студентов (контракт: contracts.md).
 */
interface StudentRepositoryInterface
{
    /** @return Student[] */
    public function findAllByAdmissionYear(int $year): array;
}