<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/RolesSeeder.php
 *
 * الأدوار الخمسة المنصوص عليها في database/seeders/README.md:
 *   Super Admin, Admin, Moderator, Association Manager, Association Member
 *
 * الجدول: roles
 * مفتاح منع التكرار: role_code (UNIQUE في المخطط)
 */

declare(strict_types=1);

/**
 * @return array<int, array<string,mixed>>
 */
function seed_roles_data(): array
{
    return [
        [
            'role_name'      => 'مدير عام',
            'role_code'      => 'super_admin',
            'description'    => 'صلاحيات كاملة على المنصة بما فيها الأدوار والصلاحيات وإعدادات النظام.',
            'is_system_role' => 1,
            'status'         => 1,
        ],
        [
            'role_name'      => 'مدير',
            'role_code'      => 'admin',
            'description'    => 'إدارة المحتوى والمستخدمين والجمعيات والطلبات والتبرعات والتقارير.',
            'is_system_role' => 1,
            'status'         => 1,
        ],
        [
            'role_name'      => 'مشرف',
            'role_code'      => 'moderator',
            'description'    => 'مراجعة المنتجات والبلاغات والشكاوى والتقييمات والرسائل.',
            'is_system_role' => 1,
            'status'         => 1,
        ],
        [
            'role_name'      => 'مدير جمعية',
            'role_code'      => 'association_manager',
            'description'    => 'إدارة بيانات الجمعية وأعضائها وحملاتها واستلام التبرعات.',
            'is_system_role' => 0,
            'status'         => 1,
        ],
        [
            'role_name'      => 'عضو جمعية',
            'role_code'      => 'association_member',
            'description'    => 'عضو في جمعية خيرية بصلاحيات محدودة داخل نطاق الجمعية.',
            'is_system_role' => 0,
            'status'         => 1,
        ],
    ];
}

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_roles(mysqli $db): array
{
    $inserted = $updated = $unchanged = 0;

    foreach (seed_roles_data() as $row) {
        $result = seed_upsert($db, 'roles', $row, ['role_code']);
        match ($result['action']) {
            'inserted'  => $inserted++,
            'updated'   => $updated++,
            default     => $unchanged++,
        };
    }

    return [$inserted, $updated, $unchanged];
}
