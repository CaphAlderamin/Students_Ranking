<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Domain\Model\DistributionResult;
use App\Domain\Model\Module;

/**
 * Собиратель отчёта по школам-организаторам (B4-02, R-18, ADR-001).
 *
 * Преобразует результат распределения в пакеты экспортных файлов: файл на пару
 * (школа-организатор, учебный год) — год участвует в имени файла (ADR-003).
 * Каждое назначение разворачивается на ровно 3 строки — по дисциплине модуля на
 * семестры {3, 4, 5} (R-02/ADR-005), независимо от источника зачисления
 * (Competition/Free/TargetQuota — R-18 не различает источники, ADR-001 требует
 * «полный список студентов своих дисциплин»).
 *
 * Заголовок файла — часть строк ассемблера (§2.2 плана R-18):
 * ['academic_year', 'semester', 'discipline_id', 'student_id'].
 *
 * Детерминизм: группы (школа, год) по коду школы asc (тибрейк schoolId) и году asc;
 * строки внутри файла — по (moduleId, semester, studentId) asc. Неполный каталог —
 * ошибка связки (\InvalidArgumentException), а не молчаливый пропуск строки (R-11).
 *
 * Каталоги приходят через конструктор (решение B4-01): на этапе связки (B4-05)
 * их построит обвязка из ModuleRepositoryInterface и справочника кодов школ.
 */
final readonly class SchoolReportAssembler implements ExportReportAssemblerInterface
{
    /** Заголовок файла — имена колонок R-18; импорт B4-03 будет ориентироваться на них. */
    private const array R_18_HEADER = ['academic_year', 'semester', 'discipline_id', 'student_id'];

    /** @var array<int, Module> каталог модулей: moduleId → Module */
    private array $modules;

    /** @var array<int, string> коды школ: schoolId → код (справочник `school-codes`) */
    private array $schoolCodes;

    /**
     * @param array<int, Module> $modules     каталог модулей (ключ — id модуля)
     * @param array<int, string> $schoolCodes коды школ (ключ — id школы-организатора)
     */
    public function __construct(array $modules, array $schoolCodes)
    {
        $this->modules = $modules;
        $this->schoolCodes = $schoolCodes;
    }

    /** @return list<ExportReportFile> */
    public function assemble(DistributionResult $result): array
    {
        // Ведро: (schoolId, уч.год) → туплы [moduleId, semester, studentId, disciplineId];
        // первые три — ключи детерминированной сортировки строк.
        $buckets = [];
        foreach ($result->assignments as $assignment) {
            $module = $this->modules[$assignment->moduleId] ?? throw new \InvalidArgumentException(
                'SchoolReportAssembler: каталог неполон — нет модуля #' . $assignment->moduleId,
            );
            $academicYear = $module->academicYear->toString();
            foreach ($module->disciplines as $discipline) {
                $buckets[$module->schoolId][$academicYear][] = [
                    $module->id,
                    $discipline->semester->asInt(),
                    $assignment->studentId,
                    $discipline->id,
                ];
            }
        }

        // Валидация кодов до сортировки: ошибка связки не должна гаситься undefined key в сравнении.
        foreach (array_keys($buckets) as $schoolId) {
            if (!isset($this->schoolCodes[$schoolId])) {
                throw new \InvalidArgumentException(
                    'SchoolReportAssembler: школа #' . $schoolId . ' вне карты кодов',
                );
            }
        }

        // Группы сортируем по коду школы asc (тибрейк schoolId) — детерминизм имён и порядка.
        uksort(
            $buckets,
            function (int $left, int $right): int {
                $byCode = strcmp($this->schoolCodes[$left], $this->schoolCodes[$right]);

                return $byCode !== 0 ? $byCode : $left <=> $right;
            },
        );

        $files = [];
        foreach ($buckets as $schoolId => $byYear) {
            $code = $this->schoolCodes[$schoolId];
            // Годы внутри школы — asc (YYYY-YYYY сортируется лексографически = хронологически).
            ksort($byYear);
            foreach ($byYear as $academicYear => $tuples) {
                // Строки: (moduleId, semester, studentId) asc.
                usort(
                    $tuples,
                    fn (array $left, array $right): int => [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]],
                );
                $rows = [self::R_18_HEADER];
                foreach ($tuples as $tuple) {
                    $rows[] = [
                        $academicYear,
                        (string) $tuple[1],
                        (string) $tuple[3],
                        (string) $tuple[2],
                    ];
                }
                $files[] = new ExportReportFile($code, $academicYear, $rows);
            }
        }

        return $files;
    }
}