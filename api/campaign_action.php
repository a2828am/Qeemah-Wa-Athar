<?php
/**
 * api/campaign_action.php — activate / close one of the association's campaigns.
 *   POST campaign_id, action=activate|close
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('استخدم POST لهذا الإجراء.', 405);
}
$assoc = Gate::requireAssociation();
Gate::requireMemberCapability('campaigns');
require_csrf();

$campaignId = post_int('campaign_id', 0) ?? 0;
$action     = (string)post('action', '');
$campaign   = $campaignId ? CampaignRepo::find($campaignId) : null;

if (!$campaign || (int)$campaign['association_id'] !== (int)$assoc['id']) {
    json_err('الحملة غير موجودة أو لا تخصّ جمعيتك.', 404);
}
if (!in_array($action, ['activate', 'close'], true)) {
    json_err('إجراء غير معروف.');
}

$status = $action === 'activate' ? 'active' : 'closed';
CampaignRepo::updateStatus($campaignId, $status);
AuditRepo::log(null, 'campaign.' . $action, 'campaign', $campaignId, ['status' => $campaign['status']], ['status' => $status]);

json_ok(['message' => $action === 'activate' ? 'تم تفعيل الحملة.' : 'تم إغلاق الحملة.', 'reload' => true]);
