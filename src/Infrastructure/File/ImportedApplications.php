<?php

declare(strict_types=1);

namespace App\Infrastructure\File;

use App\Domain\Model\Application;

/**
 * Результат импорта заявок (B4-03): доменные `Application` и читаемые ошибки валидации.
 *
 * `Application::$id` — временный синтетический порядковый номер 1..K внутри файла
 * (конструктор B1-06 требует id > 0); в БД при сохранении (B4-04) перейдёт на
 * автоинкремент. Ошибки пусты, если файл прочитан целиком без замечаний.
 */
final readonly class ImportedApplications
{
    /** @var list<Application> заявки (Domain, B1-06) */
    public array $applications;

    /** @var list<string> ошибки вида «файл: строка N: причина» */
    public array $errors;

    /**
     * Параметры принимаются лоуз-типами и валидируются на входе (рантайм-защита DTO,
     * паттерн ExportReportFile, B4-01): поля результата — строго list<Application>
     * и list<string>.
     *
     * @param array<mixed> $applications
     * @param array<mixed> $errors
     */
    public function __construct(array $applications, array $errors)
    {
        foreach ($applications as $application) {
            if (!$application instanceof Application) {
                throw new \InvalidArgumentException(
                    'ImportedApplications: applications должны содержать только Application',
                );
            }
        }
        foreach ($errors as $error) {
            if (!is_string($error)) {
                throw new \InvalidArgumentException('ImportedApplications: errors должны содержать только строки');
            }
        }

        $this->applications = array_values($applications);
        $this->errors = array_values($errors);
    }
}