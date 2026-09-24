<?php

declare(strict_types=1);

namespace App\Infrastructure\Seeder;

/**
 * Каталог сидера (B2-02): фиксированные, детерминированные данные.
 *
 * Источники:
 *  - коды школ — реальные коды ТПУ из [[context/regulations/school-codes]]
 *    (7 школ из Р1; ИШХБМТ/ШИП не входят);
 *  - типы групп: 6 технических школ → технические группы, БШ → гуманитарная
 *    (допущение плана B2-02, в КБ не зафиксировано);
 *  - количество модулей — именованные константы: 8 технических, 5 гуманитарных,
 *    3 свободных (R-19);
 *  - вместимость min 10–20, max 30–60 на модуль; пары подобраны под три
 *    инварианта вместимости (см. {@see SeedCatalog::module}).
 *
 * Именования модулей и дисциплин вымышленные (регламент списки не приводит).
 * Модули тех/гум хранятся в {@see SeedCatalog::TECHNICAL_MODULES} и
 * {@see SeedCatalog::HUMANITARIAN_MODULES}, свободные — с подмножествами
 * школ в {@see SeedCatalog::FREE_MODULE_ELIGIBLE_SCHOOLS}.
 */
final class SeedCatalog
{
    /** Количество технических модулей (TECHNICAL_MODULE_COUNT). */
    public const int TECHNICAL_MODULE_COUNT = 8;

    /** Количество гуманитарных модулей (HUMANITARIAN_MODULE_COUNT). */
    public const int HUMANITARIAN_MODULE_COUNT = 5;

    /** Количество свободных модулей (FREE_MODULE_COUNT, R-19). */
    public const int FREE_MODULE_COUNT = 3;

    /** Нижняя граница диапазона min-вместимости. */
    public const int MIN_STUDENTS_MIN = 10;

    /** Верхняя граница диапазона min-вместимости. */
    public const int MIN_STUDENTS_MAX = 20;

    /** Нижняя граница диапазона max-вместимости. */
    public const int MAX_STUDENTS_MIN = 30;

    /** Верхняя граница диапазона max-вместимости. */
    public const int MAX_STUDENTS_MAX = 60;

    /** Учебный год реализации МДС для приёма 2025 (первый год, осень 2026 = 3-й семестр). */
    public const string ACADEMIC_YEAR = '2026-2027';

    /** Единый год приёма (R-01). */
    public const int ADMISSION_YEAR = 2025;

    /** Итоговое число студентов (в диапазоне 300–500, DoD ветки). */
    public const int STUDENT_COUNT = 400;

    /**
     * Школы каталога. Коды — реальные (справочник), названия — реконструкция
     * по протоколам. Технические: 6 ИШ*, гуманитарная: БШ.
     *
     * @return list<array{code: non-empty-string, name: non-empty-string, isTechnical: bool}>
     */
    public function schools(): array
    {
        return [
            ['code' => 'ИШИТР', 'name' => 'Инженерная школа информационных технологий и робототехники', 'isTechnical' => true],
            ['code' => 'ИШНКБ', 'name' => 'Инженерная школа непостоянного тока и кибербезопасности', 'isTechnical' => true],
            ['code' => 'ИШНПТ', 'name' => 'Инженерная школа новых производственных технологий', 'isTechnical' => true],
            ['code' => 'ИШПР', 'name' => 'Инженерная школа прикладных разработок', 'isTechnical' => true],
            ['code' => 'ИШЭ', 'name' => 'Инженерная школа энергетики', 'isTechnical' => true],
            ['code' => 'ИЯТШ', 'name' => 'Инженерная школа ядерных технологий', 'isTechnical' => true],
            ['code' => 'БШ', 'name' => 'Базовая школа', 'isTechnical' => false],
        ];
    }

    /**
     * Технические модули. Пары min/max подобраны так, что
     * Σmax ≥ N_тех (инвариант (а)): 55+55+50+50+45+45+40+40 = 380 ≥ 360.
     *
     * @return list<array{schoolCode: non-empty-string, title: non-empty-string, minStudents: int, maxStudents: int}>
     */
    public function technicalModules(): array
    {
        return [
            ['schoolCode' => 'ИШИТР', 'title' => 'МДС: Интеллектуальные цифровые системы', 'minStudents' => 15, 'maxStudents' => 55],
            ['schoolCode' => 'ИШНКБ', 'title' => 'МДС: Кибербезопасность промышленных систем', 'minStudents' => 12, 'maxStudents' => 55],
            ['schoolCode' => 'ИШНПТ', 'title' => 'МДС: Аддитивные производственные технологии', 'minStudents' => 15, 'maxStudents' => 50],
            ['schoolCode' => 'ИШПР', 'title' => 'МДС: Промышленный интернет вещей', 'minStudents' => 12, 'maxStudents' => 50],
            ['schoolCode' => 'ИШЭ', 'title' => 'МДС: Умные сети электроснабжения', 'minStudents' => 12, 'maxStudents' => 45],
            ['schoolCode' => 'ИЯТШ', 'title' => 'МДС: Ядерные технологии в энергетике', 'minStudents' => 10, 'maxStudents' => 45],
            ['schoolCode' => 'ИШИТР', 'title' => 'МДС: Робототехника и сенсорика', 'minStudents' => 10, 'maxStudents' => 40],
            ['schoolCode' => 'ИШНПТ', 'title' => 'МДС: Цифровое моделирование изделий', 'minStudents' => 10, 'maxStudents' => 40],
        ];
    }

    /**
     * Гуманитарные модули. Σmax = 40+35+35+30+30 = 170 ≥ N_гум (инвариант (а)).
     *
     * @return list<array{schoolCode: non-empty-string, title: non-empty-string, minStudents: int, maxStudents: int}>
     */
    public function humanitarianModules(): array
    {
        return [
            ['schoolCode' => 'БШ', 'title' => 'МДС: Научная коммуникация и медиа', 'minStudents' => 10, 'maxStudents' => 40],
            ['schoolCode' => 'БШ', 'title' => 'МДС: Управление инженерными проектами', 'minStudents' => 10, 'maxStudents' => 35],
            ['schoolCode' => 'БШ', 'title' => 'МДС: Экономика и право инноваций', 'minStudents' => 10, 'maxStudents' => 35],
            ['schoolCode' => 'БШ', 'title' => 'МДС: Деловой русский и английский язык', 'minStudents' => 10, 'maxStudents' => 30],
            ['schoolCode' => 'БШ', 'title' => 'МДС: Социальное проектирование', 'minStudents' => 10, 'maxStudents' => 30],
        ];
    }

    /**
     * Свободные модули (ровно 3, R-19). Параметры:
     *  - объединение {@see SeedCatalog::FREE_MODULE_ELIGIBLE_SCHOOLS} = все 7 школ (инвариант (б));
     *  - Σmax = 50+45+40 = 135 ≥ 0.25×400 = 100 (инвариант (в)).
     *
     * @return list<array{schoolCode: non-empty-string, title: non-empty-string, minStudents: int, maxStudents: int}>
     */
    public function freeModules(): array
    {
        return [
            ['schoolCode' => 'ИШИТР', 'title' => 'Свободный модуль: Сквозные цифровые технологии', 'minStudents' => 15, 'maxStudents' => 50],
            ['schoolCode' => 'ИШПР', 'title' => 'Свободный модуль: Предпринимательский трек', 'minStudents' => 12, 'maxStudents' => 45],
            ['schoolCode' => 'БШ', 'title' => 'Свободный модуль: Устойчивое развитие', 'minStudents' => 10, 'maxStudents' => 40],
        ];
    }

    /**
     * Подмножества школ для свободных модулей (по индексу freeModules()).
     * Объединение всех подмножеств = все 7 школ каталога (инвариант (б)).
     *
     * @return list<list<non-empty-string>>
     */
    public function freeModuleEligibleSchools(): array
    {
        return [
            ['ИШИТР', 'ИШНКБ', 'ИШНПТ'],
            ['ИШПР', 'ИШЭ', 'ИШНКБ'],
            ['ИЯТШ', 'БШ', 'ИШПР', 'ИШИТР'],
        ];
    }

    /**
     * Число студентов по школам: 6 технических групп по 60 + БШ 40 = 400.
     *
     * @return array<non-empty-string, int>
     */
    public function studentsBySchool(): array
    {
        return [
            'ИШИТР' => 60,
            'ИШНКБ' => 60,
            'ИШНПТ' => 60,
            'ИШПР' => 60,
            'ИШЭ' => 60,
            'ИЯТШ' => 60,
            'БШ' => 40,
        ];
    }
}