<?php

declare(strict_types=1);

namespace App\Infrastructure\Seeder;

use App\Domain\Enum\ModuleType;
use App\Infrastructure\Db\PdoFactory;

/**
 * Генератор заявок сидера (B2-04, план v2 утверждён).
 *
 * @see structure/tasks/plans/plan-B2-04.md
 *
 * Строит детерминированный профиль заявок поверх когорт B2-03 (отчёт
 * EdgeSeeder): 352 заявки / 1056 items — у КАЖДОГО заявителя ровно по
 * 3 приоритета (примечание пользователя #3, план не меняется); окно подачи
 * R-12 — 1 учебная неделя; только собственный тип модуля (R-13); свободные
 * модули в заявки не входят (свободные — только фаза 4, R-09/R-10).
 *
 * Профили (детерминированные, без Faker):
 *  - «молчуны» (silent.non_target 40 + target.without_application 8) заявок
 *    не подают (обязательство #1 плана B2-03);
 *  - недоборный модуль (id из отчёта EdgeSeeder) получает ровно D = 6
 *    заявителей из базовой популяции (275): 4×приоритет 1 + 2×приоритет 2
 *    ({@see CohortCatalog::underEnrollmentDemand()}); следующие приоритеты
 *    валидны (R-07 — каскад закрытого модуля);
 *  - под-профиль хвоста T = 12: студенты базовой популяции (275, вне когорт),
 *    все 3 приоритета — на модули-концентраторы перегрузки (спрос по первому
 *    приоритету > max), H_actual = T = 12 ≤ H = 30, Σmax_free 135 ≥ W + H_actual
 *    = 52 — путь «вытеснение → свободные» (R-09) на сид-данных (примечания
 *    пользователя #1, #2);
 *  - остальные 334 заявителя — обычный профиль своего типа без перегрузки
 *    по первому приоритету (остаток перегрузки = 0).
 *
 * Детерминизм: выбор модулей — фиксированные функции от позиции студента
 * (порядок rows()/id); submitted_at = окно + индекс заявителя ×
 * SUBMISSION_STEP_SECONDS (упорядоченные таймстмпсы для тай-брейка алгоритма
 * «по дате», R-17/Q-02).
 */
final readonly class ApplicationSeeder
{
    /** Окно подачи (R-12): первая учебная неделя осени 2026. */
    public const string APPLICATION_WINDOW_START = '2026-09-07 09:00:00';
    public const string APPLICATION_WINDOW_END = '2026-09-13 18:00:00';

    /** Шаг submitted_at по позиции заявителя (детерминизм, Q-02). */
    private const int SUBMISSION_STEP_SECONDS = 900;

    /** Под-профиль хвоста: ровно 12 студентов (H_actual = T = 12 ≤ H = 30). */
    private const int TAIL_PROFILE_COUNT = 12;

    /** Число модулей-концентраторов перегрузки (3 технических с большим спросом). */
    private const int CONCENTRATOR_COUNT = 3;

    /**
     * Генератор заявок сидера (B2-04).
     *
     * @param \PDO          $pdo     PDO-соединение для вставки заявок и элементов
     * @param CohortCatalog $cohorts каталог когорт (профиль недоборного модуля)
     */
    public function __construct(
        private \PDO $pdo,
        private CohortCatalog $cohorts,
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

        return new self($factory->create(), new CohortCatalog());
    }

    /**
     * Генерирует заявки поверх когорт каталога (вызов — после EdgeSeeder::seed()).
     *
     * @param array<string, list<int>> $segmentIds распределение ID студентов по сегментам (отчёт EdgeSeeder)
     * @param list<int>                $moduleIds  [недоборный модуль] (отчёт EdgeSeeder)
     *
     * @return array{applications: int, items: int, windowStart: non-empty-string,
     *               windowEnd: non-empty-string, underEnrollmentCount: int}
     */
    public function seed(array $segmentIds, array $moduleIds): array
    {
        $underEnrollmentId = $moduleIds[0];
        $moduleTypes = $this->moduleTypes();
        $moduleLimits = $this->moduleLimits();

        $technicalIds = [];
        $humanitarianIds = [];
        foreach ($moduleTypes as $moduleId => $type) {
            if ($moduleId === $underEnrollmentId) {
                continue;
            }
            if ($type === ModuleType::Technical) {
                $technicalIds[] = $moduleId;
            } elseif ($type === ModuleType::Humanitarian) {
                $humanitarianIds[] = $moduleId;
            }
        }
        $this->requireCount($technicalIds, SeedCatalog::TECHNICAL_MODULE_COUNT, 'регулярных технических модулей');
        $this->requireCount($humanitarianIds, SeedCatalog::HUMANITARIAN_MODULE_COUNT, 'гуманитарных модулей');

        $concentrators = $this->selectConcentrators($technicalIds, $moduleLimits);
        $this->requireCount($concentrators, self::CONCENTRATOR_COUNT, 'модулей-концентраторов');

        $studentTypes = $this->studentsByTechnicalFlag();
        $allStudentIds = array_keys($studentTypes);
        $this->requireCount($allStudentIds, SeedCatalog::STUDENT_COUNT, 'студентов');

        $silentIds = array_merge(
            $segmentIds['silent.non_target'] ?? [],
            $segmentIds['target.without_application'] ?? [],
        );
        $this->requireCount(
            $silentIds,
            CohortCatalog::SILENT_NON_TARGET_COUNT + CohortCatalog::TARGET_WITHOUT_APPLICATION_COUNT,
            '«молчунов» (40 + 8)',
        );
        $silent = array_fill_keys($silentIds, true);

        $cohortIds = $this->distinctCohortIds($segmentIds);
        $this->requireCount($cohortIds, 125, 'различных студентов когорт (125)');
        $cohort = array_fill_keys($cohortIds, true);

        $baseTechnicalIds = [];
        $technicalTotal = 0;
        foreach ($allStudentIds as $studentId) {
            if ($studentTypes[$studentId]) {
                ++$technicalTotal;
                if (!isset($cohort[$studentId])) {
                    $baseTechnicalIds[] = $studentId;
                }
            }
        }
        $cohortTechnical = 0;
        foreach ($cohortIds as $studentId) {
            if (isset($studentTypes[$studentId]) && $studentTypes[$studentId]) {
                ++$cohortTechnical;
            }
        }
        $this->requireCount(
            $baseTechnicalIds,
            $technicalTotal - $cohortTechnical,
            'технических студентов базовой популяции',
        );

        $demand = $this->cohorts->underEnrollmentDemand();
        $demandIds = array_slice($baseTechnicalIds, 0, $demand['count']);
        $this->requireCount($demandIds, $demand['count'], 'заявителей недоборного модуля (D = 6)');
        $demandSet = array_fill_keys($demandIds, true);
        $tailIds = array_slice($baseTechnicalIds, $demand['count'], self::TAIL_PROFILE_COUNT);
        $this->requireCount($tailIds, self::TAIL_PROFILE_COUNT, 'студентов под-профиля хвоста (T = 12)');
        $tail = array_fill_keys($tailIds, true);

        $applicantIds = [];
        foreach ($allStudentIds as $studentId) {
            if (!isset($silent[$studentId])) {
                $applicantIds[] = $studentId;
            }
        }
        $this->requireCount(
            $applicantIds,
            SeedCatalog::STUDENT_COUNT
                - (CohortCatalog::SILENT_NON_TARGET_COUNT + CohortCatalog::TARGET_WITHOUT_APPLICATION_COUNT),
            'заявителей (352)',
        );

        $applicationStmt = $this->pdo->prepare('INSERT INTO applications (student_id, submitted_at) VALUES (?, ?)');
        $itemStmt = $this->pdo->prepare(
            'INSERT INTO application_items (application_id, module_id, priority) VALUES (?, ?, ?)',
        );

        $submittedAt = new \DateTimeImmutable(self::APPLICATION_WINDOW_START);
        $demandIndex = 0;
        $tailIndex = 0;
        $normalTechnicalIndex = 0;
        $normalHumanitarianIndex = 0;
        $applications = 0;
        $items = 0;
        $underEnrollmentCount = 0;

        foreach ($applicantIds as $studentId) {
            if (isset($tail[$studentId])) {
                $profile = $this->tailProfile($tailIndex, $concentrators);
                ++$tailIndex;
            } elseif (isset($demandSet[$studentId])) {
                $profile = $this->demandProfile($demandIndex, $underEnrollmentId, $technicalIds);
                ++$underEnrollmentCount;
                ++$demandIndex;
            } elseif ($studentTypes[$studentId]) {
                $profile = $this->normalTechnicalProfile($normalTechnicalIndex, $technicalIds, $concentrators, $moduleLimits);
                ++$normalTechnicalIndex;
            } else {
                $profile = $this->normalHumanitarianProfile($normalHumanitarianIndex, $humanitarianIds);
                ++$normalHumanitarianIndex;
            }

            $applicationStmt->execute([$studentId, $submittedAt->format('Y-m-d H:i:s')]);
            $applicationId = (int) $this->pdo->lastInsertId();
            ++$applications;

            foreach ($profile as $priority => $moduleId) {
                $itemStmt->execute([$applicationId, $moduleId, $priority + 1]);
                ++$items;
            }

            $submittedAt = $submittedAt->modify('+' . self::SUBMISSION_STEP_SECONDS . ' seconds');
        }

        return [
            'applications' => $applications,
            'items' => $items,
            'windowStart' => self::APPLICATION_WINDOW_START,
            'windowEnd' => self::APPLICATION_WINDOW_END,
            'underEnrollmentCount' => $underEnrollmentCount,
        ];
    }

    /**
     * Карта «moduleId → ModuleType» (все модули каталога, включая недоборный).
     *
     * @return array<int, ModuleType>
     */
    private function moduleTypes(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, module_type FROM modules ORDER BY id');
        $stmt->execute();
        /** @var list<array{id: int|string, module_type: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = ModuleType::from($row['module_type']);
        }

        return $result;
    }

    /**
     * Карта «moduleId → max_students» (плановая вместимость).
     *
     * @return array<int, int>
     */
    private function moduleLimits(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, max_students FROM modules ORDER BY id');
        $stmt->execute();
        /** @var list<array{id: int|string, max_students: int|string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (int) $row['max_students'];
        }

        return $result;
    }

    /**
     * Студенты в порядке вставки: «studentId → является ли группа технической».
     *
     * @return array<int, bool>
     */
    private function studentsByTechnicalFlag(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, g.is_technical
             FROM students s
             JOIN student_groups g ON g.id = s.group_id
             ORDER BY s.id',
        );
        $stmt->execute();
        /** @var list<array{id: int|string, is_technical: int|string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (int) $row['is_technical'] === 1;
        }

        return $result;
    }

    /**
     * Различные студенты когорт каталога: сумма основных выборок
     * (target.* + silent.non_target + disabled.base + paid.base) = 125.
     *
     * @param array<string, list<int>> $segmentIds
     *
     * @return list<int>
     */
    private function distinctCohortIds(array $segmentIds): array
    {
        $ids = [];
        foreach (['target.technical', 'target.humanitarian', 'silent.non_target', 'disabled.base', 'paid.base'] as $segment) {
            foreach ($segmentIds[$segment] ?? [] as $studentId) {
                $ids[] = $studentId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Модули-концентраторы перегрузки: первые CONCENTRATOR_COUNT технических
     * модулей по убыванию max_students (при равенстве — меньший id).
     *
     * @param list<int>       $technicalIds
     * @param array<int, int> $moduleLimits
     *
     * @return list<int>
     */
    private function selectConcentrators(array $technicalIds, array $moduleLimits): array
    {
        $byDemand = $technicalIds;
        usort($byDemand, static function (int $a, int $b) use ($moduleLimits): int {
            if ($moduleLimits[$a] !== $moduleLimits[$b]) {
                return $moduleLimits[$b] <=> $moduleLimits[$a];
            }

            return $a <=> $b;
        });

        return array_slice($byDemand, 0, self::CONCENTRATOR_COUNT);
    }

    /**
     * Профиль заявителя недоборного модуля (D, план B2-03 v4).
     *
     * 4 студента: приоритет 1 — недоборный модуль (base), приоритеты 2/3 —
     * валидные следующие (R-07). 2 студента: приоритет 1 — обычный технический,
     * приоритет 2 — недоборный модуль, приоритет 3 — валидный следующий.
     *
     * @param list<int> $technicalIds регулярные технические модули (без недоборного)
     *
     * @return list<int> moduleId в порядке приоритетов {1, 2, 3}
     */
    private function demandProfile(int $index, int $underEnrollmentId, array $technicalIds): array
    {
        if ($index < CohortCatalog::UNDERENROLLMENT_DEMAND_PRIORITY_1) {
            return [$underEnrollmentId, $technicalIds[3], $technicalIds[4]];
        }

        return [$technicalIds[3], $underEnrollmentId, $technicalIds[5]];
    }

    /**
     * Профиль студента под-профиля хвоста (T, план B2-04 v2, примечание #1).
     *
     * Все 3 приоритета — на модули-концентраторы перегрузки (спрос по первому
     * приоритету > max), по циклической перестановке: H_actual = T = 12.
     * Вариант «или на закрывающийся модуль» НЕ используется; недоборный модуль
     * в списках T невозможен (спрос зафиксирован ровно D = 6).
     *
     * @param list<int> $concentrators модули-концентраторы (3 шт.)
     *
     * @return list<int> moduleId в порядке приоритетов {1, 2, 3}
     */
    private function tailProfile(int $index, array $concentrators): array
    {
        $rotation = $index % count($concentrators);

        return [
            $concentrators[$rotation],
            $concentrators[($rotation + 1) % count($concentrators)],
            $concentrators[($rotation + 2) % count($concentrators)],
        ];
    }

    /**
     * Обычный технический профиль (334 заявителя без перегрузки приоритета-1).
     *
     * Первые три сегмента заполняют концентраторы до max_students; остальные —
     * циклически по не-концентраторным техническим модулям. Остаток перегрузки
     * от обычных = 0 (план B2-04 v2, p. 8).
     *
     * @param list<int>       $technicalIds   регулярные технические модули
     * @param list<int>       $concentrators  модули-концентраторы
     * @param array<int, int> $moduleLimits   план max_students
     *
     * @return list<int> moduleId в порядке приоритетов {1, 2, 3}
     */
    private function normalTechnicalProfile(int $index, array $technicalIds, array $concentrators, array $moduleLimits): array
    {
        $nonConcentratorStart = count($concentrators);
        $nonConcentratorCount = count($technicalIds) - $nonConcentratorStart;

        $segment1End = $moduleLimits[$concentrators[0]];
        $segment2End = $segment1End + $moduleLimits[$concentrators[1]];
        $segment3End = $segment2End + $moduleLimits[$concentrators[2]];

        if ($index < $segment1End) {
            return [$concentrators[0], $technicalIds[$nonConcentratorStart], $technicalIds[$nonConcentratorStart + 1]];
        }
        if ($index < $segment2End) {
            return [$concentrators[1], $technicalIds[$nonConcentratorStart + 1], $technicalIds[$nonConcentratorStart + 2]];
        }
        if ($index < $segment3End) {
            return [$concentrators[2], $technicalIds[$nonConcentratorStart + 2], $technicalIds[$nonConcentratorStart + 3]];
        }

        $offset = $index - $segment3End;
        $position = $offset % $nonConcentratorCount;

        return [
            $technicalIds[$nonConcentratorStart + $position],
            $technicalIds[$nonConcentratorStart + (($position + 1) % $nonConcentratorCount)],
            $technicalIds[$nonConcentratorStart + (($position + 2) % $nonConcentratorCount)],
        ];
    }

    /**
     * Обычный гуманитарный профиль (40 заявителей БШ, циклический по 5 модулям).
     *
     * @param list<int> $humanitarianIds гуманитарные модули
     *
     * @return list<int> moduleId в порядке приоритетов {1, 2, 3}
     */
    private function normalHumanitarianProfile(int $index, array $humanitarianIds): array
    {
        $count = count($humanitarianIds);
        $position = $index % $count;

        return [
            $humanitarianIds[$position],
            $humanitarianIds[($position + 1) % $count],
            $humanitarianIds[($position + 2) % $count],
        ];
    }

    /**
     * Проверяет размер выборки (детерминизм нарушен — ошибка, без тихой подгонки).
     *
     * @param list<int> $items
     */
    private function requireCount(array $items, int $expected, string $what): void
    {
        $actual = count($items);
        if ($actual !== $expected) {
            throw new \LogicException(
                'ApplicationSeeder: ' . $what . ' должно быть ' . $expected
                . ', получено ' . $actual . ' — проверьте каталог/когорты (деламинизация), правьте через версию плана',
            );
        }
    }
}