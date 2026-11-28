<?php
/**
 * api/ai_recommendations.php
 * -----------------------------------------------------------------------------
 * Local recommendation engine (no external calls):
 *
 *   ?product_id=ID        "منتجات قد تهمّك" — same category, city and price
 *                         band, boosted by search_history + favorite_products.
 *   ?scope=charity        matches general donations to active campaigns by
 *                         category / item name overlap.
 *   ?scope=home           personalised feed for the signed-in user from
 *                         search_history + favourites; falls back to popular.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

header('Cache-Control: no-store');

$uid       = Auth::check() ? (int)Auth::id() : 0;
$productId = get_int('product_id', 0) ?? 0;
$scope     = (string)get_q('scope', $productId ? 'product' : 'home');

$items = [];

/* ── A. related products for one product ─────────────────────────────────── */
if ($scope === 'product' && $productId > 0) {
    $base = ProductRepo::find($productId);
    if (!$base) {
        json_err('المنتج غير موجود.', 404);
    }

    $candidates = ProductRepo::search(['exclude_id' => $productId], 1, 60)[0];

    // Signals from the viewer's own history (works for guests as an empty set).
    $termBoost = [];
    $favIds    = [];
    if ($uid) {
        foreach (SearchRepo::recentTerms($uid, 8) as $row) {
            $termBoost[mb_strtolower((string)$row['term'])] = (int)$row['n'];
        }
        $favIds = FavoriteRepo::idsFor($uid);
    }
    $basePrice = $base['price'] !== null ? (float)$base['price'] : null;

    foreach ($candidates as &$c) {
        $score = 0;
        $why   = [];

        if ((int)$c['category_id'] === (int)$base['category_id']) {
            $score += 40;
            $why[]  = 'نفس التصنيف';
        }
        if ((string)$c['city'] === (string)$base['city']) {
            $score += 18;
            $why[]  = 'نفس المدينة';
        }
        if ((string)$c['transaction_type'] === (string)$base['transaction_type']) {
            $score += 12;
        }
        if ($basePrice !== null && $c['price'] !== null) {
            $diff = abs((float)$c['price'] - $basePrice) / max(1.0, $basePrice);
            if ($diff <= 0.25) {
                $score += 20;
                $why[]  = 'سعر قريب';
            } elseif ($diff <= 0.5) {
                $score += 10;
            }
        }
        foreach ($termBoost as $term => $n) {
            if ($term !== '' && mb_strpos(mb_strtolower((string)$c['title']), $term) !== false) {
                $score += min(15, 5 * $n);
                $why[]  = 'يطابق بحثك السابق';
                break;
            }
        }
        if (in_array((int)$c['id'], $favIds, true)) {
            $score += 5;
        }
        $score += min(10, (int)($c['views_count'] / 40));

        $c['_score'] = $score;
        $c['_why']   = $why;
    }
    unset($c);

    usort($candidates, static fn ($a, $b) => $b['_score'] <=> $a['_score']);

    foreach (array_slice($candidates, 0, 6) as $c) {
        $items[] = [
            'id'          => (int)$c['id'],
            'title'       => (string)$c['title'],
            'slug'        => (string)$c['slug'],
            'image'       => product_image($c['primary_image'] ?? null),
            'price_label' => $c['transaction_type'] === 'sell' ? money($c['price']) : transaction_meta()[$c['transaction_type']]['label'],
            'why'         => $c['_why'] ? implode(' · ', array_unique($c['_why'])) : 'مقترح من السوق',
            'score'       => (int)$c['_score'],
        ];
    }
    json_ok(['ok' => true, 'engine' => 'local', 'scope' => 'product', 'items' => $items]);
}

/* ── B. general donations → active campaigns ─────────────────────────────── */
if ($scope === 'charity') {
    $campaigns = CampaignRepo::activePublic();
    $donations = Repo::q(
        "SELECT d.*, c.name_ar AS category_name
           FROM donations d
           LEFT JOIN products p ON p.id = d.product_id
           LEFT JOIN categories c ON c.id = p.category_id
          WHERE d.donation_scope='general' AND d.status='offered'
          ORDER BY d.created_at DESC LIMIT 40"
    );

    $assoc = Auth::association();
    foreach ($campaigns as $c) {
        $campItems = CampaignRepo::items((int)$c['id']);
        $itemNames = array_map(static fn ($i) => mb_strtolower((string)$i['item_name']), $campItems);
        $catIds    = array_filter(array_map(static fn ($i) => (int)($i['category_id'] ?? 0), $campItems));

        $matched = 0;
        $reasons = [];
        foreach ($donations as $d) {
            $title = mb_strtolower((string)$d['title']);
            $hit   = false;
            foreach ($itemNames as $name) {
                foreach (preg_split('/\s+/u', $name) ?: [] as $word) {
                    if (mb_strlen($word) >= 3 && mb_strpos($title, $word) !== false) {
                        $hit = true;
                        break 2;
                    }
                }
            }
            if (!$hit && !empty($d['category_id']) && in_array((int)$d['category_id'], $catIds, true)) {
                $hit = true;
            }
            if ($hit) {
                $matched++;
                if (count($reasons) < 3) {
                    $reasons[] = (string)$d['title'];
                }
            }
        }

        $need = max(1, (int)$c['target_items']);
        $pct  = min(100, (int)round(((int)$c['received_items'] / $need) * 100));
        $score = (30 - $pct / 4) + ($matched * 8) + (count($campItems) > 0 ? 6 : 0);
        if ($assoc && (int)$assoc['id'] === (int)$c['association_id']) {
            $score += 25; // prefer the campaigns of the signed-in association
        }

        $items[] = [
            'id'        => (int)$c['id'],
            'title'     => (string)$c['title'],
            'why'       => $matched > 0
                ? $matched . ' تبرّعاً عاماً يطابق احتياجات الحملة' . ($reasons ? ' (' . excerpt(implode('، ', $reasons), 70) . ')' : '')
                : 'الحملة تحتاج ' . (int)$c['target_items'] . ' عنصراً ولم تُكمل هدفها بعد',
            'match_pct' => max(5, min(100, (int)round($score))),
            'icon'      => icon('hand-heart', 16),
            'link'      => 'charity/campaigns.php?campaign=' . (int)$c['id'],
            'score'     => (int)round($score),
        ];
    }

    usort($items, static fn ($a, $b) => $b['score'] <=> $a['score']);
    json_ok(['ok' => true, 'engine' => 'local', 'scope' => 'charity', 'items' => array_slice($items, 0, 5)]);
}

/* ── C. personalised home feed ───────────────────────────────────────────── */
$boostCats = [];
if ($uid) {
    foreach (SearchRepo::recentCategories($uid, 4) as $row) {
        $boostCats[(int)$row['category_id']] = (int)$row['n'];
    }
    foreach (SearchRepo::recentTerms($uid, 5) as $row) {
        $boostTerms[] = mb_strtolower((string)$row['term']);
    }
}
$candidates = ProductRepo::search(['status' => 'available'], 1, 40)[0];

foreach ($candidates as &$c) {
    $score = min(20, (int)($c['views_count'] / 30)) + (int)$c['favorites_count'] * 3;
    if (isset($boostCats[(int)$c['category_id']])) {
        $score += 20 * min(3, $boostCats[(int)$c['category_id']]);
    }
    foreach (($boostTerms ?? []) as $t) {
        if ($t !== '' && mb_strpos(mb_strtolower((string)$c['title']), $t) !== false) {
            $score += 18;
            break;
        }
    }
    $c['_score'] = $score;
}
unset($c);
usort($candidates, static fn ($a, $b) => $b['_score'] <=> $a['_score']);

foreach (array_slice($candidates, 0, 8) as $c) {
    $items[] = [
        'id'          => (int)$c['id'],
        'title'       => (string)$c['title'],
        'slug'        => (string)$c['slug'],
        'image'       => product_image($c['primary_image'] ?? null),
        'price_label' => $c['transaction_type'] === 'sell' ? money($c['price']) : transaction_meta()[$c['transaction_type']]['label'],
        'why'         => $uid ? 'مبني على بحثك ومفضلتك' : 'الأكثر تفاعلاً في السوق',
        'score'       => (int)$c['_score'],
    ];
}

json_ok(['ok' => true, 'engine' => 'local', 'scope' => 'home', 'personalised' => $uid > 0, 'items' => $items]);
