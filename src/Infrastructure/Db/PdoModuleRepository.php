<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Enum\ModuleType;
use App\Domain\Model\Assignment;
use App\Domain\Model\Discipline;
use App\Domain\Model\Module;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\Semester;

/**
 * Реализация ModuleRepositoryInterface поверх PDO (B2-01).
 *
 * Читает модули и их дисциплины (ровно 3, семестры {3,4,5} — инвариант
 * проверяет конструктор Module), а также сохраняет итоговые назначения
 * студентов на модули (assignments, только final — контракты).
 */
final readonly class PdoModuleRepository implements ModuleRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(PdoFactory $factory)
    {
        $this->pdo = $factory->create();
    }

    /**
     * @return list<Module>
     */
    public function findAll(): array
    {
        $moduleStmt = $this->pdo->prepare(
            'SELECT id, school_id, title, module_type, min_students, max_students, academic_year
             FROM modules
             ORDER BY id',
        );
        $moduleStmt->execute();
        /** @var list<array<string, string|null>> $moduleRows */
        $moduleRows = $moduleStmt->fetchAll(\PDO::FETCH_ASSOC);

        $disciplineStmt = $this->pdo->prepare(
            'SELECT id, module_id, title, semester
             FROM disciplines
             ORDER BY module_id, semester',
        );
        $disciplineStmt->execute();
        /** @var list<array<string, string|null>> $disciplineRows */
        $disciplineRows = $disciplineStmt->fetchAll(\PDO::FETCH_ASSOC);

        /** @var array<int, array<int, Discipline>> $disciplinesByModule */
        $disciplinesByModule = [];
        foreach ($disciplineRows as $row) {
            $moduleId = (int) $row['module_id'];
            $disciplinesByModule[$moduleId][] = new Discipline(
                (int) $row['id'],
                (string) $row['title'],
                new Semester((int) $row['semester']),
            );
        }

        $modules = [];
        foreach ($moduleRows as $row) {
            $id = (int) $row['id'];
            $modules[] = new Module(
                $id,
                (int) $row['school_id'],
                (string) $row['title'],
                ModuleType::from((string) $row['module_type']),
                (int) $row['min_students'],
                (int) $row['max_students'],
                new AcademicYear((string) $row['academic_year']),
                array_values($disciplinesByModule[$id] ?? []),
            );
        }

        return $modules;
    }

    public function saveAssignments(Assignment ...$a): void
    {
        if ($a === []) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO assignments (student_id, module_id, algorithm, source, rank_position, assigned_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($a as $assignment) {
                $stmt->execute([
                    $assignment->studentId,
                    $assignment->moduleId,
                    $assignment->algorithm->value,
                    $assignment->source->value,
                    $assignment->rank,
                ]);
            }
            $this->pdo->commit();
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}