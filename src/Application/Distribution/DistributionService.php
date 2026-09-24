<?php

declare(strict_types=1);

namespace App\Application\Distribution;

use App\Application\Ranking\RankingStrategyInterface;
use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\GroupType;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use App\Domain\Model\Assignment;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\Module;
use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use App\Domain\Model\StudentGroup;

/**
 * Ядро распределения студентов на модули МДС (B3-03): Multi-pass Greedy Allocation.
 *
 * Чистый Application-сервис: работает с данными домена и стратегией ранжирования
 * (R-17), не знает о БД/файлах (контракты). Выполняет фазы 0–5 фаз v2
 * [[structure/server/algorithms/distribution-phases]], включая фазу 1.1 (немедленный
 * переобход аннулированных закрытием целевиков) и каскад 3.5 до неподвижной точки.
 *
 * Статусы записей (provisional/final/void) живут ТОЛЬКО в памяти сервиса: наружу
 * выходят только final-Assignment (в БД пишет B3-05). Фаза 5 проверяет инварианты
 * I-01…I-06; нарушение — \RuntimeException с детерминированным отчётом.
 *
 * Детерминизм: все обходы используют полные детерминированные порядки
 * (даты ASC → id ASC; глобальный порядок стратегии; свободные модули — по id ASC);
 * входные массивы не мутируются.
 */
final readonly class DistributionService
{
    public const string PROFILE_MODULE = 'profile_module';
    public const string FREE_MODULE = 'free_module';

    /**
     * @param list<Module>                    $modules      модули (включая свободные, R-19)
     * @param list<StudentGroup>              $groups       учебные группы
     * @param list<Student>                   $students     контингент (R-01)
     * @param list<Application>               $applications заявки (одна на студента)
     * @param array<int, list<int>>           $moduleEligibleSchools карта «moduleId → школы» для свободных модулей (R-19)
     * @param string                          $targetQuotaNoApplicationStrategy флаг ADR-004
     */
    public function __construct(
        private RankingStrategyInterface $strategy,
        private array $modules,
        private array $groups,
        private array $students,
        private array $applications,
        private array $moduleEligibleSchools = [],
        private string $targetQuotaNoApplicationStrategy = self::PROFILE_MODULE,
    ) {
        if (!in_array($this->targetQuotaNoApplicationStrategy, [self::PROFILE_MODULE, self::FREE_MODULE], true)) {
            throw new \InvalidArgumentException(
                'DistributionService: targetQuotaNoApplicationStrategy должен быть '
                . self::PROFILE_MODULE . ' или ' . self::FREE_MODULE . ', получено: '
                . $this->targetQuotaNoApplicationStrategy,
            );
        }
    }

    /**
     * Выполняет распределение и возвращает финальный результат.
     */
    public function distribute(): DistributionResult
    {
        $context = new DistributionContext(
            modules: $this->modules,
            groups: $this->groups,
            students: $this->students,
            applications: $this->applications,
            moduleEligibleSchools: $this->moduleEligibleSchools,
            strategy: $this->strategy,
            targetQuotaNoApplicationStrategy: $this->targetQuotaNoApplicationStrategy,
        );

        $context->phaseZeroTargets();
        $context->phaseOnePreliminaryClosure();
        $context->phaseOneOneReWalkReleasedTargets();
        $context->phaseTwoRanking();
        $context->phaseThreeWalkCompetitive();
        $context->phaseThreeFiveCascade();
        $context->phaseFourTails();

        return $context->phaseFiveFinalize();
    }
}

/**
 * Внутреннее изменяемое состояние распределения (память сервиса, B3-03).
 *
 * Не публичный контракт: финальные назначения возвращаются через
 * DistributionService::distribute() как list<Assignment>.
 */
final class DistributionContext
{
    /** @var array<int, Module> модуль по id */
    private array $moduleById = [];

    /** @var array<int, StudentGroup> группа по id */
    private array $groupById = [];

    /** @var array<int, Student> студент по id */
    private array $studentById = [];

    /** @var array<int, Application> заявка студента по studentId */
    private array $applicationByStudentId = [];

    /** @var array<int, list<ApplicationItem>> элементы заявки по studentId (по возрастанию приоритета) */
    private array $itemsByStudentId = [];

    /** @var list<int> id свободных модулей (по возрастанию id) */
    private array $freeModuleIds = [];

    /** @var array<int, int> заполненность модуля (число действующих записей) */
    private array $fill = [];

    /** @var array<int, bool> статус закрытия не-свободного модуля */
    private array $closed = [];

    /**
     * Текущая (provisional) запись студента.
     *
     * @var array<int, array{moduleId: int, source: AssignmentSource, rank: ?int, priority: ?int}>
     *      ключ — studentId; отсутствие ключа = студент пока без записи
     */
    private array $recordByStudentId = [];

    /** @var list<int> целевики с заявкой (дата ASC, id ASC) */
    private array $targetWithApplicationIds = [];

    /** @var list<int> целевики без заявки (id ASC, ADR-004) */
    private array $targetWithoutApplicationIds = [];

    /** @var list<int> «молчуны»-нецелевики без заявки (id ASC, R-10) */
    private array $silentNonTargetIds = [];

    /** @var list<int> не-целевики с заявкой: глобальный порядок стратегии (фаза 2) */
    private array $competitiveOrder = [];

    /** @var array<int, int> глобальный ранг конкурсного пула (1-индекс, фаза 2) */
    private array $rankByStudentId = [];

    /** @var array<int, list<int>> moduleEligibleSchools (R-19) */
    private array $moduleEligibleSchools = [];

    private RankingStrategyInterface $strategy;

    private string $targetQuotaNoApplicationStrategy;

    /**
     * @param list<Module>                    $modules
     * @param list<StudentGroup>              $groups
     * @param list<Student>                   $students
     * @param list<Application>               $applications
     * @param array<int, list<int>>           $moduleEligibleSchools
     */
    public function __construct(
        array $modules,
        array $groups,
        array $students,
        array $applications,
        array $moduleEligibleSchools,
        RankingStrategyInterface $strategy,
        string $targetQuotaNoApplicationStrategy,
    ) {
        $this->moduleEligibleSchools = $moduleEligibleSchools;
        $this->strategy = $strategy;
        $this->targetQuotaNoApplicationStrategy = $targetQuotaNoApplicationStrategy;

        foreach ($modules as $module) {
            $this->moduleById[$module->id] = $module;
            $this->fill[$module->id] = 0;
            $this->closed[$module->id] = false;
            if ($module->moduleType === ModuleType::Free) {
                $this->freeModuleIds[] = $module->id;
            }
        }
        ksort($this->moduleById);
        sort($this->freeModuleIds);

        foreach ($groups as $group) {
            $this->groupById[$group->id] = $group;
        }

        foreach ($students as $student) {
            $this->studentById[$student->id] = $student;
            if (!isset($this->groupById[$student->groupId])) {
                throw new \InvalidArgumentException(
                    'DistributionService: группа ' . $student->groupId . ' студента ' . $student->id . ' отсутствует в $groups',
                );
            }
        }

        foreach ($applications as $application) {
            if (!isset($this->studentById[$application->studentId])) {
                throw new \InvalidArgumentException(
                    'DistributionService: заявка ' . $application->id . ' ссылается на отсутствующего студента '
                    . $application->studentId,
                );
            }
            if (isset($this->applicationByStudentId[$application->studentId])) {
                throw new \InvalidArgumentException(
                    'DistributionService: у студента ' . $application->studentId . ' более одной заявки (uk_applications_student)',
                );
            }
            $this->applicationByStudentId[$application->studentId] = $application;
            $items = $application->items;
            usort($items, static fn (ApplicationItem $a, ApplicationItem $b): int => $a->priority <=> $b->priority);
            $this->itemsByStudentId[$application->studentId] = $items;
        }

        $this->targetWithApplicationIds = [];
        $this->targetWithoutApplicationIds = [];
        $this->silentNonTargetIds = [];

        $studentIds = array_keys($this->studentById);
        sort($studentIds);

        foreach ($studentIds as $studentId) {
            $student = $this->studentById[$studentId];
            $hasApplication = isset($this->applicationByStudentId[$studentId]);
            if ($student->isTargetQuota) {
                if ($hasApplication) {
                    $this->targetWithApplicationIds[] = $studentId;
                } else {
                    $this->targetWithoutApplicationIds[] = $studentId;
                }
            } elseif (!$hasApplication) {
                $this->silentNonTargetIds[] = $studentId;
            }
        }

        usort(
            $this->targetWithApplicationIds,
            function (int $a, int $b): int {
                $byDate = $this->applicationByStudentId[$a]->submittedAt
                    <=> $this->applicationByStudentId[$b]->submittedAt;

                return $byDate !== 0 ? $byDate : $a <=> $b;
            },
        );

        $this->competitiveOrder = [];
        $this->rankByStudentId = [];
    }

    // ------------------------------------------------------------------ фазы

    /**
     * Фаза 0: целевики с заявкой — вне конкурса (R-14/ADR-006).
     * Порядок по дате заявки ASC → id ASC; приоритеты 1→3; max физичен.
     */
    public function phaseZeroTargets(): void
    {
        foreach ($this->targetWithApplicationIds as $studentId) {
            $this->placeTarget($this->studentById[$studentId]);
        }
    }

    /**
     * Фаза 1: предварительное закрытие по спросу (R-04/R-07/Q-01/ADR-006).
     * Спрос = число заявок на модуль по ВСЕМ приоритетам 1–3 (включая целевиков);
     * спрос < min → Closed навсегда; provisional-записи аннулируются (void).
     */
    public function phaseOnePreliminaryClosure(): void
    {
        $demand = [];
        foreach ($this->moduleById as $moduleId => $module) {
            if ($module->moduleType !== ModuleType::Free) {
                $demand[$moduleId] = 0;
            }
        }

        foreach ($this->applicationByStudentId as $application) {
            $seen = [];
            foreach ($application->items as $item) {
                if (!isset($demand[$item->moduleId]) || isset($seen[$item->moduleId])) {
                    continue;
                }
                $seen[$item->moduleId] = true;
                ++$demand[$item->moduleId];
            }
        }

        $moduleIds = array_keys($this->moduleById);
        sort($moduleIds);
        foreach ($moduleIds as $moduleId) {
            if ($this->isFree($moduleId)) {
                continue;
            }
            if ($demand[$moduleId] < $this->moduleById[$moduleId]->minStudents) {
                $this->closeModule($moduleId);
            }
        }
    }

    /**
     * Фаза 1.1: немедленный переобход освобождённых закрытием целевиков.
     * До фаз 2/3 — чтобы приоритет 2+ с местом был попробован до каскада
     * (R-14/ADR-006); повтор в фазе 3.5 закрепляет неподвижную точку.
     */
    public function phaseOneOneReWalkReleasedTargets(): void
    {
        foreach ($this->targetWithApplicationIds as $studentId) {
            if (!isset($this->recordByStudentId[$studentId])) {
                $this->placeTarget($this->studentById[$studentId]);
            }
        }
    }

    /**
     * Фаза 2: ранжирование конкурсного пула (не-целевики с заявкой, ADR-007).
     * Ранг = 1-индекс глобального порядка стратегии (стабилен на переобходах).
     */
    public function phaseTwoRanking(): void
    {
        $pool = [];
        foreach ($this->applicationByStudentId as $studentId => $application) {
            $student = $this->studentById[$studentId];
            if (!$student->isTargetQuota) {
                $pool[] = new RankingCandidate($student, $application->submittedAt);
            }
        }

        $rank = 1;
        foreach ($this->strategy->sort($pool) as $candidate) {
            $this->competitiveOrder[] = $candidate->student->id;
            $this->rankByStudentId[$candidate->student->id] = $rank;
            ++$rank;
        }
    }

    /**
     * Фаза 3: обход конкурсного пула в глобальном порядке (R-06/R-08/R-13/R-20).
     */
    public function phaseThreeWalkCompetitive(): void
    {
        foreach ($this->competitiveOrder as $studentId) {
            if (!isset($this->recordByStudentId[$studentId])) {
                $this->placeCompetitive($this->studentById[$studentId]);
            }
        }
    }

    /**
     * Фаза 3.5: каскад до неподвижной точки (R-05/R-07/R-09/ADR-006).
     * Пока ∃ не-свободный, не Closed модуль с заполненностью ≤ min (по возрастанию
     * id): пометить Closed, аннулировать записи (void), переобход нераспределённых:
     * сначала целевики (дата ASC, id ASC, правило фазы 0), затем конкурсные
     * в глобальном порядке стратегии. Каждая итерация закрывает ≥ 1 модуль.
     */
    public function phaseThreeFiveCascade(): void
    {
        while (true) {
            $moduleIds = array_keys($this->moduleById);
            sort($moduleIds);

            $toClose = null;
            foreach ($moduleIds as $moduleId) {
                if (
                    !$this->isFree($moduleId)
                    && !$this->closed[$moduleId]
                    && $this->fill[$moduleId] <= $this->moduleById[$moduleId]->minStudents
                ) {
                    $toClose = $moduleId;
                    break;
                }
            }
            if ($toClose === null) {
                return;
            }

            $this->closeModule($toClose);

            foreach ($this->targetWithApplicationIds as $studentId) {
                if (!isset($this->recordByStudentId[$studentId])) {
                    $this->placeTarget($this->studentById[$studentId]);
                }
            }
            foreach ($this->competitiveOrder as $studentId) {
                if (!isset($this->recordByStudentId[$studentId])) {
                    $this->placeCompetitive($this->studentById[$studentId]);
                }
            }
        }
    }

    /**
     * Фаза 4: хвосты (R-09/R-10/R-19/ADR-004).
     * Порядок групп: целевики без заявки → «молчуны»-нецелевики → конкурсный хвост
     * (в порядке стратегии) → целевики с заявкой с исчерпанными приоритетами
     * (дата ASC; ADR-006 п.5 → свободные по R-09).
     */
    public function phaseFourTails(): void
    {
        foreach ($this->targetWithoutApplicationIds as $studentId) {
            $this->placeTargetWithoutApplication($this->studentById[$studentId]);
        }

        foreach ($this->silentNonTargetIds as $studentId) {
            $this->placeFree($this->studentById[$studentId], AssignmentSource::Free);
        }

        foreach ($this->competitiveOrder as $studentId) {
            if (!isset($this->recordByStudentId[$studentId])) {
                $this->placeFree($this->studentById[$studentId], AssignmentSource::Free);
            }
        }

        foreach ($this->targetWithApplicationIds as $studentId) {
            if (!isset($this->recordByStudentId[$studentId])) {
                $this->placeFree($this->studentById[$studentId], AssignmentSource::Free);
            }
        }
    }

    /**
     * Фаза 5: финализация — provisional → final, сбор DistributionResult и проверка
     * инвариантов I-01…I-06. Нарушение → \RuntimeException с детерминированным отчётом.
     */
    public function phaseFiveFinalize(): DistributionResult
    {
        $algorithm = $this->strategy->algorithm();

        $assignments = [];
        $studentIds = array_keys($this->studentById);
        sort($studentIds);

        foreach ($studentIds as $studentId) {
            if (!isset($this->recordByStudentId[$studentId])) {
                throw new \RuntimeException(
                    'DistributionService: студент ' . $studentId . ' остался без назначения (R-11 нарушен)',
                );
            }
            $record = $this->recordByStudentId[$studentId];
            $assignments[] = new Assignment(
                studentId: $studentId,
                moduleId: $record['moduleId'],
                algorithm: $algorithm,
                source: $record['source'],
                rank: $record['rank'],
            );
        }

        $this->assertInvariants($assignments);

        $stats = [];
        foreach ($this->moduleById as $moduleId => $module) {
            $sources = ['competition' => 0, 'free' => 0, 'target_quota' => 0];
            foreach ($assignments as $assignment) {
                if ($assignment->moduleId !== $moduleId) {
                    continue;
                }
                $sources[$assignment->source->value]++;
            }

            $status = match (true) {
                $this->isFree($moduleId) => 'free',
                $this->closed[$moduleId] => 'closed',
                default => 'open',
            };

            $stats[(string) $moduleId] = [
                'fill' => $this->fill[$moduleId],
                'status' => $status,
                'min' => $module->minStudents,
                'max' => $module->maxStudents,
                'sources' => $sources,
            ];
        }

        return new DistributionResult(
            assignments: $assignments,
            unassigned: [],
            stats: $stats,
        );
    }

    // --------------------------------------------------------------- размещения

    /**
     * Целевик с заявкой: вне конкурса (R-14), приоритеты 1→3, тип модуля = тип группы
     * (R-13), max физичен (ADR-006 п.2–3). Свободные модули не рассматриваются.
     */
    private function placeTarget(Student $student): void
    {
        foreach ($this->itemsByStudentId[$student->id] as $item) {
            if ($this->moduleAvailableForNonFree($student, $item->moduleId)) {
                $this->setRecord($student->id, $item->moduleId, AssignmentSource::TargetQuota, null, $item->priority);
                return;
            }
        }
    }

    /**
     * Конкурсный обход приоритетов 1→3 (R-08/R-20): взял приоритет — дальше не идём.
     */
    private function placeCompetitive(Student $student): void
    {
        foreach ($this->itemsByStudentId[$student->id] as $item) {
            if ($this->moduleAvailableForNonFree($student, $item->moduleId)) {
                $this->setRecord(
                    $student->id,
                    $item->moduleId,
                    AssignmentSource::Competition,
                    $this->rankByStudentId[$student->id],
                    $item->priority,
                );
                return;
            }
        }
    }

    /**
     * Целевик без заявки (ADR-004): config-флаг profile_module → первый не-свободный
     * модуль своего типа с местом (id ASC), иначе свободный модуль школы;
     * free_module → сразу свободный модуль школы. Источник — TargetQuota.
     */
    private function placeTargetWithoutApplication(Student $student): void
    {
        if ($this->targetQuotaNoApplicationStrategy === DistributionService::PROFILE_MODULE) {
            $moduleIds = array_keys($this->moduleById);
            sort($moduleIds);
            foreach ($moduleIds as $moduleId) {
                if (
                    !$this->isFree($moduleId)
                    && !$this->closed[$moduleId]
                    && $this->typeMatches($student, $moduleId)
                    && $this->hasPlace($moduleId)
                ) {
                    $this->setRecord($student->id, $moduleId, AssignmentSource::TargetQuota, null, null);
                    return;
                }
            }
        }

        $this->placeFree($student, AssignmentSource::TargetQuota);
    }

    /**
     * Размещение на свободный модуль школы (R-10/R-19): допустимые для школы модули
     * по возрастанию id, до max. Нет места ни в одном — детерминированное исключение
     * (кейс 6; на сид-данных невозможно: Σmax_free 135 ≥ W 40 + H 12).
     */
    private function placeFree(Student $student, AssignmentSource $source): void
    {
        $schoolId = $this->groupById[$student->groupId]->schoolId;

        foreach ($this->freeModuleIds as $moduleId) {
            $eligible = $this->moduleEligibleSchools[$moduleId] ?? [];
            if (in_array($schoolId, $eligible, true) && $this->hasPlace($moduleId)) {
                $this->setRecord($student->id, $moduleId, $source, null, null);
                return;
            }
        }

        throw new \RuntimeException(
            'DistributionService: не хватило мест свободных модулей — студент ' . $student->id
            . ' (школа ' . $schoolId . ') не может быть размещён (R-09/R-10)',
        );
    }

    // ------------------------------------------------------------------ помощь

    private function setRecord(int $studentId, int $moduleId, AssignmentSource $source, ?int $rank, ?int $priority): void
    {
        $this->recordByStudentId[$studentId] = [
            'moduleId' => $moduleId,
            'source' => $source,
            'rank' => $rank,
            'priority' => $priority,
        ];
        ++$this->fill[$moduleId];
    }

    /**
     * Закрывает модуль навсегда и аннулирует все его provisional-записи (void).
     *
     * @see DistributionContext::phaseOnePreliminaryClosure()
     * @see DistributionContext::phaseThreeFiveCascade()
     */
    private function closeModule(int $moduleId): void
    {
        $this->closed[$moduleId] = true;

        foreach ($this->recordByStudentId as $studentId => $record) {
            if ($record['moduleId'] !== $moduleId) {
                continue;
            }
            unset($this->recordByStudentId[$studentId]);
            --$this->fill[$moduleId];
        }
    }

    private function isFree(int $moduleId): bool
    {
        return $this->moduleById[$moduleId]->moduleType === ModuleType::Free;
    }

    private function hasPlace(int $moduleId): bool
    {
        return $this->fill[$moduleId] < $this->moduleById[$moduleId]->maxStudents;
    }

    private function typeMatches(Student $student, int $moduleId): bool
    {
        $group = $this->groupById[$student->groupId];
        $moduleType = $this->moduleById[$moduleId]->moduleType;
        if ($group->isTechnical) {
            return $moduleType === ModuleType::Technical;
        }

        return $moduleType === ModuleType::Humanitarian;
    }

    private function moduleAvailableForNonFree(Student $student, int $moduleId): bool
    {
        return !$this->isFree($moduleId)
            && !$this->closed[$moduleId]
            && $this->typeMatches($student, $moduleId)
            && $this->hasPlace($moduleId);
    }

    // ---------------------------------------------------------------- инварианты

    /**
     * Проверка инвариантов выхода I-01…I-06 (фаза 5).
     *
     * @param list<Assignment> $assignments финальные назначения (provisional → final)
     */
    private function assertInvariants(array $assignments): void
    {
        $violations = [];

        // I-01: каждый студент ровно в одном final-Assignment (R-11).
        $assignedStudentIds = array_column($assignments, 'studentId');
        if (count($assignedStudentIds) !== count($this->studentById)) {
            $violations[] = 'I-01: число финальных назначений (' . count($assignedStudentIds)
                . ') не равно числу студентов (' . count($this->studentById) . ')';
        }
        if (count($assignedStudentIds) !== count(array_unique($assignedStudentIds))) {
            $violations[] = 'I-01: обнаружены студенты с более чем одним финальным назначением';
        }

        // I-02: заполненность ≤ max для всех модулей.
        foreach ($this->fill as $moduleId => $fill) {
            if ($fill > $this->moduleById[$moduleId]->maxStudents) {
                $violations[] = 'I-02: модуль ' . $moduleId . ' переполнен: ' . $fill
                    . ' > max ' . $this->moduleById[$moduleId]->maxStudents;
            }
        }

        // I-03/I-04: только для конкурсных (не-свободных) модулей.
        foreach ($this->moduleById as $moduleId => $module) {
            if ($this->isFree($moduleId)) {
                continue;
            }
            $fill = $this->fill[$moduleId];
            if ($fill > 0 && $fill <= $module->minStudents) {
                $violations[] = 'I-03: модуль ' . $moduleId . ' с финальными записями имеет заполненность '
                    . $fill . ' ≤ min ' . $module->minStudents;
            }
            if ($this->closed[$moduleId] && $fill !== 0) {
                $violations[] = 'I-04: закрытый модуль ' . $moduleId . ' имеет ' . $fill . ' финальных записей';
            }
        }

        foreach ($assignments as $assignment) {
            $student = $this->studentById[$assignment->studentId];
            $isFreeModule = $this->isFree($assignment->moduleId);

            // I-05: конкурсные final только у студентов с заявкой; тип модуля = тип группы.
            if ($assignment->source === AssignmentSource::Competition) {
                if (!isset($this->applicationByStudentId[$assignment->studentId])) {
                    $violations[] = 'I-05: конкурсное назначение студента ' . $assignment->studentId
                        . ' без заявки (ADR-007)';
                }
                if (!$this->typeMatches($student, $assignment->moduleId)) {
                    $violations[] = 'I-05: конкурсное назначение студента ' . $assignment->studentId
                        . ' на модуль не своего типа ' . $assignment->moduleId . ' (R-13)';
                }
            }

            // I-06: свободные модули содержат только записи фазы 4 (не конкуренция).
            if ($isFreeModule && $assignment->source === AssignmentSource::Competition) {
                $violations[] = 'I-06: запись конкуренции на свободный модуль ' . $assignment->moduleId
                    . ' (студент ' . $assignment->studentId . ')';
            }
        }

        if ($violations !== []) {
            throw new \RuntimeException(
                'DistributionService: нарушены инварианты выхода' . PHP_EOL . implode(PHP_EOL, $violations),
            );
        }
    }
}