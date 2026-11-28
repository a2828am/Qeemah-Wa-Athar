<?php
/**
 * includes/repository.php
 * -----------------------------------------------------------------------------
 * All database access for the platform, organised as static repositories.
 * Every write that spans more than one row runs inside a transaction through
 * Database::transaction(), which guarantees commit / rollBack.
 */
declare(strict_types=1);

use Qeemah\Config\Database;

abstract class Repo
{
    protected static function db(): PDO
    {
        return Database::pdo();
    }

    protected static function one(string $sql, array $params = []): ?array
    {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function q(string $sql, array $params = []): array
    {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function scalar(string $sql, array $params = [])
    {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    public static function exec(string $sql, array $params = []): int
    {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    protected static function lastId(): int
    {
        return (int)self::db()->lastInsertId();
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Users, roles & permissions
   ══════════════════════════════════════════════════════════════════════════ */

final class UserRepo extends Repo
{
    public static function findByEmail(string $email): ?array
    {
        return self::one(
            'SELECT u.*, t.code AS type_code, t.name_ar AS type_name
               FROM users u JOIN user_types t ON t.id = u.user_type_id
              WHERE u.email = ? LIMIT 1',
            [$email]
        );
    }

    public static function find(int $id): ?array
    {
        return self::one(
            'SELECT u.*, t.code AS type_code, t.name_ar AS type_name
               FROM users u JOIN user_types t ON t.id = u.user_type_id
              WHERE u.id = ? LIMIT 1',
            [$id]
        );
    }

    public static function create(array $d): int
    {
        self::exec(
            'INSERT INTO users (user_type_id, full_name, email, phone, password_hash, city, bio, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $d['user_type_id'], $d['full_name'], $d['email'], $d['phone'] ?? null,
                $d['password_hash'], $d['city'] ?? null, $d['bio'] ?? null,
                $d['status'] ?? 'active', now(), now(),
            ]
        );
        return self::lastId();
    }

    public static function updateProfile(int $id, array $d): void
    {
        self::exec(
            'UPDATE users SET full_name=?, phone=?, city=?, bio=?, updated_at=? WHERE id=?',
            [$d['full_name'], $d['phone'] ?? null, $d['city'] ?? null, $d['bio'] ?? null, now(), $id]
        );
    }

    public static function setPassword(int $id, string $hash): void
    {
        self::exec('UPDATE users SET password_hash=?, updated_at=? WHERE id=?', [$hash, now(), $id]);
    }

    public static function touchLogin(int $id): void
    {
        self::exec('UPDATE users SET last_login_at=?, login_count=login_count+1 WHERE id=?', [now(), $id]);
    }

    public static function setStatus(int $id, string $status): void
    {
        self::exec('UPDATE users SET status=?, updated_at=? WHERE id=?', [$status, now(), $id]);
    }

    public static function emailExists(string $email, ?int $exceptId = null): bool
    {
        if ($exceptId) {
            return (bool)self::scalar('SELECT 1 FROM users WHERE email=? AND id<>? LIMIT 1', [$email, $exceptId]);
        }
        return (bool)self::scalar('SELECT 1 FROM users WHERE email=? LIMIT 1', [$email]);
    }

    public static function userTypes(): array
    {
        return self::q('SELECT * FROM user_types WHERE is_active=1 ORDER BY id');
    }

    /* ── roles / permissions ─────────────────────────────────────────────── */

    public static function rolesOf(int $userId): array
    {
        return array_column(
            self::q(
                'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
                [$userId]
            ),
            'code'
        );
    }

    public static function permissionsOf(int $userId): array
    {
        return array_column(
            self::q(
                'SELECT DISTINCT p.code
                   FROM user_roles ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE ur.user_id = ?',
                [$userId]
            ),
            'code'
        );
    }

    public static function grantRole(int $userId, string $roleCode, ?int $by = null): void
    {
        $roleId = (int)self::scalar('SELECT id FROM roles WHERE code = ? LIMIT 1', [$roleCode]);
        if ($roleId > 0) {
            self::exec(
                'INSERT INTO user_roles (user_id, role_id, granted_by, granted_at)
                 SELECT ?, ?, ?, ?
                  WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?)',
                [$userId, $roleId, $by, now(), $userId, $roleId]
            );
        }
    }

    public static function revokeRole(int $userId, string $roleCode): void
    {
        self::exec(
            'DELETE FROM user_roles WHERE user_id=? AND role_id=(SELECT id FROM roles WHERE code=? LIMIT 1)',
            [$userId, $roleCode]
        );
    }

    /* ── listings ────────────────────────────────────────────────────────── */

    public static function paginate(array $f, int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($f['q'])) {
            $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        if (!empty($f['status'])) {
            $where[]  = 'u.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['type_id'])) {
            $where[]  = 'u.user_type_id = ?';
            $params[] = (int)$f['type_id'];
        }

        $sqlWhere = implode(' AND ', $where);
        $total    = (int)self::scalar("SELECT COUNT(*) FROM users u WHERE $sqlWhere", $params);
        $p        = paginate($total, $perPage, $page);

        $rows = self::q(
            "SELECT u.*, t.name_ar AS type_name, t.code AS type_code
               FROM users u JOIN user_types t ON t.id = u.user_type_id
              WHERE $sqlWhere
              ORDER BY u.created_at DESC, u.id DESC
              LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );
        return [$rows, $p];
    }

    public static function stats(): array
    {
        return [
            'total'     => (int)self::scalar('SELECT COUNT(*) FROM users'),
            'active'    => (int)self::scalar("SELECT COUNT(*) FROM users WHERE status='active'"),
            'suspended' => (int)self::scalar("SELECT COUNT(*) FROM users WHERE status='suspended'"),
            'new_week'  => (int)self::scalar('SELECT COUNT(*) FROM users WHERE created_at >= ?', [date('Y-m-d H:i:s', strtotime('-7 days'))]),
        ];
    }

    public static function recalculateRating(int $userId): void
    {
        $row = self::one('SELECT AVG(score) AS avg_score, COUNT(*) AS c FROM ratings WHERE ratee_id = ?', [$userId]);
        self::exec(
            'UPDATE users SET rating_avg=?, rating_count=? WHERE id=?',
            [round((float)($row['avg_score'] ?? 0), 2), (int)($row['c'] ?? 0), $userId]
        );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Sessions, activity, audit
   ══════════════════════════════════════════════════════════════════════════ */

final class SessionRepo extends Repo
{
    public static function create(string $id, int $userId, string $csrf, ?string $ip, string $ua): void
    {
        self::exec(
            'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, csrf_token, last_activity, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$id, $userId, $ip === null ? null : inet_pton($ip), mb_substr($ua, 0, 250), $csrf, now(), now()]
        );
    }

    public static function touch(string $id): void
    {
        self::exec('UPDATE user_sessions SET last_activity=? WHERE id=?', [now(), $id]);
    }

    public static function revoke(string $id): void
    {
        self::exec('UPDATE user_sessions SET revoked_at=? WHERE id=?', [now(), $id]);
    }

    public static function logEvent(?int $userId, string $event, ?string $sessionId = null, ?string $detail = null, ?string $ip = null, string $ua = ''): void
    {
        self::exec(
            'INSERT INTO login_logout_logs (user_id, event, session_id, ip_address, user_agent, detail, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$userId, $event, $sessionId, $ip === null ? null : inet_pton($ip), mb_substr($ua, 0, 250), $detail, now()]
        );
    }
}

final class ActivityRepo extends Repo
{
    public static function log(int $userId, string $action, ?string $entityType = null, ?int $entityId = null, array $meta = []): void
    {
        self::exec(
            'INSERT INTO user_activities (user_id, action, entity_type, entity_id, meta, ip_address, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [
                $userId, $action, $entityType, $entityId,
                $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                ($ip = client_ip()) === null ? null : inet_pton($ip),
                now(),
            ]
        );
    }
}

final class AuditRepo extends Repo
{
    /** Immutable — no update/delete path exists anywhere in the codebase. */
    public static function log(?int $adminId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $before = null, ?array $after = null): void
    {
        self::exec(
            'INSERT INTO admin_audit_logs (admin_id, action, entity_type, entity_id, before_json, after_json, ip_address, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $adminId, $action, $entityType, $entityId,
                $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
                $after  === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
                ($ip = client_ip()) === null ? null : inet_pton($ip),
                now(),
            ]
        );
    }

    public static function paginate(array $f, int $page = 1, int $perPage = 30): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($f['action'])) {
            $where[]  = 'a.action LIKE ?';
            $params[] = $f['action'] . '%';
        }
        if (!empty($f['admin_id'])) {
            $where[]  = 'a.admin_id = ?';
            $params[] = (int)$f['admin_id'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total    = (int)self::scalar("SELECT COUNT(*) FROM admin_audit_logs a WHERE $sqlWhere", $params);
        $p        = paginate($total, $perPage, $page);

        $rows = self::q(
            "SELECT a.*, u.full_name AS admin_name
               FROM admin_audit_logs a LEFT JOIN users u ON u.id = a.admin_id
              WHERE $sqlWhere
              ORDER BY a.id DESC
              LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );
        return [$rows, $p];
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Categories & products
   ══════════════════════════════════════════════════════════════════════════ */

final class CategoryRepo extends Repo
{
    public static function all(): array
    {
        return self::q('SELECT * FROM categories WHERE is_active=1 ORDER BY sort_order, id');
    }

    public static function withCounts(): array
    {
        return self::q(
            "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id AND p.status='available') AS products_count
               FROM categories c WHERE c.is_active=1 ORDER BY c.sort_order, c.id"
        );
    }

    public static function find(int $id): ?array
    {
        return self::one('SELECT * FROM categories WHERE id=?', [$id]);
    }

    public static function findOrFail(int $id): array
    {
        $c = self::find($id);
        if (!$c) {
            throw new RuntimeException('التصنيف غير موجود.');
        }
        return $c;
    }
}

final class ProductRepo extends Repo
{
    private const BASE_SELECT = "SELECT p.*, c.name_ar AS category_name, c.icon AS category_icon,
                                        u.full_name AS owner_name, u.rating_avg AS owner_rating,
                                        u.id AS owner_user_id, t.code AS owner_type,
                                        a.name AS association_name,
                                        (SELECT file_path FROM product_images pi WHERE pi.product_id=p.id
                                          ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS primary_image
                                   FROM products p
                                   JOIN categories c ON c.id = p.category_id
                                   JOIN users u ON u.id = p.owner_id
                                   JOIN user_types t ON t.id = u.user_type_id
                                   LEFT JOIN associations a ON a.id = p.association_id";

    /* ── read ────────────────────────────────────────────────────────────── */

    public static function find(int $id): ?array
    {
        return self::one(self::BASE_SELECT . ' WHERE p.id = ? LIMIT 1', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return self::one(self::BASE_SELECT . ' WHERE p.slug = ? LIMIT 1', [$slug]);
    }

    public static function images(int $productId): array
    {
        return self::q(
            'SELECT * FROM product_images WHERE product_id=? ORDER BY is_primary DESC, sort_order, id',
            [$productId]
        );
    }

    public static function primaryImage(int $productId): ?string
    {
        $v = self::scalar(
            'SELECT file_path FROM product_images WHERE product_id=? ORDER BY is_primary DESC, sort_order LIMIT 1',
            [$productId]
        );
        return $v ?: null;
    }

    /** Marketplace query used by the home page and the admin list. */
    public static function search(array $f = [], int $page = 1, int $perPage = 12): array
    {
        $where  = ['p.status <> ?'];
        $params = ['removed'];

        if (!empty($f['status'])) {
            $where[0] = 'p.status = ?';
            $params   = [$f['status']];
        }
        if (!empty($f['q'])) {
            $where[]  = '(p.title LIKE ? OR p.description LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        if (!empty($f['category_id'])) {
            $where[]  = 'p.category_id = ?';
            $params[] = (int)$f['category_id'];
        }
        if (!empty($f['city'])) {
            $where[]  = 'p.city = ?';
            $params[] = $f['city'];
        }
        if (!empty($f['transaction_type'])) {
            $where[]  = 'p.transaction_type = ?';
            $params[] = $f['transaction_type'];
        }
        if (!empty($f['owner_id'])) {
            $where[]  = 'p.owner_id = ?';
            $params[] = (int)$f['owner_id'];
        }
        if (!empty($f['association_id'])) {
            $where[]  = 'p.association_id = ?';
            $params[] = (int)$f['association_id'];
        }
        if (!empty($f['exclude_id'])) {
            $where[]  = 'p.id <> ?';
            $params[] = (int)$f['exclude_id'];
        }
        if (!empty($f['ids']) && is_array($f['ids'])) {
            $in      = implode(',', array_fill(0, count($f['ids']), '?'));
            $where[] = "p.id IN ($in)";
            foreach ($f['ids'] as $id) {
                $params[] = (int)$id;
            }
        }
        if (!empty($f['donation_scope'])) {
            $where[]  = 'p.donation_scope = ?';
            $params[] = $f['donation_scope'];
        }
        if (!empty($f['target_association_id'])) {
            $where[]  = '(p.target_association_id = ? OR p.donation_scope = ?)';
            $params[] = (int)$f['target_association_id'];
            $params[] = 'general';
        }

        $sqlWhere = implode(' AND ', $where);

        $order = match ($f['sort'] ?? '') {
            'cheapest' => 'p.price IS NULL, p.price ASC',
            'priciest' => 'p.price IS NULL, p.price DESC',
            'popular'  => 'p.views_count DESC, p.favorites_count DESC',
            default    => 'p.created_at DESC',
        };

        $total = (int)self::scalar("SELECT COUNT(*) FROM products p WHERE $sqlWhere", $params);
        $p     = paginate($total, $perPage, $page);

        $rows = self::q(
            self::BASE_SELECT . " WHERE $sqlWhere ORDER BY $order, p.id DESC
             LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );
        return [$rows, $p];
    }

    /* ── write ───────────────────────────────────────────────────────────── */

    /**
     * Creates a product (+ images + donation record when needed) atomically.
     * @return int new product id
     */
    public static function create(array $d, array $imagePaths = []): int
    {
        return Database::transaction(function () use ($d, $imagePaths) {
            $slug = slugify($d['title'], (string)random_int(100, 999));
            while (self::scalar('SELECT 1 FROM products WHERE slug=?', [$slug])) {
                $slug = slugify($d['title'], (string)random_int(1000, 99999));
            }

            self::exec(
                'INSERT INTO products
                   (owner_id, category_id, association_id, title, slug, description, transaction_type,
                    condition_type, status, price, currency, swap_wanted, city, address_hint,
                    donation_scope, target_association_id, published_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $d['owner_id'], $d['category_id'], $d['association_id'] ?? null,
                    $d['title'], $slug, $d['description'], $d['transaction_type'],
                    $d['condition_type'], $d['status'] ?? 'available',
                    $d['transaction_type'] === 'sell' ? $d['price'] : null,
                    'YER', $d['swap_wanted'] ?? null, $d['city'], $d['address_hint'] ?? null,
                    $d['transaction_type'] === 'donate' ? ($d['donation_scope'] ?? 'general') : null,
                    // 0 means "no association": MySQL foreign keys reject 0, so normalise early.
                    $d['transaction_type'] === 'donate' && !empty($d['target_association_id'])
                        ? (int)$d['target_association_id'] : null,
                    ($d['status'] ?? 'available') === 'available' ? now() : null,
                    now(), now(),
                ]
            );
            $id = self::lastId();

            foreach (array_values($imagePaths) as $i => $path) {
                self::exec(
                    'INSERT INTO product_images (product_id, file_path, alt_text, sort_order, is_primary, created_at)
                     VALUES (?,?,?,?,?,?)',
                    [$id, $path, $d['title'], $i, $i === 0 ? 1 : 0, now()]
                );
            }

            if ($d['transaction_type'] === 'donate') {
                self::exec(
                    'INSERT INTO donations (product_id, donor_id, association_id, donation_scope, kind, title,
                                            quantity, status, note, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [
                        $id, $d['owner_id'], $d['target_association_id'] ?? null,
                        $d['donation_scope'] ?? 'general', 'in_kind', $d['title'],
                        null, 'offered', isset($d['description']) ? excerpt($d['description'], 200) : null,
                        now(), now(),
                    ]
                );
            }

            ActivityRepo::log((int)$d['owner_id'], 'product.created', 'product', $id, ['type' => $d['transaction_type']]);
            return $id;
        });
    }

    public static function update(int $id, array $d): void
    {
        self::exec(
            'UPDATE products
                SET title=?, description=?, category_id=?, condition_type=?, price=?, swap_wanted=?,
                    city=?, address_hint=?, donation_scope=?, target_association_id=?, updated_at=?
              WHERE id=?',
            [
                $d['title'], $d['description'], $d['category_id'], $d['condition_type'],
                $d['transaction_type'] === 'sell' ? $d['price'] : null,
                $d['swap_wanted'] ?? null, $d['city'], $d['address_hint'] ?? null,
                $d['transaction_type'] === 'donate' ? ($d['donation_scope'] ?? 'general') : null,
                $d['transaction_type'] === 'donate' ? ($d['target_association_id'] ?? null) : null,
                now(), $id,
            ]
        );
    }

    public static function addImage(int $productId, string $path): void
    {
        $has = (int)self::scalar('SELECT COUNT(*) FROM product_images WHERE product_id=?', [$productId]);
        self::exec(
            'INSERT INTO product_images (product_id, file_path, sort_order, is_primary, created_at) VALUES (?,?,?,?,?)',
            [$productId, $path, $has, $has === 0 ? 1 : 0, now()]
        );
    }

    public static function deleteImage(int $imageId, int $productId): ?string
    {
        $row = self::one('SELECT * FROM product_images WHERE id=? AND product_id=?', [$imageId, $productId]);
        if (!$row) {
            return null;
        }
        self::exec('DELETE FROM product_images WHERE id=?', [$imageId]);
        return $row['file_path'];
    }

    public static function setStatus(int $id, string $status): void
    {
        $extra = $status === 'completed' ? ', completed_at=?' : '';
        $params = $status === 'completed' ? [now()] : [];
        self::exec(
            "UPDATE products SET status=?, updated_at=? $extra WHERE id=?",
            array_merge([$status, now()], $params, [$id])
        );
    }

    public static function incrementViews(int $id): void
    {
        self::exec('UPDATE products SET views_count = views_count + 1 WHERE id=?', [$id]);
    }

    public static function refreshFavoritesCount(int $id): void
    {
        self::exec(
            'UPDATE products SET favorites_count = (SELECT COUNT(*) FROM favorite_products WHERE product_id=?) WHERE id=?',
            [$id, $id]
        );
    }

    public static function incrementRequests(int $id): void
    {
        self::exec('UPDATE products SET requests_count = requests_count + 1 WHERE id=?', [$id]);
    }

    public static function delete(int $id): void
    {
        self::exec('DELETE FROM products WHERE id=?', [$id]);
    }

    /* ── stats for AI pricing ───────────────────────────────────────────── */

    public static function priceStats(int $categoryId, string $condition, int $minSamples = 3): array
    {
        $rows = self::q(
            "SELECT price FROM products
              WHERE category_id=? AND transaction_type='sell' AND price IS NOT NULL
                AND status IN ('available','completed','reserved')",
            [$categoryId]
        );
        if (count($rows) < $minSamples) {
            $rows = self::q(
                "SELECT price FROM products WHERE transaction_type='sell' AND price IS NOT NULL
                   AND status IN ('available','completed','reserved')"
            );
        }
        $prices = array_map(static fn ($r) => (float)$r['price'], $rows);
        if (!$prices) {
            return ['count' => 0, 'min' => null, 'max' => null, 'avg' => null, 'median' => null];
        }
        sort($prices);
        $n   = count($prices);
        $mid = intdiv($n, 2);
        $factor = ['new' => 1.10, 'like_new' => 1.00, 'good' => 0.88, 'fair' => 0.74, 'used' => 0.64][$condition] ?? 0.88;

        return [
            'count'  => $n,
            'min'    => (float)$prices[0],
            'max'    => (float)$prices[$n - 1],
            'avg'    => round(array_sum($prices) / $n, 2),
            'median' => $n % 2 ? (float)$prices[$mid] : (float)(($prices[$mid - 1] + $prices[$mid]) / 2),
            'suggested_low'  => (int)round($prices[0] * $factor),
            'suggested_high' => (int)round($prices[$n - 1] * $factor),
        ];
    }

    public static function countsByCategory(): array
    {
        return self::q(
            "SELECT c.id, c.name_ar, COUNT(p.id) AS n
               FROM categories c LEFT JOIN products p ON p.category_id=c.id AND p.status='available'
              GROUP BY c.id, c.name_ar ORDER BY n DESC"
        );
    }

    public static function countsByType(): array
    {
        return self::q(
            "SELECT transaction_type, COUNT(*) AS n FROM products
              WHERE status='available' GROUP BY transaction_type"
        );
    }
}

final class FavoriteRepo extends Repo
{
    public static function toggle(int $userId, int $productId): array
    {
        return Database::transaction(function () use ($userId, $productId) {
            $exists = (bool)self::scalar(
                'SELECT 1 FROM favorite_products WHERE user_id=? AND product_id=?',
                [$userId, $productId]
            );
            if ($exists) {
                self::exec('DELETE FROM favorite_products WHERE user_id=? AND product_id=?', [$userId, $productId]);
                $on = false;
            } else {
                self::exec(
                    'INSERT INTO favorite_products (user_id, product_id, created_at) VALUES (?,?,?)',
                    [$userId, $productId, now()]
                );
                $on = true;
                ActivityRepo::log($userId, 'favorite.added', 'product', $productId);
            }
            ProductRepo::refreshFavoritesCount($productId);
            $count = (int)self::scalar('SELECT favorites_count FROM products WHERE id=?', [$productId]);
            return ['on' => $on, 'count' => $count];
        });
    }

    public static function idsFor(int $userId): array
    {
        return array_map('intval', array_column(
            self::q('SELECT product_id FROM favorite_products WHERE user_id=?', [$userId]),
            'product_id'
        ));
    }

    public static function listFor(int $userId): array
    {
        return self::q(
            "SELECT p.*, c.name_ar AS category_name,
                    u.full_name AS owner_name,
                    (SELECT file_path FROM product_images pi WHERE pi.product_id=p.id
                      ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS primary_image
               FROM favorite_products f
               JOIN products p ON p.id = f.product_id
               JOIN categories c ON c.id = p.category_id
               JOIN users u ON u.id = p.owner_id
              WHERE f.user_id = ?
              ORDER BY f.created_at DESC",
            [$userId]
        );
    }

    public static function countFor(int $userId): int
    {
        return (int)self::scalar('SELECT COUNT(*) FROM favorite_products WHERE user_id=?', [$userId]);
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Requests — the core state machine
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Read-side queries for requests. All state changes live in RequestFlowRepo
 * (includes/request_flow.php) so the lifecycle has exactly one owner.
 */
final class RequestRepo extends Repo
{
    private const BASE = "SELECT r.*, p.title AS product_title, p.slug AS product_slug, p.price AS product_price,
                                 p.transaction_type, p.status AS product_status, p.city AS product_city,
                                 p.owner_id AS product_owner_id, p.association_id AS product_association_id,
                                 p.donation_scope AS product_donation_scope,
                                 COALESCE(r.association_id, p.target_association_id,
                                          CASE WHEN p.donation_scope = 'targeted' THEN p.association_id END) AS association_id,
                                 req.full_name AS requester_name, req.rating_avg AS requester_rating,
                                 req.id AS requester_id, req.phone AS requester_phone,
                                 own.full_name AS owner_name, own.id AS owner_id,
                                 op.title AS offered_title, op.slug AS offered_slug,
                                 (SELECT file_path FROM product_images pi WHERE pi.product_id=p.id
                                   ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS primary_image,
                                 (SELECT body FROM messages m WHERE m.conversation_id=r.conversation_id
                                   ORDER BY m.id DESC LIMIT 1) AS last_message,
                                 (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=r.conversation_id
                                   AND m.sender_id<>? AND m.read_at IS NULL) AS unread_messages
                            FROM user_requests r
                            JOIN products p ON p.id = r.product_id
                            JOIN users req ON req.id = r.requester_id
                            JOIN users own ON own.id = r.owner_id
                            LEFT JOIN products op ON op.id = r.offered_product_id";

    private const OPEN_STATES = ['awaiting_owner', 'accepted', 'negotiating', 'agreed', 'delivery_pending'];

    public static function find(int $id, int $viewerId = 0): ?array
    {
        return self::one(self::BASE . ' WHERE r.id = ? LIMIT 1', [$viewerId, $id]);
    }

    /** Everything a single request screen needs, in one call. */
    public static function detail(int $id, int $viewerId, bool $isAdmin = false): ?array
    {
        $row = self::find($id, $viewerId);
        if (!$row) {
            return null;
        }
        if (!RequestFlowRepo::canView($row, $viewerId, $isAdmin)) {
            return null; // treated as 404 — never leak the existence of other people's deals
        }
        $row['history']   = RequestFlowRepo::historyFor($id);
        $row['agreements'] = RequestFlowRepo::agreementsFor($id);
        $row['deliveries'] = RequestFlowRepo::deliveriesFor($id);
        $row['parties']   = RequestFlowRepo::expectedParties($row);
        $row['roles']     = RequestFlowRepo::rolesFor($row, $viewerId, $isAdmin);
        $row['donation']  = $row['request_type'] === 'donate'
            ? self::one('SELECT * FROM donations WHERE request_id=? LIMIT 1', [$id])
            : null;
        return $row;
    }

    private static function scopeFor(string $role, int $userId): array
    {
        return $role === 'owner'
            ? ['r.owner_id = ?', $userId]
            : ['r.requester_id = ?', $userId];
    }

    /** Deals currently in motion for one side of the marketplace. */
    public static function activeForUser(int $userId, string $role = 'owner', int $limit = 6): array
    {
        [$where, $param] = self::scopeFor($role === 'requester' ? 'requester' : 'owner', $userId);
        return self::q(
            self::BASE . " WHERE {$where} AND r.status IN ('awaiting_owner','accepted','negotiating','agreed','delivery_pending','disputed')
                            ORDER BY CASE r.status WHEN 'awaiting_owner' THEN 0 WHEN 'delivery_pending' THEN 1 ELSE 2 END, r.updated_at DESC
                            LIMIT {$limit}",
            [$userId, $param]
        );
    }

    public static function forOwner(int $ownerId, ?string $status = null, int $page = 1, int $perPage = 10): array
    {
        return self::paginateFor('owner', $ownerId, $status, $page, $perPage);
    }

    public static function forRequester(int $requesterId, ?string $status = null, int $page = 1, int $perPage = 10): array
    {
        return self::paginateFor('requester', $requesterId, $status, $page, $perPage);
    }

    public static function paginateFor(string $role, int $userId, ?string $status, int $page, int $perPage): array
    {
        [$where, $param] = self::scopeFor($role, $userId);
        $params = [$userId, $param];
        if ($status !== null && $status !== '' && isset(request_status_meta()[$status])) {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        $total = (int)self::scalar('SELECT COUNT(*) FROM user_requests r WHERE ' . $where, array_slice($params, 1));
        $p = paginate($total, $perPage, $page);
        $rows = self::q(
            self::BASE . ' WHERE ' . $where . ' ORDER BY r.updated_at DESC, r.id DESC LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'],
            $params
        );
        return [$rows, $p];
    }

    /** Counts per lifecycle state for the tab badges. */
    public static function statusCounts(int $userId, string $role = 'owner'): array
    {
        [$where, $param] = self::scopeFor($role, $userId);
        $rows = self::q(
            'SELECT r.status, COUNT(*) AS n FROM user_requests r WHERE ' . $where . ' GROUP BY r.status',
            [$param]
        );
        $out = array_fill_keys(array_keys(request_status_meta()), 0);
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['n'];
        }
        return $out;
    }

    /** Requests attached to one product (owner's product page). */
    public static function forProduct(int $productId): array
    {
        return self::q(self::BASE . ' WHERE r.product_id=? ORDER BY r.id DESC', [0, $productId]);
    }

    /** Live deal for a product, used for the progress bar on the product page. */
    public static function activeForProduct(int $productId, int $viewerId): ?array
    {
        return self::one(
            self::BASE . " WHERE r.product_id=? AND (r.requester_id=? OR r.owner_id=?) ORDER BY r.id DESC LIMIT 1",
            [$viewerId, $productId, $viewerId, $viewerId]
        );
    }

    public static function openCount(int $productId): int
    {
        return (int)self::scalar(
            "SELECT COUNT(*) FROM user_requests WHERE product_id=? AND status IN ('accepted','negotiating','agreed','delivery_pending')",
            [$productId]
        );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Associations, campaigns, beneficiaries, donations
   ══════════════════════════════════════════════════════════════════════════ */

final class AssociationRepo extends Repo
{
    public static function find(int $id): ?array
    {
        return self::one('SELECT * FROM associations WHERE id=?', [$id]);
    }

    public static function findBySlug(string $slug): ?array
    {
        return self::one('SELECT * FROM associations WHERE slug=?', [$slug]);
    }

    public static function forUser(int $userId): ?array
    {
        return self::one(
            'SELECT a.*, m.role AS member_role, m.can_campaigns, m.can_donations,
                    m.can_beneficiaries, m.can_members
               FROM association_members m
               JOIN associations a ON a.id = m.association_id
              WHERE m.user_id=? AND m.status=?
              ORDER BY CASE m.role WHEN "representative" THEN 0 WHEN "manager" THEN 1 WHEN "coordinator" THEN 2 ELSE 3 END, m.id
              LIMIT 1',
            [$userId, 'active']
        );
    }

    /** Every verified association this user may act for (member or owner). */
    public static function actingFor(int $userId): array
    {
        return self::q(
            "SELECT a.id, a.name, a.slug, a.status,
                    COALESCE(m.role, 'representative') AS member_role,
                    m.can_donations
               FROM associations a
               LEFT JOIN association_members m
                      ON m.association_id = a.id AND m.user_id = ? AND m.status = 'active'
              WHERE a.status = 'verified'
                AND (m.user_id IS NOT NULL OR a.owner_user_id = ?)
              ORDER BY a.name",
            [$userId, $userId]
        );
    }

    public static function primaryForUser(int $userId): ?array
    {
        $a = self::forUser($userId);
        if ($a) {
            return $a;
        }
        // Owner without a member row still counts as representative.
        return self::one('SELECT * FROM associations WHERE owner_user_id=? LIMIT 1', [$userId]);
    }

    public static function verified(): array
    {
        return self::q(
            "SELECT a.*, (SELECT COUNT(*) FROM donation_campaigns c WHERE c.association_id=a.id AND c.status='active') AS active_campaigns
               FROM associations a WHERE a.status='verified' ORDER BY a.rating_avg DESC, a.id"
        );
    }

    public static function allForAdmin(array $f = [], int $page = 1, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[]  = 'a.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['q'])) {
            $where[]  = 'a.name LIKE ?';
            $params[] = '%' . $f['q'] . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total    = (int)self::scalar("SELECT COUNT(*) FROM associations a WHERE $sqlWhere", $params);
        $p        = paginate($total, $perPage, $page);
        $rows     = self::q(
            "SELECT a.*, u.full_name AS owner_name,
                    (SELECT COUNT(*) FROM association_members m WHERE m.association_id=a.id AND m.status='active') AS members_count,
                    (SELECT COUNT(*) FROM donation_campaigns c WHERE c.association_id=a.id) AS campaigns_count
               FROM associations a JOIN users u ON u.id=a.owner_user_id
              WHERE $sqlWhere ORDER BY a.created_at DESC
              LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );
        return [$rows, $p];
    }

    public static function create(int $ownerId, array $d): int
    {
        self::exec(
            'INSERT INTO associations (owner_user_id, name, slug, license_number, description, city, phone, email,
                                      website, needs_summary, status, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $ownerId, $d['name'], slugify($d['name'], (string)random_int(100, 999)),
                $d['license_number'] ?? null, $d['description'] ?? null, $d['city'],
                $d['phone'] ?? null, $d['email'] ?? null, $d['website'] ?? null,
                $d['needs_summary'] ?? null, 'pending', now(), now(),
            ]
        );
        $id = self::lastId();
        self::exec(
            'INSERT INTO association_members (association_id, user_id, role, can_campaigns, can_donations,
                                              can_beneficiaries, can_members, status, joined_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$id, $ownerId, 'representative', 1, 1, 1, 1, 'active', now(), now()]
        );
        UserRepo::grantRole($ownerId, 'association_representative', $ownerId);
        return $id;
    }

    public static function setStatus(int $id, string $status, ?int $by = null): void
    {
        $verifiedAt = $status === 'verified' ? now() : null;
        $byId       = $status === 'verified' ? $by : null;
        self::exec(
            'UPDATE associations SET status=?, verified_at=?, verified_by=?, updated_at=? WHERE id=?',
            [$status, $verifiedAt, $byId, now(), $id]
        );
    }

    public static function memberRow(int $assocId, int $userId): ?array
    {
        return self::one(
            'SELECT * FROM association_members WHERE association_id=? AND user_id=? AND status=?',
            [$assocId, $userId, 'active']
        );
    }

    public static function members(int $assocId): array
    {
        return self::q(
            'SELECT m.*, u.full_name, u.email, u.phone, u.rating_avg
               FROM association_members m JOIN users u ON u.id=m.user_id
              WHERE m.association_id=?
              ORDER BY CASE m.role WHEN "representative" THEN 0 WHEN "manager" THEN 1 WHEN "coordinator" THEN 2 ELSE 3 END, m.id',
            [$assocId]
        );
    }

    public static function addMember(int $assocId, int $userId, string $role, array $perms, ?int $by = null): void
    {
        if (self::memberRow($assocId, $userId)) {
            throw new RuntimeException('هذا المستخدم عضو بالفعل في الجمعية.');
        }
        self::exec(
            'INSERT INTO association_members (association_id, user_id, role, can_campaigns, can_donations,
                                              can_beneficiaries, can_members, status, invited_by, joined_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $assocId, $userId, $role,
                !empty($perms['campaigns']) ? 1 : 0, !empty($perms['donations']) ? 1 : 0,
                !empty($perms['beneficiaries']) ? 1 : 0, !empty($perms['members']) ? 1 : 0,
                'active', $by, now(), now(),
            ]
        );
        $roleCode = match ($role) {
            'representative' => 'association_representative',
            'manager'        => 'association_manager',
            'coordinator'    => 'association_coordinator',
            default          => 'association_viewer',
        };
        UserRepo::grantRole($userId, $roleCode, $by);
    }

    public static function updateMemberPerms(int $memberId, int $assocId, array $perms, string $role): void
    {
        self::exec(
            'UPDATE association_members
                SET role=?, can_campaigns=?, can_donations=?, can_beneficiaries=?, can_members=?
              WHERE id=? AND association_id=?',
            [
                $role,
                !empty($perms['campaigns']) ? 1 : 0, !empty($perms['donations']) ? 1 : 0,
                !empty($perms['beneficiaries']) ? 1 : 0, !empty($perms['members']) ? 1 : 0,
                $memberId, $assocId,
            ]
        );
    }

    public static function removeMember(int $memberId, int $assocId): void
    {
        self::exec(
            'UPDATE association_members SET status=? WHERE id=? AND association_id=? AND role<>?',
            ['removed', $memberId, $assocId, 'representative']
        );
    }

    /* ── join requests ────────────────────────────────────────────────────
       An individual asks to be recognised as a member/representative of an
       association. Nothing is granted until the association's representative
       approves — a pending request must never open any door. */

    /** Verified associations an individual can ask to join. */
    public static function verifiedList(): array
    {
        return self::q(
            "SELECT id, name, slug, city, description, needs_summary
               FROM associations WHERE status='verified' ORDER BY name"
        );
    }

    /** Raise a join request, or throw a message the form can show as-is. */
    public static function requestJoin(int $assocId, int $userId, string $role, string $message = ''): int
    {
        $assoc = self::find($assocId);
        if (!$assoc || (string)$assoc['status'] !== 'verified') {
            throw new RuntimeException('الجمعية غير متاحة للانضمام.');
        }
        if (self::memberRow($assocId, $userId)) {
            throw new RuntimeException('أنت عضو في هذه الجمعية بالفعل.');
        }
        if (!in_array($role, ['manager', 'coordinator', 'viewer'], true)) {
            $role = 'coordinator';
        }
        $open = (int)self::scalar(
            "SELECT COUNT(*) FROM association_join_requests WHERE association_id=? AND user_id=? AND status='pending'",
            [$assocId, $userId]
        );
        if ($open > 0) {
            throw new RuntimeException('لديك طلب قيد المراجعة لهذه الجمعية.');
        }
        self::exec(
            'INSERT INTO association_join_requests (association_id, user_id, message, requested_role, status, created_at)
             VALUES (?,?,?,?,?,?)',
            [$assocId, $userId, $message !== '' ? mb_substr($message, 0, 500) : null, $role, 'pending', now()]
        );
        return self::lastId();
    }

    /** Pending requests for a representative to act on. */
    public static function pendingJoins(int $assocId): array
    {
        return self::q(
            "SELECT j.*, u.full_name, u.email, u.city, u.created_at AS user_since,
                    (SELECT COUNT(*) FROM products p WHERE p.owner_id=j.user_id AND p.status<>'deleted') AS products_count
               FROM association_join_requests j
               JOIN users u ON u.id = j.user_id
              WHERE j.association_id=? AND j.status='pending'
              ORDER BY j.id DESC",
            [$assocId]
        );
    }

    /** The viewer's own requests, newest first. */
    public static function myJoins(int $userId): array
    {
        return self::q(
            'SELECT j.*, a.name AS assoc_name, a.slug AS assoc_slug, a.city AS assoc_city
               FROM association_join_requests j
               JOIN associations a ON a.id = j.association_id
              WHERE j.user_id=? ORDER BY j.id DESC',
            [$userId]
        );
    }

    /** Approve: becomes a member with the asked-for role, in one transaction. */
    public static function approveJoin(int $joinId, int $assocId, int $by): array
    {
        $row = self::one(
            "SELECT * FROM association_join_requests WHERE id=? AND association_id=? AND status='pending'",
            [$joinId, $assocId]
        );
        if (!$row) {
            throw new RuntimeException('الطلب غير موجود أو تمت معالجته.');
        }
        if (self::memberRow($assocId, (int)$row['user_id'])) {
            throw new RuntimeException('هذا المستخدم عضو بالفعل.');
        }
        self::exec(
            "UPDATE association_join_requests SET status='approved', resolved_by=?, resolved_at=? WHERE id=?",
            [$by, now(), $joinId]
        );
        self::addMember(
            $assocId,
            (int)$row['user_id'],
            (string)$row['requested_role'],
            [(string)$row['requested_role'] === 'viewer' ? 'none' : 'donations' => true],
            $by
        );
        return $row;
    }

    public static function rejectJoin(int $joinId, int $assocId, int $by, string $note = ''): array
    {
        $row = self::one(
            "SELECT * FROM association_join_requests WHERE id=? AND association_id=? AND status='pending'",
            [$joinId, $assocId]
        );
        if (!$row) {
            throw new RuntimeException('الطلب غير موجود أو تمت معالجته.');
        }
        self::exec(
            "UPDATE association_join_requests SET status='rejected', resolved_by=?, resolved_at=?, decision_note=? WHERE id=?",
            [$by, now(), $note !== '' ? mb_substr($note, 0, 255) : null, $joinId]
        );
        return $row;
    }

    public static function cancelJoin(int $joinId, int $userId): void
    {
        self::exec(
            "UPDATE association_join_requests SET status='cancelled', resolved_at=? WHERE id=? AND user_id=? AND status='pending'",
            [now(), $joinId, $userId]
        );
    }

    public static function stats(int $assocId): array
    {
        return [
            'members'      => (int)self::scalar("SELECT COUNT(*) FROM association_members WHERE association_id=? AND status='active'", [$assocId]),
            'campaigns'    => (int)self::scalar('SELECT COUNT(*) FROM donation_campaigns WHERE association_id=?', [$assocId]),
            'active_campaigns' => (int)self::scalar("SELECT COUNT(*) FROM donation_campaigns WHERE association_id=? AND status='active'", [$assocId]),
            'donations'    => (int)self::scalar('SELECT COUNT(*) FROM donations WHERE association_id=?', [$assocId]),
            'received'     => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE association_id=? AND status IN ('received','distributed')", [$assocId]),
            'distributed'  => (int)self::scalar('SELECT COUNT(*) FROM distribution_logs WHERE association_id=?', [$assocId]),
            'beneficiaries'=> (int)self::scalar("SELECT COUNT(*) FROM beneficiaries WHERE association_id=? AND status='active'", [$assocId]),
            'receipts'     => (int)self::scalar('SELECT COUNT(*) FROM donation_receipts WHERE association_id=?', [$assocId]),
        ];
    }
}

final class CampaignRepo extends Repo
{
    public static function find(int $id): ?array
    {
        return self::one(
            'SELECT c.*, a.name AS association_name
               FROM donation_campaigns c JOIN associations a ON a.id=c.association_id
              WHERE c.id=?',
            [$id]
        );
    }

    public static function forAssociation(int $assocId, ?string $status = null): array
    {
        $sql    = 'SELECT c.*, (SELECT COUNT(*) FROM donation_campaign_items i WHERE i.campaign_id=c.id) AS items_count
                     FROM donation_campaigns c WHERE c.association_id=?';
        $params = [$assocId];
        if ($status) {
            $sql     .= ' AND c.status=?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY c.created_at DESC';
        return self::q($sql, $params);
    }

    public static function activePublic(): array
    {
        return self::q(
            "SELECT c.*, a.name AS association_name, a.city AS association_city
               FROM donation_campaigns c JOIN associations a ON a.id=c.association_id
              WHERE c.status='active' AND a.status='verified'
              ORDER BY c.created_at DESC"
        );
    }

    public static function create(int $assocId, int $by, array $d): int
    {
        return Database::transaction(function () use ($assocId, $by, $d) {
            self::exec(
                'INSERT INTO donation_campaigns
                   (association_id, created_by, title, slug, description, goal_summary, target_items,
                    target_amount, status, starts_at, ends_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $assocId, $by, $d['title'], slugify($d['title'], (string)random_int(100, 999)),
                    $d['description'], $d['goal_summary'] ?? null, (int)($d['target_items'] ?? 0),
                    $d['target_amount'] ?? null, $d['status'] ?? 'active',
                    $d['starts_at'] ?? today(), $d['ends_at'] ?? null, now(), now(),
                ]
            );
            $id = self::lastId();

            foreach (($d['items'] ?? []) as $item) {
                if (trim((string)($item['item_name'] ?? '')) === '') {
                    continue;
                }
                self::exec(
                    'INSERT INTO donation_campaign_items
                       (campaign_id, item_name, category_id, quantity_needed, priority, note, created_at)
                     VALUES (?,?,?,?,?,?,?)',
                    [
                        $id, $item['item_name'], $item['category_id'] ?? null,
                        (int)($item['quantity_needed'] ?? 1), $item['priority'] ?? 'normal',
                        $item['note'] ?? null, now(),
                    ]
                );
            }
            return $id;
        });
    }

    public static function updateStatus(int $id, string $status): void
    {
        self::exec('UPDATE donation_campaigns SET status=?, updated_at=? WHERE id=?', [$status, now(), $id]);
    }

    public static function items(int $campaignId): array
    {
        return self::q(
            'SELECT i.*, c.name_ar AS category_name
               FROM donation_campaign_items i
               LEFT JOIN categories c ON c.id=i.category_id
              WHERE i.campaign_id=? ORDER BY CASE i.priority WHEN "urgent" THEN 0 WHEN "high" THEN 1 WHEN "normal" THEN 2 ELSE 3 END, i.id',
            [$campaignId]
        );
    }

    /**
     * Adds a donation to a campaign: updates counters and the participant row
     * inside one transaction.
     */
    public static function attachDonation(int $campaignId, int $donationId, int $userId): void
    {
        Database::transaction(function () use ($campaignId, $donationId, $userId) {
            $campaign = self::find($campaignId);
            if (!$campaign) {
                throw new RuntimeException('الحملة غير موجودة.');
            }
            self::exec(
                'UPDATE donations SET campaign_id=?, updated_at=? WHERE id=?',
                [$campaignId, now(), $donationId]
            );
            self::exec(
                'UPDATE donation_campaigns SET received_items=received_items+1, updated_at=? WHERE id=?',
                [now(), $campaignId]
            );
            // A donor may give to the same campaign more than once. The unique key
            // (campaign_id, user_id, donation_id) is what makes a repeat safe to
            // detect here, and MySQL refuses the plain INSERT on a second attempt.
            $rows = self::q(
                'SELECT COUNT(*) AS n FROM donation_campaign_participants
                  WHERE campaign_id=? AND user_id=? AND donation_id=?',
                [$campaignId, $userId, $donationId]
            );
            $already = (int)($rows[0]['n'] ?? 0);
            if ($already === 0) {
                self::exec(
                    'INSERT INTO donation_campaign_participants (campaign_id, user_id, donation_id, role, items_count, joined_at)
                     VALUES (?,?,?,?,?,?)',
                    [$campaignId, $userId, $donationId, 'donor', 1, now()]
                );
            }
        });
    }

    public static function participants(int $campaignId): array
    {
        return self::q(
            'SELECT p.*, u.full_name FROM donation_campaign_participants p
               JOIN users u ON u.id=p.user_id
              WHERE p.campaign_id=? ORDER BY p.joined_at DESC',
            [$campaignId]
        );
    }

    public static function progressPct(array $c): int
    {
        $target = (int)($c['target_items'] ?? 0);
        if ($target <= 0) {
            return 0;
        }
        return min(100, (int)round(((int)$c['received_items'] / $target) * 100));
    }
}

final class DonationRepo extends Repo
{
    private const BASE = "SELECT d.*, u.full_name AS donor_name, a.name AS association_name,
                                 p.title AS product_title, p.slug AS product_slug,
                                 (SELECT file_path FROM product_images pi WHERE pi.product_id=p.id
                                   ORDER BY pi.is_primary DESC, pi.sort_order LIMIT 1) AS primary_image
                            FROM donations d
                            JOIN users u ON u.id=d.donor_id
                            LEFT JOIN associations a ON a.id=d.association_id
                            LEFT JOIN products p ON p.id=d.product_id";

    public static function find(int $id): ?array
    {
        return self::one(self::BASE . ' WHERE d.id=?', [$id]);
    }

    /** Donations visible to one association (targeted to it + general offers). */
    public static function availableForAssociation(int $assocId, int $page = 1, int $perPage = 20): array
    {
        $sql = self::BASE . " WHERE d.status='offered'
                              AND (d.association_id = ? OR (d.donation_scope='general' AND d.association_id IS NULL))
                              ORDER BY d.donation_scope='general', d.created_at DESC";
        $total = (int)self::scalar(
            "SELECT COUNT(*) FROM donations d WHERE d.status='offered'
              AND (d.association_id = ? OR (d.donation_scope='general' AND d.association_id IS NULL))",
            [$assocId]
        );
        $p = paginate($total, $perPage, $page);
         return [self::q($sql . " LIMIT {$p['per_page']} OFFSET {$p['offset']}", [$assocId]), $p];
    }

    public static function claimedByAssociation(int $assocId): array
    {
        return self::q(
            self::BASE . ' WHERE d.association_id=? AND d.status IN (?,?,?) ORDER BY d.claimed_at DESC, d.id DESC',
            [$assocId, 'claimed', 'received', 'distributed']
        );
    }

    public static function createGeneral(int $donorId, array $d): int
    {
        self::exec(
            'INSERT INTO donations (donor_id, association_id, donation_scope, kind, title, quantity,
                                    estimated_value, status, note, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $donorId, null, 'general', 'in_kind', $d['title'], $d['quantity'] ?? null,
                $d['estimated_value'] ?? null, 'offered', $d['note'] ?? null, now(), now(),
            ]
        );
        return self::lastId();
    }

    /**
     * Claiming locks the row, verifies eligibility, then assigns the association.
     */
    public static function claim(int $donationId, int $assocId, int $actorId): void
    {
        Database::transaction(function () use ($donationId, $assocId, $actorId) {
            $d = self::one('SELECT * FROM donations WHERE id=?', [$donationId]);
            if (!$d) {
                throw new RuntimeException('التبرّع غير موجود.');
            }
            if ($d['status'] !== 'offered') {
                throw new RuntimeException('لم يعد هذا التبرّع متاحاً للاستلام.');
            }
            if ($d['donation_scope'] === 'targeted' && (int)$d['association_id'] !== $assocId) {
                throw new RuntimeException('هذا التبرّع موجّه لجمعية أخرى.');
            }
            self::exec(
                'UPDATE donations SET association_id=?, status=?, claimed_at=?, updated_at=? WHERE id=?',
                [$assocId, 'claimed', now(), now(), $donationId]
            );
            self::exec(
                'UPDATE associations SET donations_received = donations_received + 1 WHERE id=?',
                [$assocId]
            );
            NotificationRepo::push(
                (int)$d['donor_id'],
                'donation_claimed',
                'تم استلام تبرّعك',
                'قامت جمعية باستلام تبرّعك: «' . excerpt((string)$d['title'], 60) . '»',
                'my_requests.php'
            );
            ActivityRepo::log($actorId, 'donation.claimed', 'donation', $donationId, ['assoc' => $assocId]);
        });
    }

    public static function markReceived(int $donationId, int $assocId): void
    {
        self::exec(
            'UPDATE donations SET status=?, received_at=?, updated_at=? WHERE id=? AND association_id=?',
            ['received', now(), now(), $donationId, $assocId]
        );
    }

    public static function markDistributed(int $donationId, int $assocId): void
    {
        self::exec(
            'UPDATE donations SET status=?, distributed_at=?, updated_at=? WHERE id=? AND association_id=?',
            ['distributed', now(), now(), $donationId, $assocId]
        );
    }

    public static function statsForDonor(int $donorId): array
    {
        return [
            'total'      => (int)self::scalar('SELECT COUNT(*) FROM donations WHERE donor_id=?', [$donorId]),
            'claimed'    => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE donor_id=? AND status IN ('claimed','received','distributed')", [$donorId]),
            'distributed'=> (int)self::scalar("SELECT COUNT(*) FROM donations WHERE donor_id=? AND status='distributed'", [$donorId]),
        ];
    }

    public static function statsPlatform(): array
    {
        return [
            'offered'     => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE status='offered'"),
            'claimed'     => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE status='claimed'"),
            'received'    => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE status='received'"),
            'distributed' => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE status='distributed'"),
        ];
    }
}

final class BeneficiaryRepo extends Repo
{
    public static function forAssociation(int $assocId, ?string $status = null): array
    {
        $sql    = 'SELECT b.*, (SELECT COUNT(*) FROM distribution_logs l WHERE l.beneficiary_id=b.id) AS distributions
                     FROM beneficiaries b WHERE b.association_id=?';
        $params = [$assocId];
        if ($status) {
            $sql     .= ' AND b.status=?';
            $params[] = $status;
        }
        return self::q($sql . ' ORDER BY b.created_at DESC', $params);
    }

    public static function find(int $id): ?array
    {
        return self::one('SELECT * FROM beneficiaries WHERE id=?', [$id]);
    }

    public static function create(int $assocId, int $by, array $d): int
    {
        $next = (int)self::scalar(
            'SELECT COUNT(*) FROM beneficiaries WHERE association_id=?',
            [$assocId]
        ) + 1001;
        $code = $d['code'] ?: ('B-' . $next);

        self::exec(
            'INSERT INTO beneficiaries (association_id, code, full_name, national_id, phone, city,
                                       household_size, needs_summary, status, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $assocId, $code, $d['full_name'], $d['national_id'] ?? null, $d['phone'] ?? null,
                $d['city'] ?? null, (int)($d['household_size'] ?? 1), $d['needs_summary'] ?? null,
                'active', $by, now(), now(),
            ]
        );
        return self::lastId();
    }

    public static function update(int $id, int $assocId, array $d): void
    {
        self::exec(
            'UPDATE beneficiaries SET full_name=?, national_id=?, phone=?, city=?, household_size=?,
                                     needs_summary=?, status=?, updated_at=?
              WHERE id=? AND association_id=?',
            [
                $d['full_name'], $d['national_id'] ?? null, $d['phone'] ?? null, $d['city'] ?? null,
                (int)($d['household_size'] ?? 1), $d['needs_summary'] ?? null,
                $d['status'] ?? 'active', now(), $id, $assocId,
            ]
        );
    }

    public static function logDistribution(int $assocId, int $by, array $d): int
    {
        self::exec(
            'INSERT INTO distribution_logs (association_id, beneficiary_id, donation_id, receipt_id,
                                            items_summary, quantity, distributed_on, handled_by, note, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                $assocId, (int)$d['beneficiary_id'], $d['donation_id'] ?? null, $d['receipt_id'] ?? null,
                $d['items_summary'], $d['quantity'] ?? null, $d['distributed_on'] ?? today(),
                $by, $d['note'] ?? null, now(),
            ]
        );
        return self::lastId();
    }

    public static function distributions(int $assocId): array
    {
        return self::q(
            'SELECT l.*, b.code AS beneficiary_code, b.full_name AS beneficiary_name, u.full_name AS handler_name
               FROM distribution_logs l
               JOIN beneficiaries b ON b.id=l.beneficiary_id
               JOIN users u ON u.id=l.handled_by
              WHERE l.association_id=?
              ORDER BY l.distributed_on DESC, l.id DESC',
            [$assocId]
        );
    }
}

final class ReceiptRepo extends Repo
{
    public static function nextNumber(): string
    {
        $year   = date('Y');
        $prefix = 'RCP-' . $year . '-';
        $last   = (string)self::scalar(
            'SELECT receipt_no FROM donation_receipts WHERE receipt_no LIKE ? ORDER BY id DESC LIMIT 1',
            [$prefix . '%']
        );
        $seq = $last !== '' ? ((int)substr($last, -4)) + 1 : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    public static function issue(int $donationId, int $assocId, int $by, array $d): array
    {
        return Database::transaction(function () use ($donationId, $assocId, $by, $d) {
            $donation = DonationRepo::find($donationId);
            if (!$donation || (int)$donation['association_id'] !== $assocId) {
                throw new RuntimeException('التبرّع غير مرتبط بجمعيتك.');
            }
            $no = self::nextNumber();
            self::exec(
                'INSERT INTO donation_receipts (receipt_no, donation_id, association_id, issued_by,
                                                beneficiary_ref, items_summary, quantity, issued_at,
                                                signature_note, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $no, $donationId, $assocId, $by, $d['beneficiary_ref'] ?? null,
                    $d['items_summary'], $d['quantity'] ?? null, now(),
                    $d['signature_note'] ?? null, now(),
                ]
            );
            $id = self::lastId();

            DonationRepo::markReceived($donationId, $assocId);

            if (!empty($d['beneficiary_id'])) {
                BeneficiaryRepo::logDistribution($assocId, $by, [
                    'beneficiary_id' => (int)$d['beneficiary_id'],
                    'donation_id'    => $donationId,
                    'receipt_id'     => $id,
                    'items_summary'  => $d['items_summary'],
                    'quantity'       => $d['quantity'] ?? null,
                    'distributed_on' => today(),
                    'note'           => 'إيصال ' . $no,
                ]);
                DonationRepo::markDistributed($donationId, $assocId);
            }

            return ['id' => $id, 'receipt_no' => $no];
        });
    }

    public static function forAssociation(int $assocId): array
    {
        return self::q(
            'SELECT r.*, d.title AS donation_title, u.full_name AS issuer_name
               FROM donation_receipts r
               JOIN donations d ON d.id=r.donation_id
               JOIN users u ON u.id=r.issued_by
              WHERE r.association_id=?
              ORDER BY r.id DESC',
            [$assocId]
        );
    }

    public static function find(int $id): ?array
    {
        return self::one(
            'SELECT r.*, d.title AS donation_title, d.donor_id, u.full_name AS donor_name,
                    a.name AS association_name, i.full_name AS issuer_name
               FROM donation_receipts r
               JOIN donations d ON d.id=r.donation_id
               JOIN users u ON u.id=d.donor_id
               JOIN associations a ON a.id=r.association_id
               JOIN users i ON i.id=r.issued_by
              WHERE r.id=?',
            [$id]
        );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Chat & notifications
   ══════════════════════════════════════════════════════════════════════════ */

final class ChatRepo extends Repo
{
    /** Finds or creates the conversation between two users for an optional product. */
    public static function open(int $userId, int $otherUserId, ?int $productId = null, ?int $requestId = null, ?string $subject = null): int
    {
        if ($userId === $otherUserId) {
            throw new RuntimeException('لا يمكنك بدء محادثة مع نفسك.');
        }
        $a = min($userId, $otherUserId);
        $b = max($userId, $otherUserId);

        $existing = self::one(
            'SELECT id FROM conversations WHERE user_a_id=? AND user_b_id=? AND
                (product_id = ? OR (product_id IS NULL AND ? IS NULL)) LIMIT 1',
            [$a, $b, $productId, $productId]
        );
        if ($existing) {
            return (int)$existing['id'];
        }

        self::exec(
            'INSERT INTO conversations (product_id, request_id, user_a_id, user_b_id, subject, status, last_message_at, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$productId, $requestId, $a, $b, $subject, 'open', now(), now()]
        );
        return self::lastId();
    }

    /**
     * Conversation for an accepted request. Only the requester and the owner
     * are participants; a targeted donation also carries the association id
     * so the rep can act, but the thread itself stays two-party.
     */
    public static function openForRequest(int $requestId, int $ownerId, int $requesterId, ?int $productId, int $associationId = 0): int
    {
        $existing = self::one('SELECT id FROM conversations WHERE request_id=? LIMIT 1', [$requestId]);
        if ($existing) {
            return (int)$existing['id'];
        }
        $a = min($ownerId, $requesterId);
        $b = max($ownerId, $requesterId);
        $subject = (string)self::scalar('SELECT title FROM products WHERE id=?', [$productId]);
        self::exec(
            'INSERT INTO conversations (product_id, request_id, user_a_id, user_b_id, subject, status, created_at, last_message_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$productId, $requestId, $a, $b, $subject !== '' ? $subject : 'طلب #' . $requestId, 'open', now(), now()]
        );
        $id = self::lastId();
        self::exec(
            'INSERT INTO messages (conversation_id, sender_id, body, kind, created_at, delivered_at)
             VALUES (?,?,?,?,?,?)',
            [$id, $ownerId, 'تم قبول الطلب وفتح المحادثة. اتفقا على السعر وطريقة التسليم ثم أكّدا الاتفاق كلٌّ من جهته.', 'system', now(), now()]
        );
        return $id;
    }

    public static function find(int $id): ?array
    {
        return self::one(
            'SELECT c.*, r.status AS request_status, r.requester_id, r.owner_id
               FROM conversations c LEFT JOIN user_requests r ON r.id = c.request_id
              WHERE c.id=?',
            [$id]
        );
    }

    /** True only for the two people in the conversation (admin may audit). */
    public static function canAccess(int $conversationId, int $userId, bool $isAdmin = false): bool
    {
        if ($isAdmin) {
            return true;
        }
        return self::isParticipant($conversationId, $userId);
    }

    public static function isParticipant(int $conversationId, int $userId): bool
    {
        return (bool)self::scalar(
            'SELECT 1 FROM conversations WHERE id=? AND (user_a_id=? OR user_b_id=?)',
            [$conversationId, $userId, $userId]
        );
    }

    public static function forUser(int $userId): array
    {
        return self::q(
            "SELECT c.*,
                    CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS other_id,
                    ou.full_name AS other_name,
                    p.title AS product_title,
                    (SELECT body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_at,
                    (SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_id<>? AND m.read_at IS NULL) AS unread
               FROM conversations c
               JOIN users ou ON ou.id = (CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END)
               LEFT JOIN products p ON p.id = c.product_id
              WHERE (c.user_a_id = ? OR c.user_b_id = ?) AND c.status <> 'blocked'
              ORDER BY COALESCE(c.last_message_at, c.created_at) DESC",
            [$userId, $userId, $userId, $userId, $userId]
        );
    }

    public static function messages(int $conversationId, int $afterId = 0, int $limit = 100): array
    {
        return self::q(
            'SELECT m.*, u.full_name AS sender_name, u.id AS sender_user_id
               FROM messages m JOIN users u ON u.id=m.sender_id
              WHERE m.conversation_id=? AND m.id > ?
              ORDER BY m.id ASC LIMIT ' . max(1, min(200, $limit)),
            [$conversationId, $afterId]
        );
    }

    public static function send(int $conversationId, int $senderId, string $body, string $kind = 'text'): array
    {
        return Database::transaction(function () use ($conversationId, $senderId, $body, $kind) {
            $conv = self::find($conversationId);
            if (!$conv) {
                throw new RuntimeException('المحادثة غير موجودة.');
            }
            if ((int)$conv['user_a_id'] !== $senderId && (int)$conv['user_b_id'] !== $senderId) {
                throw new RuntimeException('غير مسموح لك بالكتابة في هذه المحادثة.');
            }
            if ($conv['status'] === 'blocked') {
                throw new RuntimeException('هذه المحادثة مغلقة.');
            }
            // A finished deal keeps its transcript readable but read-only.
            if (!empty($conv['request_status']) && in_array((string)$conv['request_status'], ['delivered', 'rejected', 'cancelled'], true)) {
                throw new RuntimeException('أُغلق هذا الطلب — المحادثة متاحة للقراءة فقط.');
            }

            self::exec(
                'INSERT INTO messages (conversation_id, sender_id, body, kind, created_at) VALUES (?,?,?,?,?)',
                [$conversationId, $senderId, $body, $kind, now()]
            );
            $id = self::lastId();
            self::exec('UPDATE conversations SET last_message_at=? WHERE id=?', [now(), $conversationId]);

            $otherId = (int)$conv['user_a_id'] === $senderId ? (int)$conv['user_b_id'] : (int)$conv['user_a_id'];
            $sender  = (string)self::scalar('SELECT full_name FROM users WHERE id=?', [$senderId]);
            NotificationRepo::push(
                $otherId,
                'message_new',
                'رسالة جديدة',
                excerpt($sender . ': ' . $body, 90),
                'chat.php?c=' . $conversationId
            );

            $row = self::one('SELECT * FROM messages WHERE id=?', [$id]);
            return $row ?? ['id' => $id];
        });
    }

    /** Messages addressed to this user are marked delivered the moment they are fetched. */
    public static function markDelivered(int $conversationId, int $userId): void
    {
        self::exec(
            'UPDATE messages SET delivered_at=? WHERE conversation_id=? AND sender_id<>? AND delivered_at IS NULL',
            [now(), $conversationId, $userId]
        );
    }

    public static function markRead(int $conversationId, int $userId): int
    {
        self::markDelivered($conversationId, $userId);
        $n = self::exec(
            'UPDATE messages SET read_at=? WHERE conversation_id=? AND sender_id<>? AND read_at IS NULL',
            [now(), $conversationId, $userId]
        );
        $lastId = (int)self::scalar(
            'SELECT MAX(id) FROM messages WHERE conversation_id=?',
            [$conversationId]
        );
        $existing = self::scalar(
            'SELECT 1 FROM conversation_reads WHERE conversation_id=? AND user_id=?',
            [$conversationId, $userId]
        );
        if ($existing) {
            self::exec(
                'UPDATE conversation_reads SET last_read_message_id=?, read_at=?
                  WHERE conversation_id=? AND user_id=?',
                [$lastId, now(), $conversationId, $userId]
            );
        } else {
            self::exec(
                'INSERT INTO conversation_reads (conversation_id, user_id, last_read_message_id, read_at)
                 VALUES (?,?,?,?)',
                [$conversationId, $userId, $lastId, now()]
            );
        }
        return $n;
    }

    public static function unreadTotal(int $userId): int
    {
        return (int)self::scalar(
            'SELECT COUNT(*) FROM messages m
               JOIN conversations c ON c.id=m.conversation_id
              WHERE (c.user_a_id=? OR c.user_b_id=?) AND m.sender_id<>? AND m.read_at IS NULL',
            [$userId, $userId, $userId]
        );
    }
}

final class NotificationRepo extends Repo
{
    public static function push(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, string $icon = 'bell'): void
    {
        self::exec(
            'INSERT INTO notifications (user_id, type, title, body, link_url, icon, is_read, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [$userId, $type, $title, $body, $link, $icon, 0, now()]
        );
    }

    public static function forUser(int $userId, int $limit = 30): array
    {
        return self::q(
            'SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            [$userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int)self::scalar('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$userId]);
    }

    public static function markRead(int $userId, ?int $id = null): int
    {
        if ($id !== null) {
            return self::exec(
                'UPDATE notifications SET is_read=1, read_at=? WHERE id=? AND user_id=?',
                [now(), $id, $userId]
            );
        }
        return self::exec(
            'UPDATE notifications SET is_read=1, read_at=? WHERE user_id=? AND is_read=0',
            [now(), $userId]
        );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Trust & moderation
   ══════════════════════════════════════════════════════════════════════════ */

final class RatingRepo extends Repo
{
    public static function create(int $requestId, int $raterId, int $score, ?string $comment, ?int $rateeOverride = null): void
    {
        Database::transaction(function () use ($requestId, $raterId, $score, $comment, $rateeOverride) {
            $req = self::one('SELECT * FROM user_requests WHERE id=?', [$requestId]);
            if (!$req) {
                throw new RuntimeException('الطلب غير موجود.');
            }
            if ($req['status'] !== 'delivered') {
                throw new RuntimeException('يمكن التقييم بعد تأكيد التسليم من الطرفين.');
            }
            /* Operator's rule: ANY signed-in user may rate a completed deal —
               with one exception, the person who published the product. Their
               own item is not a transaction they took part in, and letting them
               rate it would be self-praise of the service they offered.

               Who the score lands on is unchanged: the counterparty of the deal.
               A stranger rates the seller (the party facing the market); a
               targeted donation's counterparty is the association's rep. */
            $dealtWith = self::counterpartyFor($req, $raterId);
            if ($dealtWith <= 0 || $dealtWith === $raterId) {
                throw new RuntimeException('لا يوجد طرف آخر في هذه العملية لتقييمه.');
            }
            $publisher = self::publisherIdFor($req);
            if ($raterId === $publisher) {
                throw new RuntimeException('لا يمكنك تقييم عملية على منتج نشرته أنت.');
            }
            if (self::scalar('SELECT 1 FROM ratings WHERE request_id=? AND rater_id=?', [$requestId, $raterId])) {
                throw new RuntimeException('لقد قيّمت هذه العملية مسبقاً.');
            }
            $rateeId = $rateeOverride ?: $dealtWith;
            if ($rateeId <= 0) {
                throw new RuntimeException('لا يوجد طرف آخر لتقييمه.');
            }

            self::exec(
                'INSERT INTO ratings (request_id, rater_id, ratee_id, product_id, score, comment, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$requestId, $raterId, $rateeId, (int)$req['product_id'], $score, $comment, now()]
            );
            UserRepo::recalculateRating($rateeId);
            ActivityRepo::log($raterId, 'rating.created', 'request', $requestId, ['score' => $score]);
        });
    }

    /** The user who published the product behind a request (never rateable). */
    public static function publisherIdFor(array $req): int
    {
        $pid = (int)($req['product_id'] ?? 0);
        if ($pid > 0) {
            $owner = (int)self::scalar('SELECT owner_id FROM products WHERE id=?', [$pid]);
            if ($owner > 0) {
                return $owner;
            }
        }
        return (int)($req['owner_id'] ?? 0);
    }

    /**
     * Who this rater is rating: the counterparty of the deal.
     *
     * - A party to the deal rates the other party (targeted donation: the rep).
     * - Anyone else rates the party that represents the product to the market —
     *   the seller, or the association for a donation.
     */
    public static function counterpartyFor(array $req, int $raterId): int
    {
        $parties = RequestFlowRepo::expectedParties($req);
        if (in_array($raterId, $parties, true)) {
            $others = array_values(array_diff($parties, [$raterId]));
            return (int)($others[0] ?? 0);
        }
        $assoc = (int)($req['association_id'] ?? 0);
        if ($assoc > 0) {
            $rep = (int)self::scalar('SELECT owner_user_id FROM associations WHERE id=?', [$assoc]);
            if ($rep > 0) {
                return $rep;
            }
        }
        return (int)($req['owner_id'] ?? 0);
    }

    public static function forRequest(int $requestId): array
    {
        return self::q(
            'SELECT r.*, u.full_name AS rater_name FROM ratings r JOIN users u ON u.id=r.rater_id
              WHERE r.request_id=? ORDER BY r.id',
            [$requestId]
        );
    }

    public static function hasRated(int $requestId, int $userId): bool
    {
        return (bool)self::scalar('SELECT 1 FROM ratings WHERE request_id=? AND rater_id=?', [$requestId, $userId]);
    }

    public static function recentFor(int $userId, int $limit = 10): array
    {
        return self::q(
            'SELECT r.*, u.full_name AS rater_name FROM ratings r JOIN users u ON u.id=r.rater_id
              WHERE r.ratee_id=? ORDER BY r.id DESC LIMIT ' . max(1, $limit),
            [$userId]
        );
    }
}

final class ModerationRepo extends Repo
{
    public static function reportEntity(int $reporterId, string $entityType, int $entityId, string $reason, ?string $details): int
    {
        self::exec(
            'INSERT INTO reports (reporter_id, entity_type, entity_id, reason, details, status, created_at)
             VALUES (?,?,?,?,?,?,?)',
            [$reporterId, $entityType, $entityId, $reason, $details, 'open', now()]
        );
        $id = self::lastId();

        if ($entityType === 'product') {
            self::exec(
                'INSERT INTO product_reports (product_id, reporter_id, report_id, reason_code, note, status, created_at)
                 VALUES (?,?,?,?,?,?,?)',
                [$entityId, $reporterId, $id, $reason, $details, 'open', now()]
            );
        }
        ActivityRepo::log($reporterId, 'report.created', $entityType, $entityId, ['reason' => $reason]);
        return $id;
    }

    public static function reports(array $f = [], int $page = 1, int $perPage = 25): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($f['status'])) {
            $where[]  = 'r.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['entity_type'])) {
            $where[]  = 'r.entity_type = ?';
            $params[] = $f['entity_type'];
        }
        $sqlWhere = implode(' AND ', $where);
        $total    = (int)self::scalar("SELECT COUNT(*) FROM reports r WHERE $sqlWhere", $params);
        $p        = paginate($total, $perPage, $page);
        $rows     = self::q(
            "SELECT r.*, u.full_name AS reporter_name, rv.full_name AS reviewer_name
               FROM reports r
               JOIN users u ON u.id=r.reporter_id
               LEFT JOIN users rv ON rv.id=r.reviewed_by
              WHERE $sqlWhere ORDER BY r.id DESC
              LIMIT {$p['per_page']} OFFSET {$p['offset']}",
            $params
        );
        return [$rows, $p];
    }

    public static function resolveReport(int $id, string $status, int $adminId, ?string $note): void
    {
        Database::transaction(function () use ($id, $status, $adminId, $note) {
            self::exec(
                'UPDATE reports SET status=?, action_note=?, reviewed_by=?, reviewed_at=? WHERE id=?',
                [$status, $note, $adminId, now(), $id]
            );
            self::exec(
                'UPDATE product_reports SET status=?, reviewed_by=?, reviewed_at=? WHERE report_id=?',
                [$status, $adminId, now(), $id]
            );
        });
    }

    public static function complaints(?string $status = null): array
    {
        $sql    = 'SELECT c.*, u.full_name AS complainant_name, a.full_name AS against_name, h.full_name AS handler_name
                     FROM complaints c
                     JOIN users u ON u.id=c.complainant_id
                     LEFT JOIN users a ON a.id=c.against_user_id
                     LEFT JOIN users h ON h.id=c.handled_by';
        $params = [];
        if ($status) {
            $sql     .= ' WHERE c.status=?';
            $params[] = $status;
        }
        return self::q($sql . ' ORDER BY c.id DESC', $params);
    }

    public static function openCount(): int
    {
        return (int)self::scalar("SELECT COUNT(*) FROM reports WHERE status='open'")
             + (int)self::scalar("SELECT COUNT(*) FROM complaints WHERE status IN ('open','investigating')");
    }
}

final class SettingsRepo extends Repo
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = self::q('SELECT * FROM system_settings ORDER BY group_name, setting_key');
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = self::cast($r);
        }
        self::$cache = $out;
        return $out;
    }

    public static function settingRows(): array
    {
        return self::q('SELECT * FROM system_settings ORDER BY group_name, setting_key');
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, $value, ?int $by = null): void
    {
        $type = (string)self::scalar('SELECT value_type FROM system_settings WHERE setting_key=?', [$key]);
        $stored = match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string)$value,
        };
        self::exec(
            'UPDATE system_settings SET setting_value=?, updated_by=?, updated_at=? WHERE setting_key=?',
            [$stored, $by, now(), $key]
        );
        self::$cache = null;
    }

    private static function cast(array $row)
    {
        return match ($row['value_type']) {
            'int'  => (int)$row['setting_value'],
            'bool' => (bool)(int)$row['setting_value'],
            'json' => json_decode((string)$row['setting_value'], true),
            default => $row['setting_value'],
        };
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   Search history & statistics
   ══════════════════════════════════════════════════════════════════════════ */

final class SearchRepo extends Repo
{
    public static function log(?int $userId, ?string $term, ?int $categoryId, ?string $city, ?string $type, int $results): void
    {
        self::exec(
            'INSERT INTO search_history (user_id, session_ref, term, category_id, city, transaction_type, results_count, created_at)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $userId, substr(session_id() ?: '', 0, 64), $term ? mb_substr($term, 0, 160) : null,
                $categoryId, $city, $type, $results, now(),
            ]
        );
    }

    public static function recentTerms(int $userId, int $limit = 8): array
    {
        return self::q(
            'SELECT term, COUNT(*) n FROM search_history
              WHERE user_id=? AND term IS NOT NULL AND term <> ""
              GROUP BY term ORDER BY n DESC, MAX(created_at) DESC LIMIT ' . max(1, $limit),
            [$userId]
        );
    }

    public static function recentCategories(int $userId, int $limit = 4): array
    {
        return self::q(
            'SELECT category_id, COUNT(*) n FROM search_history
              WHERE user_id=? AND category_id IS NOT NULL
              GROUP BY category_id ORDER BY n DESC LIMIT ' . max(1, $limit),
            [$userId]
        );
    }

    public static function popular(int $limit = 6): array
    {
        return self::q(
            'SELECT term, COUNT(*) n FROM search_history WHERE term IS NOT NULL AND term <> ""
              GROUP BY term ORDER BY n DESC LIMIT ' . max(1, $limit)
        );
    }
}

final class StatsRepo extends Repo
{
    public static function platform(): array
    {
        return [
            'users'        => (int)self::scalar('SELECT COUNT(*) FROM users'),
            'products'     => (int)self::scalar("SELECT COUNT(*) FROM products WHERE status<>'removed'"),
            'available'    => (int)self::scalar("SELECT COUNT(*) FROM products WHERE status='available'"),
            'reserved'     => (int)self::scalar("SELECT COUNT(*) FROM products WHERE status='reserved'"),
            'completed'    => (int)self::scalar("SELECT COUNT(*) FROM products WHERE status='completed'"),
            'requests'     => (int)self::scalar('SELECT COUNT(*) FROM user_requests'),
            'new_requests' => (int)self::scalar("SELECT COUNT(*) FROM user_requests WHERE status='awaiting_owner'"),
            'in_deal'      => (int)self::scalar("SELECT COUNT(*) FROM user_requests WHERE status IN ('accepted','negotiating','agreed','delivery_pending')"),
            'delivered'    => (int)self::scalar("SELECT COUNT(*) FROM user_requests WHERE status='delivered'"),
            'disputed'     => (int)self::scalar("SELECT COUNT(*) FROM user_requests WHERE status='disputed'"),
            'associations' => (int)self::scalar("SELECT COUNT(*) FROM associations WHERE status='verified'"),
            'pending_assoc'=> (int)self::scalar("SELECT COUNT(*) FROM associations WHERE status='pending'"),
            'donations'    => (int)self::scalar('SELECT COUNT(*) FROM donations'),
            'distributed'  => (int)self::scalar("SELECT COUNT(*) FROM donations WHERE status='distributed'"),
            'campaigns'    => (int)self::scalar("SELECT COUNT(*) FROM donation_campaigns WHERE status='active'"),
            'reports'      => ModerationRepo::openCount(),
            'messages'     => (int)self::scalar('SELECT COUNT(*) FROM messages'),
        ];
    }

    public static function heroCounters(): array
    {
        return [
            'products'     => (int)self::scalar('SELECT COUNT(*) FROM products'),
            'beneficiaries'=> (int)self::scalar("SELECT COUNT(*) FROM donations WHERE status IN ('received','distributed')"),
            'associations' => (int)self::scalar("SELECT COUNT(*) FROM associations WHERE status='verified'"),
        ];
    }

    public static function userDashboard(int $userId): array
    {
        return [
            'my_products'   => (int)self::scalar('SELECT COUNT(*) FROM products WHERE owner_id=?', [$userId]),
            'available'     => (int)self::scalar("SELECT COUNT(*) FROM products WHERE owner_id=? AND status='available'", [$userId]),
            'reserved'      => (int)self::scalar("SELECT COUNT(*) FROM products WHERE owner_id=? AND status='reserved'", [$userId]),
            'incoming'      => (int)self::scalar('SELECT COUNT(*) FROM user_requests WHERE owner_id=?', [$userId]),
            'new_incoming'  => (int)self::scalar("SELECT COUNT(*) FROM user_requests WHERE owner_id=? AND status='awaiting_owner'", [$userId]),
            'active_deals'  => (int)self::scalar("SELECT COUNT(*) FROM user_requests WHERE (owner_id=? OR requester_id=?) AND status IN ('accepted','negotiating','agreed','delivery_pending')", [$userId, $userId]),
            'outgoing'      => (int)self::scalar('SELECT COUNT(*) FROM user_requests WHERE requester_id=?', [$userId]),
            'favorites'     => FavoriteRepo::countFor($userId),
            'unread_msgs'   => ChatRepo::unreadTotal($userId),
        ];
    }

    public static function chartLast6Months(): array
    {
        $out    = [];
        $anchor = strtotime(date('Y-m-01'));
        $start5 = strtotime('-5 months', $anchor);
        for ($i = 0; $i < 6; $i++) {
            $from = date('Y-m-01', strtotime("+{$i} months", $start5));
            $to   = date('Y-m-01', strtotime('+' . ($i + 1) . ' months', $start5));
            $out[] = [
                'label'    => date('m/Y', strtotime($from)),
                'products' => (int)self::scalar('SELECT COUNT(*) FROM products WHERE created_at >= ? AND created_at < ?', [$from, $to]),
                'requests' => (int)self::scalar('SELECT COUNT(*) FROM user_requests WHERE created_at >= ? AND created_at < ?', [$from, $to]),
            ];
        }
        return $out;
    }
}
