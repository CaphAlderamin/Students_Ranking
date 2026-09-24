<?php

declare(strict_types=1);

namespace App\Infrastructure\Seeder;

use App\Infrastructure\Db\PdoFactory;

/**
 * Оркестратор сидера краевых когорт (B2-03, план v4 утверждён).
 *
 * Композиция поверх ядра B2-02: {@see DataSeeder} наполняет базовый каталог,
 * затем EdgeSeeder применяет двухстадийную схему маркеров из {@see CohortCatalog}
 * (стадия 1 — основные выборки по непересекающимся сегментам глобального
 * списка студентов, стадия 2 — пересечение paid ∩ disabled), вставляет
 * недоборный модуль с 3 дисциплинами {3,4,5} и проверяет инвариант
 * Σmax_free ≥ W + H.
 *
 * Файлы ядра B2-02 (SeedCatalog/DataSeeder/StudentGenerator) не изменяются.
 * «Молчуны» не получают DB-флага: их членство материализуется только в
 * CohortCatalog (для B2-04). Служебные колонки/таблицы под когорты не заводятся.
 *
 * Возвращаемый отчёт содержит распределение ID студентов по сегментам
 * каталога и ID недоборного модуля — используется тестом (расщеплённые
 * проверки флаг-счётов и выборок) и раннером scripts/seed.php.
 */
final readonly class EdgeSeeder
{
    /**
     * Оркестратор сидера краевых когорт (B2-03).
     *
     * @param \PDO             $pdo       PDO-соединение (UPDATE/INSERT)
     * @param SeedCatalog      $catalog   базовый каталог (наполняется через DataSeeder)
     * @param CohortCatalog    $cohorts   каталог когорт (сегменты, недоборный модуль)
     * @param StudentGenerator $generator генератор строк студентов
     */
    public function __construct(
        private \PDO $pdo,
        private SeedCatalog $catalog,
        private CohortCatalog $cohorts,
        private StudentGenerator $generator,
    ) {
    }

    /**
     * Создаёт сидер из конфигурации config/db.php (для scripts/seed.php).
     *
     * @param string $configPath путь к файлу конфигурации БД
     */
    public static function fromConfigFile(string $configPath): self
    {
        $factory = PdoFactory::fromConfigFile($configPath);

        return new self($factory->create(), new SeedCatalog(), new CohortCatalog(), new StudentGenerator());
    }

    /**
     * Наполняет БД: базовый каталог + краевые когорты + недоборный модуль.
     *
     * @return array{segmentIds: array<string, list<int>>, moduleIds: list<int>}
     */
    public function seed(): array
    {
        (new DataSeeder($this->pdo, $this->catalog, $this->generator))->seed();

        $studentIds = $this->orderedStudentIds();
        $segmentIds = $this->applyMarkers($studentIds);

        $moduleId = $this->insertUnderEnrollmentModule();
        $this->insertUnderEnrollmentDisciplines($moduleId);

        $this->assertInvariant();

        return [
            'segmentIds' => $segmentIds,
            'moduleIds' => [$moduleId],
        ];
    }

    /**
     * Id всех студентов в порядке вставки (глобальный список rows()).
     *
     * @return list<int>
     */
    private function orderedStudentIds(): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM students ORDER BY id');
        $stmt->execute();
        $values = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new \LogicException('EdgeSeeder: id студента не является скаляром');
            }
            $ids[] = (int) $value;
        }

        return $ids;
    }

    /**
     * Применяет двухстадийную схему маркеров к глобальному списку студентов.
     *
     * Стадия 1: флаги сегментов {@see CohortCatalog::segments()} (основные,
     * непересекающиеся выборки). Стадия 2: пересечение — первые 5 платников
     * по порядку rows() получают is_disabled=1. Возвращает ID выборок для
     * тестов и B2-04.
     *
     * @param list<int> $studentIds
     *
     * @return array<string, list<int>>
     */
    private function applyMarkers(array $studentIds): array
    {
        $segmentIds = [];

        foreach ($this->cohorts->segments() as $segment) {
            $ids = array_slice($studentIds, $segment['offset'], $segment['length']);
            $this->writeFlags($ids, $segment['flags']);
            $segmentIds[$segment['name']] = $ids;
        }

        $paidIds = $segmentIds['paid.base'] ?? [];
        $intersection = array_slice($paidIds, 0, CohortCatalog::PAID_DISABLED_INTERSECTION_COUNT);
        $this->writeFlags($intersection, ['is_disabled' => 1]);
        $segmentIds['paid.intersection'] = $intersection;

        $targetIds = array_merge($segmentIds['target.technical'] ?? [], $segmentIds['target.humanitarian'] ?? []);
        $segmentIds['target.without_application'] = array_slice(
            $targetIds,
            0,
            CohortCatalog::TARGET_WITHOUT_APPLICATION_COUNT,
        );

        return $segmentIds;
    }

    /**
     * Устанавливает DB-флаги для списка студентов (безопасный whitelist имён).
     *
     * @param list<int>             $studentIds
     * @param array<string, int>    $flags map «колонка → 0/1»
     */
    private function writeFlags(array $studentIds, array $flags): void
    {
        if ($studentIds === [] || $flags === []) {
            return;
        }

        $set = [];
        foreach ($flags as $column => $value) {
            $set[] = sprintf('`%s` = %d', $column, $value);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE students SET ' . implode(', ', $set)
            . ' WHERE id IN (' . implode(', ', array_fill(0, count($studentIds), '?')) . ')',
        );
        $stmt->execute($studentIds);
    }

    /**
     * Вставляет недоборный модуль (B2-03, план v4).
     *
     * Правило для B2-04: модуль получает ровно профиль спроса
     * {@see CohortCatalog::underEnrollmentDemand()} — D = 6 заявителей
     * (4×prio1, 2×prio2), детерминированные из базовой популяции (275),
     * непересекаемые с когортами, не подающими заявки.
     */
    private function insertUnderEnrollmentModule(): int
    {
        $module = $this->cohorts->underEnrollmentModule();

        $schoolStmt = $this->pdo->prepare('SELECT id FROM schools WHERE code = ?');
        $schoolStmt->execute([$module['schoolCode']]);
        $schoolId = $schoolStmt->fetchColumn();
        if (!is_string($schoolId) && !is_int($schoolId)) {
            throw new \RuntimeException('EdgeSeeder: школа недоборного модуля не найдена: ' . $module['schoolCode']);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO modules (school_id, title, module_type, min_students, max_students, academic_year)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            (int) $schoolId,
            $module['title'],
            $module['moduleType'],
            $module['minStudents'],
            $module['maxStudents'],
            SeedCatalog::ACADEMIC_YEAR,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Вставляет 3 дисциплины недоборного модуля с семестрами {3, 4, 5} (R-02).
     */
    private function insertUnderEnrollmentDisciplines(int $moduleId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO disciplines (module_id, title, semester) VALUES (?, ?, ?)');

        foreach ([3, 4, 5] as $semester) {
            $stmt->execute([
                $moduleId,
                'Дисциплина модуля «' . $this->cohorts->underEnrollmentModule()['title'] . '», семестр ' . $semester,
                $semester,
            ]);
        }
    }

    /**
     * Проверяет инвариант сидера B2-03: Σmax_sвободных ≥ W + H.
     *
     * @throws \RuntimeException при нарушении (с полным отчётом)
     */
    private function assertInvariant(): void
    {
        $sumMaxFree = 0;
        foreach ($this->catalog->freeModules() as $module) {
            $sumMaxFree += $module['maxStudents'];
        }

        $required = CohortCatalog::W + CohortCatalog::H;
        if ($sumMaxFree < $required) {
            throw new \RuntimeException(
                'EdgeSeeder: нарушен инвариант B2-03: Σmax_sвободных=' . $sumMaxFree
                . ' < W + H = ' . $required . '. Правьте CohortCatalog или профиль заявок (план B2-03).',
            );
        }
    }
}