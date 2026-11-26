<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/RolePermissionsSeeder.php
 *
 * ربط الأدوار بالصلاحيات (42 صلاحية × الأدوار الخمسة).
 * الجدول: role_permissions — مفتاح منع التكرار: (role_id, permission_id) UNIQUE
 *
 * قاعدة الإسناد (مشتقة من طبيعة الأدوار ومن المخطط، ولا تتجاوز 42 صلاحية):
 *   super_admin         → جميع الصلاحيات (42)
 *   admin               → كل شيء عدا إدارة الأدوار/الصلاحيات وإعدادات النظام وسجل التدقيق
 *   moderator           → صلاحيات المراجعة والاعتدال فقط
 *   association_manager → مجال الجمعيات + الطلبات + التبرعات + التواصل
 *   association_member  → العرض والإنشاء في نطاق الجمعية فقط
 *
 * granted_by يبقى NULL في البيانات الأولية لعدم وجود مستخدمين مُدخلين في السيدات.
 */

declare(strict_types=1);

/**
 * @return array<string, array<int,string>>
 */
function seed_role_permission_matrix(): array
{
    return [
        'super_admin'         => ['*'],
        'admin'               => [
            'users.*', 'products.*', 'categories.*', 'associations.*', 'requests.*', 'donations.*', 'communication.*',
        ],
        'moderator'           => [
            'users.view', 'users.ban',
            'products.view', 'products.approve', 'products.moderate_reports',
            'categories.view',
            'associations.view',
            'requests.view',
            'donations.view',
            'communication.moderate_messages', 'communication.moderate_ratings', 'communication.manage_complaints',
        ],
        'association_manager' => [
            'users.view',
            'products.view', 'products.create', 'products.update',
            'categories.view',
            'associations.view', 'associations.update', 'associations.manage_members',
            'requests.view', 'requests.respond',
            'donations.view', 'donations.receive', 'donations.manage_campaigns', 'donations.issue_receipts',
            'communication.send_messages', 'communication.manage_complaints',
        ],
        'association_member'  => [
            'products.view', 'products.create',
            'categories.view',
            'associations.view',
            'requests.view', 'requests.create',
            'donations.view', 'donations.create',
            'communication.send_messages',
        ],
    ];
}

/**
 * @param array<string, array<int,string>> $matrix
 * @param array<string, string>            $availableCodes  permission_code => permission_code
 *
 * @return array<int,string>
 */
function seed_resolve_role_permissions(array $patterns, array $availableCodes): array
{
    $resolved = [];

    foreach ($patterns as $pattern) {
        if ($pattern === '*') {
            return array_keys($availableCodes); // أكواد الصلاحيات (المفتاح = الكود)
        }

        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -2);
            foreach ($availableCodes as $code) {
                if (str_starts_with($code, $prefix . '.')) {
                    $resolved[$code] = $code;
                }
            }
            continue;
        }

        if (isset($availableCodes[$pattern])) {
            $resolved[$pattern] = $pattern;
        }
    }

    return array_values($resolved);
}

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_role_permissions(mysqli $db): array
{
    // خريطة الأكواد → المعرفات
    $permissionIds = [];
    $result = $db->query('SELECT permission_id, permission_code FROM permissions');
    while ($row = $result->fetch_assoc()) {
        $permissionIds[$row['permission_code']] = (int) $row['permission_id'];
    }

    $roleIds = [];
    $result = $db->query('SELECT role_id, role_code FROM roles');
    while ($row = $result->fetch_assoc()) {
        $roleIds[$row['role_code']] = (int) $row['role_id'];
    }

    $inserted = $updated = $unchanged = 0;

    foreach (seed_role_permission_matrix() as $roleCode => $patterns) {
        if (!isset($roleIds[$roleCode])) {
            throw new RuntimeException("الدور '{$roleCode}' غير موجود؛ شغّل RolesSeeder أولًا.");
        }

        // خريطة للبحث: كود الصلاحية => كود الصلاحية
        $availableCodes = array_combine(array_keys($permissionIds), array_keys($permissionIds));

        foreach (seed_resolve_role_permissions($patterns, $availableCodes) as $permissionCode) {
            if (!isset($permissionIds[$permissionCode])) {
                throw new RuntimeException("الصلاحية '{$permissionCode}' غير موجودة؛ شغّل PermissionsSeeder أولًا.");
            }

            $result = seed_upsert($db, 'role_permissions', [
                'role_id'       => $roleIds[$roleCode],
                'permission_id' => $permissionIds[$permissionCode],
            ], ['role_id', 'permission_id']);

            match ($result['action']) {
                'inserted' => $inserted++,
                'updated'  => $updated++,
                default    => $unchanged++,
            };
        }
    }

    return [$inserted, $updated, $unchanged];
}
