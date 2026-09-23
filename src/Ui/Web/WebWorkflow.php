<?php

declare(strict_types=1);

namespace App\Ui\Web;

use App\Application\Distribution\DistributionService;
use App\Application\Export\ExporterFactory;
use App\Application\Export\SchoolReportAssembler;
use App\Application\Ranking\CriteriaRating;
use App\Domain\Enum\ExportFormat;
use App\Domain\Enum\GroupType;
use App\Domain\Model\Application;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use App\Domain\Model\StudentGroup;
use App\Domain\Service\ApplicationValidator;
use App\Domain\ValueObject\RatingWeights;
use App\Infrastructure\File\ReportApplicationImporter;
use App\Infrastructure\File\ZipArchiver;
use App\Ui\Cli\RankingStrategyFactory;

/**
 * Конвейер веб-контура (B4-04, план §2.2): импорт файла заявок → глубокая проверка
 * R-13 → распределение → экспорт по школам → ZIP-архив.
 *
 * Чистый Ui-сервис: каталоги и конфиги приходят через конструктор (связка
 * public/index.php), БД — только на чтение; заявки в БД НЕ пишутся (веб = ephemeral,
 * разделение concerns с CLI-контуром, решение §2.5). Запуск независим и
 * детерминирован: то же ядро DistributionService и те же дефолты, что в CLI
 * (алгоритм date — вариант A §2.5; формат csv из config/export.php, ADR-003).
 *
 * Политика блокировок §2.5: ошибки импорта (B4-03) или нарушения R-13 останавливают
 * конвейер ДО распределения — результат возвращается с errors/validationErrors,
 * архив пуст, заявки с неполным файлом не обрабатываются частично.
 *
 * Исключения (\InvalidArgumentException/\RuntimeException) пробрасываются наружу
 * как ошибки связки/ядра и перехватываются в public/index.php.
 */
final readonly class WebWorkflow
{
    /** Дефолтный алгоритм веба = алгоритм CLI по умолчанию (Makefile ALGO ?= date, §2.5). */
    public const string DEFAULT_ALGORITHM = RankingStrategyFactory::DATE_ALGORITHM;

    /**
     * @param list<Student>                    $students      контингент года приёма (R-01)
     * @param list<Module>                     $modules       каталог модулей (включая свободные, R-19)
     * @param list<StudentGroup>               $groups        учебные группы
     * @param array<int, list<int>>            $moduleEligibleSchools карта «moduleId → школы» (R-19)
     * @param array<int, string>               $schoolCodes   коды школ: schoolId → код (ADR-001)
     * @param ExportFormat                     $format        формат экспорта (дефолт csv, ADR-003)
     * @param string                           $exportDirectory каталог для отчётных файлов (ADR-003)
     */
    public function __construct(
        private array $students,
        private array $modules,
        private array $groups,
        private array $moduleEligibleSchools,
        private array $schoolCodes,
        private RatingWeights $ratingWeights,
        private string $algorithm = self::DEFAULT_ALGORITHM,
        private string $targetQuotaNoApplicationStrategy = DistributionService::PROFILE_MODULE,
        private ExportFormat $format = ExportFormat::Csv,
        private string $exportDirectory = '',
    ) {
        // Ранняя валидация алгоритма (R-17): ошибка связки до чтения файла.
        RankingStrategyFactory::createStrategy($this->algorithm, new CriteriaRating($this->ratingWeights));
    }

    /**
     * Выполняет конвейер: import → R-13 → distribute → export → zip.
     *
     * @param non-empty-string $filePath путь к загруженному файлу (csv/tsv, tmp_name)
     *
     * @throws \App\Infrastructure\File\ImportException при недоступном файле (B4-03)
     * @throws \InvalidArgumentException                 при несогласованности конфигов (R-17, R-19, ADR-003)
     * @throws \RuntimeException                         при сбое ядра (инварианты I-01…I-06, B3-03)
     *                                                  или экспорта/архива (B4-04)
     */
    public function run(string $filePath): WebResult
    {
        $import = (new ReportApplicationImporter(
            $this->moduleById(),
            $this->studentById(),
        ))->import($filePath);

        // Блокировка: импорт с ошибками → конвейер останавливается (политика §2.5).
        if ($import->errors !== []) {
            return new WebResult(errors: $import->errors);
        }

        // Блокировка: нарушения R-13 (только модули своего типа группы) — до распределения.
        $validationErrors = $this->validateR13($import->applications);
        if ($validationErrors !== []) {
            return new WebResult(validationErrors: $validationErrors);
        }

        $strategy = RankingStrategyFactory::createStrategy(
            $this->algorithm,
            new CriteriaRating($this->ratingWeights),
        );

        $result = (new DistributionService(
            strategy: $strategy,
            modules: $this->modules,
            groups: $this->groups,
            students: $this->students,
            applications: $import->applications,
            moduleEligibleSchools: $this->moduleEligibleSchools,
            targetQuotaNoApplicationStrategy: $this->targetQuotaNoApplicationStrategy,
        ))->distribute();

        $paths = array_values(
            (new ExporterFactory(
                new SchoolReportAssembler($this->moduleById(), $this->schoolCodes),
            ))->create($this->format)->export($result, $this->exportDirectory),
        );

        $archiveName = 'students-ranking_' . (new \DateTimeImmutable('now'))->format('Ymd_His') . '.zip';
        $archiveBytes = (new ZipArchiver())->archive($paths);

        return new WebResult(
            exportFilePaths: $paths,
            assignedCount: count($result->assignments),
            totalStudents: count($this->students),
            archiveBytes: $archiveBytes,
            archiveName: $archiveName,
        );
    }

    /**
     * Проверяет все заявки на соответствие R-13 (ApplicationValidator, B2-04).
     *
     * Каталоги групп и типов модулей строятся один раз; каждая заявка валидируется
     * по типу группы её студента. Регулярный случай — пустой список: валидные заявки.
     *
     * @param list<Application> $applications заявки из файла (порядок импорта)
     *
     * @return list<string> читаемые нарушения R-13 (пусто — все заявки валидны)
     */
    private function validateR13(array $applications): array
    {
        $typeByGroup = [];
        foreach ($this->groups as $group) {
            $typeByGroup[$group->id] = $group->isTechnical
                ? GroupType::Technical
                : GroupType::Humanitarian;
        }
        $typeByModule = [];
        foreach ($this->modules as $module) {
            $typeByModule[$module->id] = $module->moduleType;
        }

        $groupOfStudent = [];
        foreach ($this->students as $student) {
            $groupOfStudent[$student->id] = $student->groupId;
        }

        $violations = [];
        $validator = new ApplicationValidator();
        foreach ($applications as $application) {
            $groupId = $groupOfStudent[$application->studentId] ?? null;
            if ($groupId === null) {
                $violations[] = 'студент ' . $application->studentId . ' неизвестен (нет в каталоге)';
                continue;
            }
            $groupType = $typeByGroup[$groupId] ?? null;
            if ($groupType === null) {
                $violations[] = 'группа студента ' . $application->studentId . ' неизвестна (нет в каталоге)';
                continue;
            }

            try {
                $validator->validate($application, $groupType, $typeByModule);
            } catch (\InvalidArgumentException $exception) {
                $violations[] = $exception->getMessage();
            }
        }

        return $violations;
    }

    /**
     * Каталог модулей, ключённый по id: использует импортёр (B4-03, `moduleId → Module`)
     * и ассемблер отчёта (SchoolReportAssembler требует ключ — id модуля, не список).
     *
     * @return array<int, Module>
     */
    private function moduleById(): array
    {
        $map = [];
        foreach ($this->modules as $module) {
            $map[$module->id] = $module;
        }

        return $map;
    }

    /**
     * Каталог студентов для импортёра (B4-03): studentId → Student.
     *
     * @return array<int, Student>
     */
    private function studentById(): array
    {
        $map = [];
        foreach ($this->students as $student) {
            $map[$student->id] = $student;
        }

        return $map;
    }
}