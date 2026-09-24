<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\Enum\ModuleType;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\Semester;

/**
 * Модуль МДС (таблица `modules`).
 *
 * Инварианты: ровно 3 дисциплины (R-02), семестры дисциплин — {3, 4, 5} (ADR-005),
 * вместимость: min ≥ 1, max ≥ min (Q-6, подтверждено пользователем 2026-09-22).
 */
final readonly class Module
{
    public int $id;
    public int $schoolId;
    public string $title;
    public ModuleType $moduleType;
    public int $minStudents;
    public int $maxStudents;
    public AcademicYear $academicYear;

    /** @var list<Discipline> */
    public array $disciplines;

    /**
     * @param int           $id          первичный ключ (> 0)
     * @param int           $schoolId    школа-организатор (ADR-001) (> 0)
     * @param string        $title       название модуля
     * @param ModuleType    $moduleType  тип модуля (R-03)
     * @param int           $minStudents минимум для открытия (R-04)
     * @param int           $maxStudents максимум мест (R-04/R-06)
     * @param AcademicYear  $academicYear учебный год (Q-1: YYYY-YYYY)
     * @param list<Discipline> $disciplines ровно 3 дисциплины на семестры 3, 4, 5 (R-02/ADR-005)
     */
    public function __construct(
        int $id,
        int $schoolId,
        string $title,
        ModuleType $moduleType,
        int $minStudents,
        int $maxStudents,
        AcademicYear $academicYear,
        array $disciplines,
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('Module: id должен быть положительным числом, получено: ' . $id);
        }
        if ($schoolId < 1) {
            throw new \InvalidArgumentException('Module: schoolId должен быть положительным числом, получено: ' . $schoolId);
        }
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Module: title не может быть пустым');
        }
        if ($minStudents < 1) {
            throw new \InvalidArgumentException('Module: minStudents должен быть ≥ 1 (Q-6), получено: ' . $minStudents);
        }
        if ($maxStudents < $minStudents) {
            throw new \InvalidArgumentException(
                'Module: maxStudents должен быть ≥ minStudents (Q-6): min=' . $minStudents . ', max=' . $maxStudents,
            );
        }

        if (count($disciplines) !== 3) {
            throw new \InvalidArgumentException(
                'Module: модуль должен содержать ровно 3 дисциплины (R-02), получено: ' . count($disciplines),
            );
        }
        $semesters = array_map(
            static fn (Discipline $d): int => $d->semester->asInt(),
            $disciplines,
        );
        sort($semesters);
        if ($semesters !== [3, 4, 5]) {
            throw new \InvalidArgumentException(
                'Module: семестры дисциплин должны быть {3, 4, 5} (ADR-005), получено: ' . implode(', ', $semesters),
            );
        }

        $this->id = $id;
        $this->schoolId = $schoolId;
        $this->title = $title;
        $this->moduleType = $moduleType;
        $this->minStudents = $minStudents;
        $this->maxStudents = $maxStudents;
        $this->academicYear = $academicYear;
        $this->disciplines = $disciplines;
    }
}