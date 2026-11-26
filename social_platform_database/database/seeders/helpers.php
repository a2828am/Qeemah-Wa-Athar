<?php
/**
 * Maknoon | قيمة وأثر
 * database/seeders/helpers.php
 *
 * دوال مساعدة للسيدرات — PHP خالص (MySQLi) بدون أي إطار عمل.
 * لا تنشئ/تعدّل أي جدول، ولا تحذف أي بيانات موجودة.
 */

declare(strict_types=1);

/** ألوان مخرجات الطرفية (CLI) */
function seed_color(string $text, string $color = 'reset'): string
{
    $map = [
        'reset'   => "\033[0m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'red'     => "\033[31m",
        'cyan'    => "\033[36m",
        'gray'    => "\033[90m",
        'bold'    => "\033[1m",
    ];

    return ($map[$color] ?? '') . $text . "\033[0m";
}

/** طباعة سطر */
function seed_line(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

/**
 * تحويل مجموعة صفوف إلى INSERT ... ON DUPLICATE KEY UPDATE
 * (بديل updateOrCreate) اعتمادًا على مفتاح فريد موجود فعلًا في المخطط.
 *
 * @param mysqli                $db          الاتصال
 * @param string                $table       اسم الجدول
 * @param array<string,mixed>   $row         العمود => القيمة
 * @param array<int,string>     $uniqueCols  أعمدة المفتاح الفريد لمنع التكرار
 *
 * @return array{action:string,id:int|null}  action: inserted|updated|unchanged
 */
function seed_upsert(mysqli $db, string $table, array $row, array $uniqueCols): array
{
    foreach ($uniqueCols as $col) {
        if (!array_key_exists($col, $row)) {
            throw new InvalidArgumentException("المفتاح الفريد '{$col}' غير موجود ضمن بيانات الجدول '{$table}'.");
        }
    }

    $columns      = array_keys($row);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $columnList   = '`' . implode('`, `', $columns) . '`';

    $updatable = array_values(array_diff($columns, $uniqueCols));
    if ($updatable) {
        $assignments = [];
        foreach ($updatable as $col) {
            $assignments[] = "`{$col}` = VALUES(`{$col}`)";
        }
        $onDuplicate = ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    } else {
        // لا توجد أعمدة قابلة للتحديث (جدول ربط) → لا شيء يُحدَّث عند التكرار
        $onDuplicate = ' ON DUPLICATE KEY UPDATE `' . $uniqueCols[0] . '` = `' . $uniqueCols[0] . '`';
    }

    $sql  = "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholders}){$onDuplicate}";
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException("فشل تجهيز الاستعلام على '{$table}': " . $db->error);
    }

    $types  = '';
    $values = [];
    foreach ($row as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } elseif ($value === null) {
            $types .= 's';
        } else {
            $types .= 's';
        }
        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException("فشل تنفيذ الإدخال في '{$table}': {$error}");
    }

    $affected = $stmt->affected_rows; // 1 = إضافة، 2 = تعديل، 0 = لا تغيير
    $stmt->close();

    $action = $affected === 1 ? 'inserted' : ($affected === 2 ? 'updated' : 'unchanged');

    return ['action' => $action, 'id' => seed_find_id($db, $table, $row, $uniqueCols)];
}

/**
 * جلب المعرّف اعتمادًا على أعمدة المفتاح الفريد.
 *
 * @param array<string,mixed> $row
 * @param array<int,string>   $uniqueCols
 */
function seed_find_id(mysqli $db, string $table, array $row, array $uniqueCols): ?int
{
    $conditions = [];
    foreach ($uniqueCols as $col) {
        $conditions[] = "`{$col}` = ?";
    }

    $sql  = "SELECT * FROM `{$table}` WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
    $stmt = $db->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException("فشل تجهيز استعلام البحث في '{$table}': " . $db->error);
    }

    $types  = '';
    $values = [];
    foreach ($uniqueCols as $col) {
        $value = $row[$col];
        $types .= is_int($value) ? 'i' : 's';
        $values[] = $value;
    }

    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $result = $stmt->get_result();
    $record = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($record === null) {
        return null;
    }

    // أول عمود ينتهي بـ _id يُعتبر المفتاح الأساسي
    foreach ($record as $key => $value) {
        if (substr($key, -3) === '_id') {
            return (int) $value;
        }
    }

    return null;
}

/**
 * تجميع إحصاءات كل سيدر بشكل موحد.
 *
 * @param array<string,int> $stats
 */
function seed_report(string $label, int $inserted, int $updated, int $unchanged): void
{
    seed_line(sprintf(
        '  %s %s — %s: %d، %s: %d، %s: %d',
        seed_color('✔', 'green'),
        $label,
        seed_color('مُضاف', 'cyan'),
        $inserted,
        seed_color('مُحدَّث', 'cyan'),
        $updated,
        seed_color('بدون تغيير', 'gray'),
        $unchanged
    ));
}

/**
 * تنفيذ سيدر داخل معاملة واحدة (كل الجداول InnoDB).
 *
 * @param callable $callback الدالة التي تنفذ الإدخال وتعيد [inserted, updated, unchanged]
 *
 * @return array{0:int,1:int,2:int}
 */
function seed_transaction(mysqli $db, callable $callback): array
{
    $db->begin_transaction();
    try {
        $stats = $callback($db);
        $db->commit();

        return $stats;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}
