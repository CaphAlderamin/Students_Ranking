<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Model\Student;

/**
 * Реализация StudentRepositoryInterface поверх PDO (B2-01).
 *
 * Читает студентов по году приёма (student_groups.admission_year) и маппит
 * строки БД в DTO Student. Валидация значений (границы метрик Q-4/ADR-002)
 * выполняется конструктором Student («огненная стена», B1-06) — репозиторий
 * её не дублирует.
 */
final readonly class PdoStudentRepository implements StudentRepositoryInterface
{
    private \PDO $pdo;

    /**
     * Репозиторий студентов поверх PDO (B2-01).
     *
     * @param PdoFactory $factory фабрика PDO-соединения
     */
    public function __construct(PdoFactory $factory)
    {
        $this->pdo = $factory->create();
    }

    /**
     * @return list<Student>
     */
    public function findAllByAdmissionYear(int $year): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.* FROM students s
             JOIN student_groups g ON g.id = s.group_id
             WHERE g.admission_year = ?
             ORDER BY s.id',
        );
        $stmt->execute([$year]);

        /** @var list<array<string, int|string|null>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $students = [];
        foreach ($rows as $row) {
            $students[] = new Student(
                (int) $row['id'],
                (int) $row['group_id'],
                (string) $row['full_name'],
                self::toBool($row['is_target_quota']),
                self::toBool($row['is_paid']),
                self::toBool($row['is_disabled']),
                (int) $row['entrance_exams_sum'],
                (float) $row['gpa_sem12'],
                (float) $row['gpa_basic'],
                (int) $row['entrance_test_score'],
            );
        }

        return $students;
    }

    /**
     * Преобразует TINYINT(1) ('0'/'1' или 0/1) в bool.
     *
     * @param int|string|null $value значение из БД
     */
    private static function toBool(int|string|null $value): bool
    {
        return $value === '1' || $value === 1;
    }
}