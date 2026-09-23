<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use App\Application\Distribution\DistributionService;
use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\RankingStrategyInterface;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\StudentGroup;
use App\Infrastructure\Db\ApplicationRepositoryInterface;
use App\Infrastructure\Db\ModuleRepositoryInterface;
use App\Infrastructure\Db\StudentRepositoryInterface;
use App\Infrastructure\Seeder\SeedCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI-команда `distribute` (B3-05): запуск распределения студентов на модули на реальном сиде.
 *
 * Композиция (конструктор-инжекция, план v3 §2.2): репозитории (контракты),
 * критериальный ключ (ADR-002), сырой PDO (группы/карта школ вне контрактов и
 * DELETE перед перезаписью), флаг ADR-004 — все значения передаются из связки
 * `bin/console`; конфиги команда сама НЕ читает (юнит-тестируемость без БД).
 *
 * Последовательность execute():
 *   1. данные ядра через контракты репозиториев;
 *   2. входы вне контрактов — сырой PDO (паттерн DistributionSeedRoundTripTest, B3-04);
 *   3. стратегия через {@see RankingStrategyFactory} по опции --algorithm;
 *   4. прогон DistributionService;
 *   5. запись в БД (удаление существующих + вставка финалов через контракт);
 *   6. вывод краткой машинной сводки (план §2.8).
 *
 * Ошибки ядра/фабрики (\InvalidArgumentException, \RuntimeException) — в stderr, exit 1.
 * Юнит-тесты — CommandTester + моки (App\Tests\Unit\Ui\Cli\DistributeCommandTest).
 */
final class DistributeCommand extends Command
{
    /** Флаг ADR-004: целевик без заявки → профильный модуль своего типа. */
    public const string PROFILE_MODULE_FLAG = 'profile_module';

    /** Флаг ADR-004: целевик без заявки → свободный модуль школы. */
    public const string FREE_MODULE_FLAG = 'free_module';

    public function __construct(
        private readonly StudentRepositoryInterface $students,
        private readonly ModuleRepositoryInterface $modules,
        private readonly ApplicationRepositoryInterface $applications,
        private readonly \PDO $pdo,
        private readonly CriteriaRating $criteriaRating,
        private readonly string $targetQuotaNoApplicationStrategy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('distribute')
            ->setDescription('Распределение студентов на модули МДС (R-17: --algorithm=date|criteria).')
            ->addOption(
                'algorithm',
                null,
                InputOption::VALUE_REQUIRED,
                'Алгоритм ранжирования: ' . RankingStrategyFactory::DATE_ALGORITHM
                . ' (по дате подачи) или ' . RankingStrategyFactory::CRITERIA_ALGORITHM . ' (по критериям).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            /** @var string|null $algorithm */
            $algorithm = $input->getOption('algorithm');
            $strategy = RankingStrategyFactory::createStrategy((string) $algorithm, $this->criteriaRating);

            $students = array_values($this->students->findAllByAdmissionYear(SeedCatalog::ADMISSION_YEAR));
            $modules = array_values($this->modules->findAll());
            $applications = array_values($this->applications->findAll());
            $groups = $this->readGroups();
            $eligibleSchools = $this->readEligibleSchools();

            $service = new DistributionService(
                strategy: $strategy,
                modules: $modules,
                groups: $groups,
                students: $students,
                applications: $applications,
                moduleEligibleSchools: $eligibleSchools,
                targetQuotaNoApplicationStrategy: $this->targetQuotaNoApplicationStrategy,
            );
            $result = $service->distribute();

            // Перезапись результата (план v3 §2.7): DELETE в autocommit, затем INSERT
            // собственной транзакцией saveAssignments (неатомарность — известное ограничение).
            $this->pdo->exec('DELETE FROM assignments');
            $this->modules->saveAssignments(...$result->assignments);

            $this->renderSummary($output, $strategy, count($students), $result);

            return Command::SUCCESS;
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    /**
     * Группы (таблица `student_groups`) — вне контрактов, сырой PDO.
     *
     * @return list<StudentGroup>
     */
    private function readGroups(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, school_id, name, is_technical, admission_year FROM student_groups ORDER BY id',
        );
        $stmt->execute();
        /** @var list<array<string, int|string|null>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = new StudentGroup(
                (int) $row['id'],
                (int) $row['school_id'],
                (string) $row['name'],
                (int) $row['is_technical'] === 1,
                (int) $row['admission_year'],
            );
        }

        return $groups;
    }

    /**
     * Карта «moduleId → школы» для свободных модулей (R-19) — вне контрактов, сырой PDO.
     *
     * @return array<int, list<int>>
     */
    private function readEligibleSchools(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT module_id, school_id FROM module_eligible_schools ORDER BY module_id, school_id',
        );
        $stmt->execute();
        /** @var list<array{module_id: int|string, school_id: int|string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['module_id']][] = (int) $row['school_id'];
        }

        return $map;
    }

    /**
     * Краткая машинная сводка результата (план §2.8) — без application_items (это B4).
     */
    private function renderSummary(
        OutputInterface $output,
        RankingStrategyInterface $strategy,
        int $studentCount,
        DistributionResult $result,
    ): void {
        $output->writeln('Алгоритм: ' . $strategy->algorithm()->value);
        $output->writeln(sprintf(
            'Распределено студентов: %d/%d (R-11)',
            count($result->assignments),
            $studentCount,
        ));
        $output->writeln('Модуль    Статус    Заполнено    min/max    Источники (competition/free/target_quota)');

        $moduleIds = array_keys($result->stats);
        sort($moduleIds);
        foreach ($moduleIds as $moduleId) {
            /** @var array{fill: int, status: string, min: int, max: int, sources: array<string, int>} $stats */
            $stats = $result->stats[$moduleId];
            $output->writeln(sprintf(
                '%-9s %-8s %-10s %-9s %s',
                $moduleId,
                $stats['status'],
                $stats['fill'] . '/' . $stats['max'],
                $stats['min'] . '/' . $stats['max'],
                $this->sourcesLine($stats['sources']),
            ));
        }
    }

    /**
     * Строка источников (только ненулевые; все нули → «—»).
     *
     * @param array<string, int> $sources карта «источник → число записей»
     */
    private function sourcesLine(array $sources): string
    {
        $parts = [];
        foreach (['competition', 'free', 'target_quota'] as $source) {
            if ($sources[$source] > 0) {
                $parts[] = $source . '=' . $sources[$source];
            }
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}