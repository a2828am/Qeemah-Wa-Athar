<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/SystemSettingsSeeder.php
 *
 * إعدادات النظام المصرح بها في database/seeders/README.md فقط:
 * الاسم، الوصف، اللغة، العملة، التسجيل، المنتجات، التبرعات، الرسائل، التصنيفات، الجمعيات.
 * لا إعدادات خاصة بالمؤسسات ولا بكيانات غير موجودة في المخطط.
 *
 * الجدول: system_settings — مفتاح منع التكرار: setting_name (UNIQUE)
 * setting_category محصور في ENUM المخطط:
 * general | localization | security | products | associations | uploads | interface | donations | community | communication | system
 */

declare(strict_types=1);

/**
 * @return array<int, array<string,mixed>>
 */
function seed_system_settings_data(): array
{
    return [
        // الاسم والوصف
        [
            'setting_name'     => 'site_name',
            'setting_value'    => 'مكنون | قيمة وأثر',
            'value_type'       => 'string',
            'description'      => 'الاسم الظاهر للمنصة في الواجهات والعناوين.',
            'setting_category' => 'general',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'site_description',
            'setting_value'    => 'منصة اجتماعية لتبادل المنتجات والتبرع بها بين المستخدمين والجمعيات الخيرية.',
            'value_type'       => 'string',
            'description'      => 'وصف مختصر للمنصة يظهر في الصفحة الرئيسية ووسوم المشاركة.',
            'setting_category' => 'general',
            'is_editable'      => 1,
        ],
        // اللغة والعملة
        [
            'setting_name'     => 'default_language',
            'setting_value'    => 'ar',
            'value_type'       => 'string',
            'description'      => 'اللغة الافتراضية للواجهة.',
            'setting_category' => 'localization',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'default_currency',
            'setting_value'    => 'YER',
            'value_type'       => 'string',
            'description'      => 'العملة الافتراضية المستخدمة في الأسعار والطلبات.',
            'setting_category' => 'localization',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'timezone',
            'setting_value'    => 'Asia/Aden',
            'value_type'       => 'string',
            'description'      => 'المنطقة الزمنية المعتمدة في تسجيل التواريخ.',
            'setting_category' => 'localization',
            'is_editable'      => 1,
        ],
        // التسجيل
        [
            'setting_name'     => 'registration_enabled',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'السماح بتسجيل مستخدمين جدد في المنصة.',
            'setting_category' => 'general',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'registration_requires_email_verification',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'إلزام المستخدم الجديد بتأكيد بريده الإلكتروني قبل تفعيل الحساب.',
            'setting_category' => 'security',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'registration_requires_admin_approval',
            'setting_value'    => '0',
            'value_type'       => 'boolean',
            'description'      => 'إلزام اعتماد الإدارة للحسابات الجديدة قبل التفعيل.',
            'setting_category' => 'security',
            'is_editable'      => 1,
        ],
        // المنتجات
        [
            'setting_name'     => 'products_require_approval',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'مراجعة المنتجات من الإدارة قبل النشر (status = pending).',
            'setting_category' => 'products',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'products_max_images',
            'setting_value'    => '8',
            'value_type'       => 'integer',
            'description'      => 'أقصى عدد صور يمكن إرفاقه لكل منتج في جدول product_images.',
            'setting_category' => 'products',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'products_default_condition',
            'setting_value'    => 'used',
            'value_type'       => 'string',
            'description'      => 'حالة المنتج الافتراضية عند الإنشاء (new, like_new, used, needs_repair).',
            'setting_category' => 'products',
            'is_editable'      => 1,
        ],
        // التبرعات
        [
            'setting_name'     => 'donations_enabled',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'تمكين مسار التبرعات بالمنتجات.',
            'setting_category' => 'donations',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'donations_require_receipt',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'إلزام الجمعية بإصدار إيصال استلام لكل تبرع في جدول donation_receipts.',
            'setting_category' => 'donations',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'donations_campaigns_enabled',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'تمكين حملات التبرع للجمعيات في جدول donation_campaigns.',
            'setting_category' => 'donations',
            'is_editable'      => 1,
        ],
        // الرسائل
        [
            'setting_name'     => 'messages_enabled',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'تمكين المحادثات والرسائل داخل المنصة.',
            'setting_category' => 'communication',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'messages_max_attachment_mb',
            'setting_value'    => '5',
            'value_type'       => 'integer',
            'description'      => 'أقصى حجم مرفق مسموح في الرسائل بالميغابايت.',
            'setting_category' => 'communication',
            'is_editable'      => 1,
        ],
        // التصنيفات
        [
            'setting_name'     => 'categories_max_depth',
            'setting_value'    => '2',
            'value_type'       => 'integer',
            'description'      => 'أقصى عمق مسموح للتصنيفات الفرعية عبر parent_category_id.',
            'setting_category' => 'products',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'categories_enabled',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'إظهار التصنيفات في واجهة البحث والنشر.',
            'setting_category' => 'products',
            'is_editable'      => 1,
        ],
        // الجمعيات
        [
            'setting_name'     => 'associations_require_verification',
            'setting_value'    => '1',
            'value_type'       => 'boolean',
            'description'      => 'إلزام التحقق من وثائق الجمعية قبل اعتمادها (verification_status).',
            'setting_category' => 'associations',
            'is_editable'      => 1,
        ],
        [
            'setting_name'     => 'associations_auto_approve',
            'setting_value'    => '0',
            'value_type'       => 'boolean',
            'description'      => 'اعتماد الجمعيات تلقائيًا عند التسجيل دون مراجعة إدارية.',
            'setting_category' => 'associations',
            'is_editable'      => 1,
        ],
    ];
}

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_system_settings(mysqli $db): array
{
    $inserted = $updated = $unchanged = 0;

    foreach (seed_system_settings_data() as $setting) {
        $result = seed_upsert($db, 'system_settings', $setting, ['setting_name']);
        match ($result['action']) {
            'inserted' => $inserted++,
            'updated'  => $updated++,
            default    => $unchanged++,
        };
    }

    return [$inserted, $updated, $unchanged];
}
