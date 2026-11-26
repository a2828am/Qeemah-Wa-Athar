<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/PermissionsSeeder.php
 *
 * 42 صلاحية موزعة على تسعة مجالات، كما ينص database/seeders/README.md:
 *   1. المستخدمون                (5)
 *   2. الأدوار والصلاحيات        (4)
 *   3. المنتجات                  (6)
 *   4. التصنيفات                 (4)
 *   5. الجمعيات                  (5)
 *   6. الطلبات                   (5)
 *   7. التبرعات                  (5)
 *   8. التواصل والتفاعل          (5)
 *   9. إدارة النظام              (3)
 *                                = 42
 *
 * لا توجد أي صلاحية خاصة بالمؤسسات (جميعها جمعيات في جدول associations).
 * الجدول: permissions — مفتاح منع التكرار: permission_code (UNIQUE)
 */

declare(strict_types=1);

/**
 * @return array<string, array{label:string, items: array<string,string>}>
 */
function seed_permissions_sections(): array
{
    return [
        'users' => [
            'label' => 'المستخدمون',
            'items' => [
                'view'   => 'عرض المستخدمين',
                'create' => 'إضافة مستخدم',
                'update' => 'تعديل بيانات مستخدم',
                'delete' => 'حذف مستخدم',
                'ban'    => 'حظر أو إلغاء حظر مستخدم',
            ],
        ],
        'roles' => [
            'label' => 'الأدوار والصلاحيات',
            'items' => [
                'view'   => 'عرض الأدوار والصلاحيات',
                'create' => 'إضافة دور',
                'update' => 'تعديل دور',
                'assign' => 'إسناد صلاحيات إلى دور',
            ],
        ],
        'products' => [
            'label' => 'المنتجات',
            'items' => [
                'view'            => 'عرض المنتجات',
                'create'          => 'إضافة منتج',
                'update'          => 'تعديل منتج',
                'delete'          => 'حذف منتج',
                'approve'         => 'اعتماد أو رفض نشر منتج',
                'moderate_reports'=> 'مراجعة بلاغات المنتجات',
            ],
        ],
        'categories' => [
            'label' => 'التصنيفات',
            'items' => [
                'view'   => 'عرض التصنيفات',
                'create' => 'إضافة تصنيف',
                'update' => 'تعديل تصنيف',
                'delete' => 'حذف تصنيف',
            ],
        ],
        'associations' => [
            'label' => 'الجمعيات',
            'items' => [
                'view'           => 'عرض الجمعيات',
                'create'         => 'إضافة جمعية',
                'update'         => 'تعديل بيانات جمعية',
                'approve'        => 'اعتماد أو تعليق جمعية',
                'manage_members' => 'إدارة أعضاء الجمعية',
            ],
        ],
        'requests' => [
            'label' => 'الطلبات',
            'items' => [
                'view'    => 'عرض طلبات التبادل والطلبات',
                'create'  => 'إنشاء طلب',
                'update'  => 'تعديل طلب',
                'cancel'  => 'إلغاء طلب',
                'respond' => 'الرد على طلب (قبول أو رفض)',
            ],
        ],
        'donations' => [
            'label' => 'التبرعات',
            'items' => [
                'view'           => 'عرض التبرعات',
                'create'         => 'إنشاء تبرع',
                'receive'        => 'استلام تبرع وتسجيل الاستلام',
                'manage_campaigns'=> 'إدارة حملات التبرع',
                'issue_receipts' => 'إصدار إيصالات الاستلام',
            ],
        ],
        'communication' => [
            'label' => 'التواصل والتفاعل',
            'items' => [
                'send_messages'     => 'إرسال الرسائل',
                'moderate_messages' => 'مراجعة الرسائل وحجبها',
                'submit_rating'     => 'إضافة تقييم',
                'moderate_ratings'  => 'مراجعة التقييمات وإخفاؤها',
                'manage_complaints' => 'معالجة الشكاوى',
            ],
        ],
        'system' => [
            'label' => 'إدارة النظام',
            'items' => [
                'manage_settings'   => 'إدارة إعدادات النظام',
                'generate_reports'  => 'إنشاء التقارير وتصديرها',
                'view_audit_logs'   => 'عرض سجل عمليات الإدارة',
            ],
        ],
    ];
}

/**
 * @return array<int, array<string,mixed>>
 */
function seed_permissions_data(): array
{
    $rows = [];

    foreach (seed_permissions_sections() as $sectionCode => $section) {
        foreach ($section['items'] as $action => $label) {
            $rows[] = [
                'permission_name'    => $label,
                'permission_code'    => $sectionCode . '.' . $action,
                'permission_section' => $section['label'],
                'description'        => $label . ' — مجال: ' . $section['label'],
                'status'             => 1,
            ];
        }
    }

    return $rows;
}

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_permissions(mysqli $db): array
{
    $inserted = $updated = $unchanged = 0;

    foreach (seed_permissions_data() as $row) {
        $result = seed_upsert($db, 'permissions', $row, ['permission_code']);
        match ($result['action']) {
            'inserted'  => $inserted++,
            'updated'   => $updated++,
            default     => $unchanged++,
        };
    }

    return [$inserted, $updated, $unchanged];
}
