<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/AdminUserSeeder.php
 *
 * إنشاء حساب مدير المنصة الأول + إسناد الدور super_admin في جدول user_roles.
 *
 * ⚠️ لا تُدرج كلمات مرور حقيقية (قاعدة صريحة في database/seeders/README.md):
 *    تُولَّد كلمة مرور عشوائية عند التشغيل وتُخزَّن كبصمة bcrypt فقط.
 *    لا يمكن استرجاع الكلمة النصية لاحقًا، ويجب إعادة تعيينها من قاعدة البيانات
 *    أو من واجهة استعادة كلمة المرور قبل أول تسجيل دخول.
 *
 * الجداول: users (مفتاح التكرار: username) — user_roles (مفتاح التكرار: user_id + role_id + association_id)
 */

declare(strict_types=1);

/** البريد المستخدم في السيد — نموذج غير حقيقي */
const SEED_ADMIN_USERNAME = 'platform_manager';
const SEED_ADMIN_EMAIL    = 'platform.manager@example.com';

/**
 * @return array{0:int,1:int,2:int} [inserted, updated, unchanged]
 */
function seed_admin_user(mysqli $db): array
{
    $inserted = $updated = $unchanged = 0;

    // 1) نوع المستخدم "مدير المنصة"
    $stmt = $db->prepare('SELECT user_type_id FROM user_types WHERE type_code = ? LIMIT 1');
    $typeCode = 'platform_manager';
    $stmt->bind_param('s', $typeCode);
    $stmt->execute();
    $userType = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$userType) {
        throw new RuntimeException("نوع المستخدم 'platform_manager' غير موجود؛ شغّل UserTypesSeeder أولًا.");
    }

    // 2) المستخدم
    $existsStmt = $db->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
    $username   = SEED_ADMIN_USERNAME;
    $existsStmt->bind_param('s', $username);
    $existsStmt->execute();
    $existing = $existsStmt->get_result()->fetch_assoc();
    $existsStmt->close();

    if ($existing) {
        $userId = (int) $existing['user_id'];
        $unchanged++;
        seed_line(seed_color(
            '    ℹ حساب المدير ' . SEED_ADMIN_USERNAME . ' موجود مسبقًا — لم تُعدَّل كلمة المرور.',
            'gray'
        ));
    } else {
        $row = [
            'user_type_id'     => (int) $userType['user_type_id'],
            'full_name'        => 'مدير المنصة',
            'username'         => SEED_ADMIN_USERNAME,
            'email'            => SEED_ADMIN_EMAIL,
            'phone'            => null,
            'password_hash'    => password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT),
            'profile_image'    => null,
            'gender'           => null,
            'birth_date'       => null,
            'bio'              => 'حساب أولي لمدير المنصة أُنشئ عبر السيدر.',
            'account_status'   => 'active',
            'email_verified'   => 1,
            'activity_status'  => 1,
            'is_banned'        => 0,
        ];

        $result = seed_upsert($db, 'users', $row, ['username']);
        $userId = (int) $result['id'];
        $inserted++;
    }

    // 3) إسناد الدور super_admin (بلا جمعية)
    $roleStmt = $db->prepare('SELECT role_id FROM roles WHERE role_code = ? LIMIT 1');
    $roleCode = 'super_admin';
    $roleStmt->bind_param('s', $roleCode);
    $roleStmt->execute();
    $role = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();

    if (!$role) {
        throw new RuntimeException("الدور 'super_admin' غير موجود؛ شغّل RolesSeeder أولًا.");
    }
    $roleId = (int) $role['role_id'];

    // UNIQUE(user_id, role_id, association_id) لا يمنع تكرار NULL في MySQL،
    // لذلك نتحقق يدويًا قبل الإدخال.
    $checkStmt = $db->prepare(
        'SELECT user_role_id FROM user_roles
         WHERE user_id = ? AND role_id = ? AND association_id IS NULL LIMIT 1'
    );
    $checkStmt->bind_param('ii', $userId, $roleId);
    $checkStmt->execute();
    $assigned = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if ($assigned) {
        $unchanged++;
    } else {
        $insertStmt = $db->prepare(
            'INSERT INTO user_roles (user_id, role_id, association_id, assigned_by, status)
             VALUES (?, ?, NULL, NULL, "active")'
        );
        $insertStmt->bind_param('ii', $userId, $roleId);
        $insertStmt->execute();
        $insertStmt->close();
        $inserted++;
    }

    return [$inserted, $updated, $unchanged];
}
