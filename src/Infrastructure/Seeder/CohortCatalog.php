<?php

declare(strict_types=1);

namespace App\Infrastructure\Seeder;

/**
 * Каталог краевых когорт сидера (B2-03, план v4 утверждён).
 *
 * Двухстадийная схема наложения маркеров на уже сгенерированных студентов
 * DataSeeder (B2-02): StudentGenerator отдаёт ровно 400 строк с флагами по
 * умолчанию; есть каталог фиксирует, КАКИЕ позиции глобального списка
 * студентов (порядок rows() внутри групп, ID = порядку вставки) получают
 * основные маркеры, а какие — пересечение.
 *
 * Числовая схема (см. {@see CohortCatalog::segments()}):
 *  - стадия 1 (основные непересекающиеся выборки):
 *    целевики 30 (25 тех + 5 гум) -> is_target_quota=1;
 *    «молчуны»-нецелевики 40 -> БЕЗ DB-флага (членство только здесь, для B2-04);
 *    инвалиды-основные 5 -> is_disabled=1;
 *    платники-основные 50 -> is_paid=1;
 *  - стадия 2 (пересечение): первые 5 платников по порядку rows() дополнительно
 *    получают is_disabled=1;
 *  - итоговые флаг-счёты: целевики 30, «молчуны» 40, инвалиды 10 (5+5),
 *    платники 50; различных студентов когорт 125; базовая популяция 275.
 *
 * «Молчуны» (40 нецелевиков + 8 целевиков без заявки) НЕ имеют DB-флага:
 * отсутствие заявки материализуется только в B2-04. Заводить служебные
 * колонки/таблицы под когорты запрещено (правило ревью v4) — SSOT-схема
 * не загрязняется тестовыми артефактами.
 *
 * Недоборный модуль: 17-й технический «МДС: редкое направление» (ИЯТШ),
 * min = UNDERENROLLMENT_MIN / max = UNDERENROLLMENT_MAX. Профиль спроса для
 * B2-04 — ровно D = 6 заявителей ({@see CohortCatalog::underEnrollmentDemand()}),
 * выбираются детерминированно из базовой популяции (275) и непересекаемы
 * с когортами, не подающими заявки.
 */
final class CohortCatalog
{
    /** Целевики (фаза 0 / ADR-004): итоговый счёт флага is_target_quota. */
    public const int TARGET_QUOTA_COUNT = 30;

    /** Из них технических школ. */
    public const int TARGET_QUOTA_TECHNICAL = 25;

    /** Из них гуманитарных (БШ). */
    public const int TARGET_QUOTA_HUMANITARIAN = 5;

    /** Целевики без заявки (ADR-004): подмножество TARGET_QUOTA_COUNT. */
    public const int TARGET_WITHOUT_APPLICATION_COUNT = 8;

    /** «Молчуны»-нецелевики W (фаза 4, R-10): без DB-флага. */
    public const int SILENT_NON_TARGET_COUNT = 40;

    /** Инвалиды-основные (стадия 1) — размер основной выборки. */
    public const int DISABLED_BASE_COUNT = 5;

    /** Платники-основные (стадия 1). */
    public const int PAID_BASE_COUNT = 50;

    /** Пересечение paid ∩ disabled: первые 5 платников по порядку rows(). */
    public const int PAID_DISABLED_INTERSECTION_COUNT = 5;

    /** Хвост конкурса H (константа каталога, утверждено ревью B2-03). */
    public const int H = 30;

    /** «Молчуны» W (фаза 4) — дублирует SILENT_NON_TARGET_COUNT для формулы. */
    public const int W = self::SILENT_NON_TARGET_COUNT;

    /** Недоборный модуль: min_students (заведомый недобор, D=6 << 20). */
    public const int UNDERENROLLMENT_MIN = 20;

    /** Недоборный модуль: max_students. */
    public const int UNDERENROLLMENT_MAX = 30;

    /** Профиль спроса недоборного модуля для B2-04: ровно D=6 заявителей. */
    public const int UNDERENROLLMENT_DEMAND_COUNT = 6;

    /** Из них с приоритетом 1. */
    public const int UNDERENROLLMENT_DEMAND_PRIORITY_1 = 4;

    /** Из них с приоритетом 2. Остальные валидные приоритеты — следующие. */
    public const int UNDERENROLLMENT_DEMAND_PRIORITY_2 = 2;

    /**
     * Сегменты глобального списка студентов (порядок rows() внутри групп).
     * Схема смещений построена по каталогу B2-02: ИШИТР 0..59, ИШНКБ 60..119,
     * ИШНПТ 120..179, ИШПР 180..239, ИШЭ 240..299, ИЯТШ 300..359, БШ 360..399
     * (студенты вставляются в том же порядке, см. SeedCatalog::studentsBySchool()).
     *
     * Стадия 1 (основные выборки, непересекающиеся):
     *  - target.technical  [0, 25)    is_target_quota=1
     *  - target.humanitarian [360, 365) is_target_quota=1
     *  - silent.non_target  [25, 65)   флагов НЕТ (когорта «молчунов» = 40)
     *  - disabled.base      [65, 70)   is_disabled=1
     *  - paid.base          [70, 120)  is_paid=1
     *
     * Стадия 2 (пересечение): первые 5 id из paid.base получают is_disabled=1
     * (см. EdgeSeeder::applyIntersection()).
     *
     * У любого студента ровно один основной маркер; сумма основных выборок =
     * 25 + 5 + 40 + 5 + 50 = 125; база 400 - 125 = 275.
     *
     * @return list<array{name: non-empty-string, offset: int, length: int, flags: array<string, int>}>
     */
    public function segments(): array
    {
        return [
            [
                'name' => 'target.technical',
                'offset' => 0,
                'length' => self::TARGET_QUOTA_TECHNICAL,
                'flags' => ['is_target_quota' => 1],
            ],
            [
                'name' => 'target.humanitarian',
                'offset' => 360,
                'length' => self::TARGET_QUOTA_HUMANITARIAN,
                'flags' => ['is_target_quota' => 1],
            ],
            [
                'name' => 'silent.non_target',
                'offset' => 25,
                'length' => self::SILENT_NON_TARGET_COUNT,
                'flags' => [],
            ],
            [
                'name' => 'disabled.base',
                'offset' => 65,
                'length' => self::DISABLED_BASE_COUNT,
                'flags' => ['is_disabled' => 1],
            ],
            [
                'name' => 'paid.base',
                'offset' => 70,
                'length' => self::PAID_BASE_COUNT,
                'flags' => ['is_paid' => 1],
            ],
        ];
    }

    /**
     * Недоборный модуль каталога (17-й, технический).
     *
     * @return array{schoolCode: non-empty-string, title: non-empty-string,
     *               moduleType: non-empty-string, minStudents: int, maxStudents: int}
     */
    public function underEnrollmentModule(): array
    {
        return [
            'schoolCode' => 'ИЯТШ',
            'title' => 'МДС: редкое направление',
            'moduleType' => 'technical',
            'minStudents' => self::UNDERENROLLMENT_MIN,
            'maxStudents' => self::UNDERENROLLMENT_MAX,
        ];
    }

    /**
     * Профиль спроса недоборного модуля для B2-04.
     *
     * 6 заявителей выбираются детерминированно из базовой популяции (275)
     * и непересекаемы со студентами, не подающими заявки (молчуны 40
     * и целевики без заявки 8). Правило фиксации профиля: B2-04 создаёт
     * ровно этот профиль заявок — D=6 при D < min.
     *
     * @return array{count: int, priorityOne: int, priorityTwo: int}
     */
    public function underEnrollmentDemand(): array
    {
        return [
            'count' => self::UNDERENROLLMENT_DEMAND_COUNT,
            'priorityOne' => self::UNDERENROLLMENT_DEMAND_PRIORITY_1,
            'priorityTwo' => self::UNDERENROLLMENT_DEMAND_PRIORITY_2,
        ];
    }

    /**
     * Итоговое число модулей каталога: 8 тех + 5 гум + 3 свободных + недоборный.
     */
    public function totalModuleCount(): int
    {
        return SeedCatalog::TECHNICAL_MODULE_COUNT
            + SeedCatalog::HUMANITARIAN_MODULE_COUNT
            + SeedCatalog::FREE_MODULE_COUNT
            + 1;
    }
}