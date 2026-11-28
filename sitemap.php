<?php
/**
 * sitemap.php — XML sitemap of public pages, listings and campaigns.
 * Linked from robots.txt so crawlers always see fresh products.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');

$urls = [];
foreach (['index.php' => '1.0', 'login.php' => '0.3', 'register.php' => '0.3'] as $path => $priority) {
    $urls[] = ['loc' => url($path), 'priority' => $priority, 'lastmod' => null];
}

foreach (Repo::q("SELECT slug, updated_at FROM products WHERE status='available' ORDER BY created_at DESC LIMIT 2000") as $p) {
    $urls[] = ['loc' => url('product_details.php?slug=' . urlencode((string)$p['slug'])), 'priority' => '0.8', 'lastmod' => (string)($p['updated_at'] ?? null)];
}
foreach (Repo::q("SELECT slug, updated_at FROM donation_campaigns WHERE status='active' LIMIT 500") as $c) {
    $urls[] = ['loc' => url('campaign.php?slug=' . urlencode((string)$c['slug'])), 'priority' => '0.6', 'lastmod' => (string)($c['updated_at'] ?? null)];
}

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1) ?></loc>
<?php if (!empty($u['lastmod'])): ?>
    <lastmod><?= htmlspecialchars(date('Y-m-d', strtotime((string)$u['lastmod'])), ENT_XML1) ?></lastmod>
<?php endif; ?>
    <priority><?= e($u['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
