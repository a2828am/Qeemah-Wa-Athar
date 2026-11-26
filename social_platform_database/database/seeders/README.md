# Seeders — البيانات المرجعية لمكنون | قيمة وأثر

مجلد مستقل يحتوي **سيدرات PHP خالص** (MySQLi) لإدخال البيانات المرجعية فقط، دون Laravel ودون أي إطار عمل، ووفق ما يحدده `database/seeders/README.md` وبنيّة الجداول والأعمدة الفعلية في `database/migrations/social_platform_database.sql`.

> هذا المجلد **لا يُنشئ ولا يُعدّل** أي جدول أو عمود أو إعداد قائم، ولا يحتوي أي مجلدات فرعية، ولا يمسّ أي ملف خارج نفسه.

---

## 1. محتويات المجلد

| الملف | الوظيفة |
|---|---|
| `run.php` | نقطة التشغيل: الاتصال، ترتيب الاعتماديات، المعاملات، ملخص النتائج، تحقق نهائي |
| `helpers.php` | دوال مشتركة: `seed_upsert` (بديل `updateOrCreate`)، `seed_find_id`، `seed_report`، `seed_transaction` |
| `UserTypesSeeder.php` | أنواع المستخدمين الثلاثة → `user_types` |
| `RolesSeeder.php` | الأدوار الخمسة → `roles` |
| `PermissionsSeeder.php` | 42 صلاحية في 9 مجالات → `permissions` |
| `RolePermissionsSeeder.php` | ربط الأدوار بالصلاحيات → `role_permissions` |
| `CategoriesSeeder.php` | 30 تصنيفًا رئيسيًا (`parent_category_id = NULL`) → `categories` |
| `AdminUserSeeder.php` | حساب مدير المنصة الأول + إسناده لدور `super_admin` → `users` + `user_roles` |
| `SystemSettingsSeeder.php` | إعدادات الاسم/الوصف/اللغة/العملة/التسجيل/المنتجات/التبرعات/الرسائل/التصنيفات/الجمعيات → `system_settings` |

الوضع الموصى به:

```
social_platform_database/
└── database/
    ├── config/
    │   └── connection.php        # (موجود مسبقًا — لا يُعدَّل)
    └── seeders/                  # ← هذا المجلد
```

---

## 2. التشغيل

```bash
# 1) استيراد المخطط أولًا
mysql -u root -p social_platform_database < database/migrations/social_platform_database.sql

# 2) تشغيل السيدرات (بيانات الاتصال الافتراضية من database/config/README.md)
php database/seeders/run.php

# أو باستخدام ملف الاتصال الموحد للمشروع (يُعيد كائن mysqli)
php database/seeders/run.php --use-connection-file
```

تُقرأ بيانات الاتصال من متغيرات البيئة، وبالافتراضيات المعتمدة في `database/config/README.md`:

| المتغير | الافتراضي |
|---|---|
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| `DB_DATABASE` | `social_platform_database` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | (فارغة) |

الترميز مضبوط على `utf8mb4` لدعم العربية. المتطلبات: PHP 8.0+ مع امتداد `mysqli`.

---

## 3. ترتيب الاعتماديات (كما يُنفَّذ فعليًا)

```
user_types → roles → permissions → role_permissions → categories → users (مدير المنصة) → user_roles → system_settings
```

كل سيدر يُنفَّذ داخل **معاملة واحدة** (جداول InnoDB)، فأي خطأ يوقف التنفيذ ويتراجع عن آخر معاملة.

## 4. منع التكرار (Idempotency)

استُخدم `INSERT ... ON DUPLICATE KEY UPDATE` (المكافئ لـ `updateOrCreate`) اعتمادًا على مفاتيح فريدة موجودة فعلًا في المخطط:

| الجدول | مفتاح منع التكرار |
|---|---|
| `user_types` | `type_code` (UNIQUE) |
| `roles` | `role_code` (UNIQUE) |
| `permissions` | `permission_code` (UNIQUE) |
| `role_permissions` | `(role_id, permission_id)` UNIQUE |
| `categories` | `category_code` (UNIQUE) |
| `users` | `username` (UNIQUE — والتحقق يدويًا قبل الإدخال) |
| `user_roles` | تحقق يدوي على `(user_id, role_id, association_id IS NULL)` لأن MySQL لا يمنع تكرار `NULL` |
| `system_settings` | `setting_name` (UNIQUE) |

إعادة التشغيل لا تُنشئ أي تكرار: عدد الصفوف يبقى ثابتًا وتظهر جميعها بـ «بدون تغيير».

---

## 5. البيانات المُدخلة

### أنواع المستخدمين (3)
`individual` مستخدم عادي · `association_representative` ممثل جمعية خيرية · `platform_manager` مدير المنصة.

### الأدوار (5)
`super_admin`, `admin`, `moderator`, `association_manager`, `association_member`.

### الصلاحيات (42) موزعة على تسعة مجالات
| المجال | العدد |
|---|---|
| المستخدمون `users.*` | 5 |
| الأدوار والصلاحيات `roles.*` | 4 |
| المنتجات `products.*` | 6 |
| التصنيفات `categories.*` | 4 |
| الجمعيات `associations.*` | 5 |
| الطلبات `requests.*` | 5 |
| التبرعات `donations.*` | 5 |
| التواصل والتفاعل `communication.*` | 5 |
| إدارة النظام `system.*` | 3 |
| **الإجمالي** | **42** |

لا توجد أي صلاحية خاصة بالمؤسسات. صيغة الكود: `section.action` (مثل `products.approve`)، والمجال يُخزَّن في `permission_section`.

### إسناد الصلاحيات للأدوار (114 صفًا)
| الدور | عدد الصلاحيات |
|---|---|
| `super_admin` | 42 (الكل) |
| `admin` | 35 |
| `association_manager` | 16 |
| `moderator` | 12 |
| `association_member` | 9 |

`granted_by` يبقى `NULL` لعدم وجود مستخدمين مُدخلين في السيدات.

### التصنيفات (30)
ثلاثون تصنيفًا رئيسيًا، جميعها `parent_category_id = NULL`، مع `category_code` فريد و`display_order` متسلسل و`status = 1`.
مسارات الصور مبدئية بصيغة `storage/categories/<code>.png` ولا تحتاج وجود الملف لنجاح الإدخال.

### مدير المنصة (حساب واحد)
`platform_manager` / `platform.manager@example.com` — بريد وقيم غير حقيقية، ودور `super_admin` بإسناد `active`.

### إعدادات النظام (20)
`site_name`, `site_description`, `default_language` (ar), `default_currency` (YER), `timezone` (Asia/Aden), `registration_enabled`, `registration_requires_email_verification`, `registration_requires_admin_approval`, `products_require_approval`, `products_max_images`, `products_default_condition`, `donations_enabled`, `donations_require_receipt`, `donations_campaigns_enabled`, `messages_enabled`, `messages_max_attachment_mb`, `categories_max_depth`, `categories_enabled`, `associations_require_verification`, `associations_auto_approve`.

جميعها ضمن قيم `system_settings.setting_category` المسموحة في ENUM، وبلا أي إعداد لكيانات غير موجودة في المخطط.

---

## 6. تفاصيل كانت ناقصة/غامضة في ملفات README وطريقة معالجتها

1. **أسماء الصلاحيات الـ42 غير مذكورة** — ذُكر العدد (42) والمجالات التسعة فقط. اعتُمدت أسماء وظيفية متسقة مع الجداول الفعلية بنفس التوزيع، وبصيغة `section.action`.
2. **توزيع الصلاحيات على الأدوار غير محدد** — اعتُمد إسناد مشتق من طبيعة كل دور وضمن الصلاحيات الـ42 فقط (الجدول أعلاه). لم تُضف أي صلاحية جديدة لتغطية الفروق.
3. **عدد ونصوص إعدادات النظام غير محدد** — ذُكرت الموضوعات فقط (الاسم…الجمعيات)، فاعتُمدت 20 إعدادًا تغطيها حصرًا. مفتاح `timezone` أُضيف لأنه يخدم موضوع اللغة/التوطين والمخطط يقبل `localization`؛ يمكن حذفه بلا أثر على بقية البيانات.
4. **أسماء التصنيفات الثلاثين غير محددة** — اعتُمدت تسميات عربية مرتبطة بسياق المنصة (إلكترونيات، أثاث، ملابس، مواد غذائية، مستلزمات مخيمات…)، مع `category_code` إنجليزي فريد.
5. **كلمة مرور مدير المنصة** — القاعدة تمنع كلمات مرور حقيقية، لذا تُولَّد كلمة عشوائية عند التشغيل وتُخزَّن كبصمة `bcrypt` فقط. لا يمكن معرفتها لاحقًا، ويجب إعادة تعيينها من قاعدة البيانات أو عبر مسار استعادة كلمة المرور قبل أول دخول. وإعادة تشغيل السيدر **لا تستبدل** كلمة مرور حساب موجود.
6. **عدد مستخدمي السيدات** — لم يُطلب سوى أنواع المستخدمين والأدوار؛ أُضيف حساب مدير واحد فقط لربط `user_roles` بدور `super_admin` (لأن `assigned_by` و`granted_by` يحتاجان مستخدمًا). لا توجد بيانات أشخاص فعلية.
7. **قيم `role_name`** — المخطط يفرض `role_name` نصًا عربيًا في الواجهة مع `role_code` ثابت؛ اعتُمدت تسميات عربية مع الأكواد الإنجليزية الخمسة المنصوص عليها.
8. **ملاحظة على ملف المخطط**: الاستيراد على MariaDB 11.8 يرفض 3 عبارات DDL في الملف المرفق نفسه (`CHECK` على `donations`، و`CHECK` على `ratings`، ومفتاح `donation_receipts` الأجنبي) لأسباب تخصّ محرك MariaDB. الجداول التي تخصّ السيدرات أُنشئت وسُيّدت بنجاح. لم يُعدَّل الملف لأن التعليمات تمنع التعديل — يُستحسن استيراده على MySQL 8.0.

---

## 7. نتيجة اختبار فعلية

اختبار على MariaDB 11.8 بعد استيراد المخطط:

```
الإجمالي — مُضاف: 216، مُحدَّث: 0، بدون تغيير: 0      (التشغيل الأول)
الإجمالي — مُضاف: 0، مُحدَّث: 0، بدون تغيير: 216      (إعادة التشغيل)

✔ user_types: 3 (المتوقع 3)
✔ roles: 5 (المتوقع 5)
✔ permissions: 42 (المتوقع 42)
✔ categories: 30 (المتوقع 30)
```

النص العربي يُخزَّن ويُقرأ بشكل صحيح (تحقق عبر `--default-character-set=utf8mb4`)، والتصنيفات الثلاثون جميعها `parent_category_id IS NULL`.
