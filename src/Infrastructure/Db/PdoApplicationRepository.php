<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;

/**
 * Реализация ApplicationRepositoryInterface поверх PDO (B2-01).
 *
 * Читает заявки и их элементы (приоритеты). Сортировка items по priority
 * (в запросе) позволяет конструктору Application подтвердить непрерывность
 * ряда {1..N} (контракт #2, R-12); репозиторий валидацию не дублирует.
 */
final readonly class PdoApplicationRepository implements ApplicationRepositoryInterface
{
    private \PDO $pdo;

    /**
     * Репозиторий заявок поверх PDO (B2-01).
     *
     * @param PdoFactory $factory фабрика PDO-соединения
     */
    public function __construct(PdoFactory $factory)
    {
        $this->pdo = $factory->create();
    }

    /**
     * @return list<Application>
     */
    public function findAll(): array
    {
        $applicationStmt = $this->pdo->prepare(
            'SELECT id, student_id, submitted_at
             FROM applications
             ORDER BY id',
        );
        $applicationStmt->execute();
        /** @var list<array<string, string|null>> $applicationRows */
        $applicationRows = $applicationStmt->fetchAll(\PDO::FETCH_ASSOC);

        $itemStmt = $this->pdo->prepare(
            'SELECT application_id, module_id, priority
             FROM application_items
             ORDER BY application_id, priority',
        );
        $itemStmt->execute();
        /** @var list<array<string, string|null>> $itemRows */
        $itemRows = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);

        /** @var array<int, array<int, ApplicationItem>> $itemsByApplication */
        $itemsByApplication = [];
        foreach ($itemRows as $row) {
            $applicationId = (int) $row['application_id'];
            $itemsByApplication[$applicationId][] = new ApplicationItem(
                (int) $row['module_id'],
                (int) $row['priority'],
            );
        }

        $applications = [];
        foreach ($applicationRows as $row) {
            $id = (int) $row['id'];
            $applications[] = new Application(
                $id,
                (int) $row['student_id'],
                new \DateTimeImmutable((string) $row['submitted_at']),
                array_values($itemsByApplication[$id] ?? []),
            );
        }

        return $applications;
    }
}