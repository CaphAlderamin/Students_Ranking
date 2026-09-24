<?php

declare(strict_types=1);

namespace App\Ui\Cli;

use App\Application\Distribution\DistributionService;
use App\Application\Export\ExporterFactory;
use App\Application\Export\SchoolReportAssembler;
use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\RankingStrategyInterface;
use App\Domain\Enum\ExportFormat;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\Module;
use App\Domain\Model\StudentGroup;
use App\Infrastructure\Db\ApplicationRepositoryInterface;
use App\Infrastructure\Db\ModuleRepositoryInterface;
use App\Infrastructure\Db\StudentRepositoryInterface;
use App\Infrastructure\File\ZipArchiver;
use App\Infrastructure\Seeder\SeedCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI-команда `distribute` (B3-05, экспорт — B4-05): запуск распределения студентов
 * на модули на реальном сиде + экспорт отчётных файлов по школам и ZIP-архива.
 *
 * Композиция (конструктор-инжекция, план v3 §2.2): репозитории (контракты),
 * критериальный ключ (ADR-002), сырой PDO (группы/карта школ/коды школ вне
 * контрактов и DELETE перед перезаписью), флаг ADR-004, дефолтный формат экспорта
 * и каталог вывода (config/export.php, B4-05) — все значения передаются из связки
 * `bin/console`; конфиги команда сама НЕ читает (юнит-тестируемость без БД).
 *
 * Последовательность execute():
 *   1. данные ядра через контракты репозиториев;
 *   2. входы вне контрактов — сырой PDO (паттерн DistributionSeedRoundTripTest, B3-04);
 *   3. стратегия через {@see RankingStrategyFactory} по опции --algorithm;
 *   4. прогон DistributionService;
 *   5. запись в БД (удаление существующих + вставка финалов через контракт);
 *   6. экспорт отчётов (ExporterFactory + SchoolReportAssembler, ADR-003) и
 *      ZIP-архив (ZipArchiver, B4-04) — формат из опции --format либо конфиг-дефолт;
 *   7. вывод краткой машинной сводки (план §2.8) + путей файлов и архива (B4-05).
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
        private readonly ExportFormat $defaultFormat = ExportFormat::Csv,
        private readonly string $exportDirectory = '',
        private readonly ZipArchiver $zipArchiver = new ZipArchiver(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('distribute')
            ->setDescription('Распределение студентов на модули МДС (R-17: --algorithm=date|criteria; ADR-003: --format=csv|tsv).')
            ->addOption(
                'algorithm',
                null,
                InputOption::VALUE_REQUIRED,
                'Алгоритм ранжирования: ' . RankingStrategyFactory::DATE_ALGORITHM
                . ' (по дате подачи) или ' . RankingStrategyFactory::CRITERIA_ALGORITHM . ' (по критериям).',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Формат отчётных файлов и архива: csv|tsv (по умолчанию — из config/export.php, ADR-003).',
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

            $this->exportAndArchive($input, $output, $modules, $result);
            $this->renderSummary($output, $strategy, count($students), $result);

            return Command::SUCCESS;
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    /**
     * Экспорт отчётных файлов (ADR-003) и ZIP-архива (B4-04, единый контур B4-05):
     * тот же ExporterFactory + SchoolReportAssembler, что и в вебе (одинаковый результат).
     *
     * @param list<Module> $modules каталог для карты moduleId → Module (B4-02)
     *
     * @throws \InvalidArgumentException при невалидном --format (R-17-стиль, ADR-003)
     * @throws \RuntimeException         при пустом каталоге экспорта / ошибке записи архива
     */
    private function exportAndArchive(
        InputInterface $input,
        OutputInterface $output,
        array $modules,
        DistributionResult $result,
    ): void {
        if ($this->exportDirectory === '') {
            throw new \RuntimeException('Экспорт недоступен: не задан каталог вывода (config/export.php).');
        }

        $format = $this->resolveFormat($input);
        $paths = array_values(
            (new ExporterFactory(
                new SchoolReportAssembler($this->moduleById($modules), $this->schoolCodes()),
            ))->create($format)->export($result, $this->exportDirectory),
        );

        $archiveName = 'students-ranking_' . (new \DateTimeImmutable('now'))->format('Ymd_His') . '.zip';
        $archivePath = rtrim($this->exportDirectory, '/\\') . DIRECTORY_SEPARATOR . $archiveName;
        $written = file_put_contents($archivePath, $this->zipArchiver->archive($paths));
        if ($written === false) {
            throw new \RuntimeException('Не удалось записать архив: ' . $archivePath);
        }

        $output->writeln('Файлы отчётов (' . count($paths) . '):');
        foreach ($paths as $path) {
            $output->writeln('  ' . $path);
        }
        $output->writeln('Архив: ' . $archivePath);
    }

    /**
     * Фактический формат экспорта: опция --format перекрывает конфиг-дефолт (ADR-003).
     */
    private function resolveFormat(InputInterface $input): ExportFormat
    {
        /** @var string|null $value */
        $value = $input->getOption('format');
        if ($value === null) {
            return $this->defaultFormat;
        }

        $format = ExportFormat::tryFrom($value);
        if ($format === null) {
            throw new \InvalidArgumentException('Неизвестный формат "' . $value . '"; разрешены: csv|tsv.');
        }

        return $format;
    }

    /**
     * Коды школ (таблица `schools`) для имён отчётных файлов (ADR-001/ADR-003) —
     * вне контрактов, сырой PDO (паттерн веба readSchoolCodes, B4-04).
     *
     * @return array<int, string> schoolId → код
     */
    private function schoolCodes(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, code FROM schools ORDER BY id');
        $stmt->execute();
        /** @var list<array{id: int|string, code: string|null}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $codes = [];
        foreach ($rows as $row) {
            $codes[(int) $row['id']] = (string) $row['code'];
        }

        return $codes;
    }

    /**
     * Каталог модулей с ключом по id — школьный ассемблер (B4-02) требует ключ, не список.
     *
     * @param list<Module> $modules
     *
     * @return array<int, Module>
     */
    private function moduleById(array $modules): array
    {
        $map = [];
        foreach ($modules as $module) {
            $map[$module->id] = $module;
        }

        return $map;
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