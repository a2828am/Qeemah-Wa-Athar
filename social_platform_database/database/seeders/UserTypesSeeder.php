<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/UserTypesSeeder.php
 *
 * أنواع المستخدمين الثلاثة المنصوص عليها في database/seeders/README.md:
 *   1) مستخدم عادي            → individual
 *   2) ممثل جمعية خيرية        → association_representative
 *   3) مدير المنصة            → platform_manager
 *
 * الجدول: user_types
 * مفتاح منع التكرار: type_code (UNIQUE في المخطط)
 */

declare(strict_types=1);

/**
 * @return array<int, array<string,mixed>>
 */
function seed_user_types_data(): array
{
    return [
        [
            'type_name'   => 'مستخدم عادي',
            'type_code'   => 'individual',
            'description' => 'مستخدم فرد يستعرض المنتجات وينشرها ويطلب التبادل أو التبرع.',
            'status'      => 1,
        ],
        [
            'type_name'   => 'ممثل جمعية خيرية',
            'type_code'   => 'association_representative',
            'description' => 'مستخدم يمثل جمعية خيرية معتمدة داخل جدول associations.',
            'status'      => 1,
        ],
        [
            'type_name'   => 'مدير المنصة',
            'type_code'   => 'platform_manager',
            'description' => 'مستخدم يدير المنصة، وتُحدد صلاحياته عبر الأدوار والصلاحيات لا عبر الاسم.',
            'status'      => 1,
        ],
    ];
}

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_user_types(mysqli $db): array
{
    $inserted = $updated = $unchanged = 0;

    foreach (seed_user_types_data() as $row) {
        $result = seed_upsert($db, 'user_types', $row, ['type_code']);
        match ($result['action']) {
            'inserted'  => $inserted++,
            'updated'   => $updated++,
            default     => $unchanged++,
        };
    }

    return [$inserted, $updated, $unchanged];
}
