<?php
/**
 * api/claim_donation.php — an association claims an in-kind donation.
 *   POST donation_id
 * Only targeted donations addressed to that association, or general offers,
 * can be claimed; the update runs inside a transaction.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
$assoc = Gate::requireAssociation();
Gate::requireMemberCapability('donations');
require_csrf();

if ((string)$assoc['status'] !== 'verified') {
    json_err('لا يمكن استلام التبرّعات قبل توثيق الجمعية من الإدارة.', 403);
}

$donationId = post_int('donation_id', 0) ?? 0;
try {
    DonationRepo::claim($donationId, (int)$assoc['id'], (int)Auth::id());
    json_ok(['message' => 'تم استلام التبرّع باسم جمعيتك.', 'reload' => true]);
} catch (RuntimeException $ex) {
    json_err($ex->getMessage(), 422);
} catch (Throwable $ex) {
    json_err('تعذّر استلام التبرّع.', 500);
}
