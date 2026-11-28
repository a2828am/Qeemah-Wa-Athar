<?php
/**
 * charity/donations_manage.php — claim in-kind donations, follow their
 * lifecycle (offered → claimed → received → distributed) and issue receipts.
 *
 *   ?tab=available (default) | claimed | receipts
 *   ?receipt=ID   printable receipt
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/components.php';

$assoc   = Gate::requireAssociation();
$assocId = (int)$assoc['id'];
$perms   = Auth::memberPerms();
$me      = Auth::user();
$tab     = get_q('tab', 'available') ?? 'available';
$receiptId = get_int('receipt', 0) ?? 0;

/* ── printable receipt ───────────────────────────────────────────────────── */
if ($receiptId) {
    $receipt = ReceiptRepo::find($receiptId);
    if (!$receipt || (int)$receipt['association_id'] !== $assocId) {
        http_response_code(404);
        flash('error', 'الإيصال غير موجود.');
        redirect('charity/donations_manage.php?tab=receipts');
    }
    $pageTitle   = 'إيصال ' . (string)$receipt['receipt_no'] . ' | قيمة وأثر';
    $currentPage = 'charity_panel';
    $hideTopbar  = true;
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="container section" style="padding-top:26px">
      <div class="panel" style="max-width:760px;margin:0 auto">
        <div class="flex justify-between items-start gap-16 flex-wrap">
          <div class="flex items-center gap-8">
            <span class="logo-mark" style="width:40px;height:40px"><?= icon('leaf', 20) ?></span>
            <div>
              <b style="font-size:17px;display:block"><?= e((string)$receipt['association_name']) ?></b>
              <span class="text-xs text-muted">إيصال استلام تبرّع عيني</span>
            </div>
          </div>
          <div style="text-align:end">
            <b style="font-size:15px;display:block"><?= e((string)$receipt['receipt_no']) ?></b>
            <span class="text-xs text-muted"><?= e((string)$receipt['issued_at']) ?></span>
          </div>
        </div>

        <div class="kv mt-24" style="margin-top:24px">
          <div class="kv-row"><span>المتبرّع</span><b><?= e((string)$receipt['donor_name']) ?></b></div>
          <div class="kv-row"><span>التبرّع</span><b><?= e((string)$receipt['donation_title']) ?></b></div>
          <div class="kv-row"><span>الوصف</span><b><?= e((string)$receipt['items_summary']) ?></b></div>
          <div class="kv-row"><span>الكمية</span><b><?= (int)$receipt['quantity'] > 0 ? (int)$receipt['quantity'] : '—' ?></b></div>
          <?php if (!empty($receipt['beneficiary_ref'])): ?>
            <div class="kv-row"><span>مرجع المستفيد</span><b><?= e((string)$receipt['beneficiary_ref']) ?></b></div>
          <?php endif; ?>
          <div class="kv-row"><span>أصدره</span><b><?= e((string)$receipt['issuer_name']) ?></b></div>
        </div>

        <?php if (!empty($receipt['signature_note'])): ?>
          <div class="trust-note mt-16" style="margin-top:16px"><?= icon('edit', 15) ?><span><?= e((string)$receipt['signature_note']) ?></span></div>
        <?php endif; ?>

        <div class="flex gap-8 mt-24" style="margin-top:24px">
          <button class="btn btn-outline btn-sm" type="button" onclick="window.print()"><?= icon('printer', 15) ?>طباعة</button>
          <a class="btn btn-ghost btn-sm" href="<?= e(url('charity/donations_manage.php?tab=receipts')) ?>"><?= icon('chevron-right', 15) ?>رجوع للإيصالات</a>
        </div>
      </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

/* ── post actions: status changes + receipt issuing ──────────────────────── */
$errors = [];
$receiptDonation = null;
$issueFor = get_int('issue', 0) ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    Gate::requireMemberCapability('donations');
    $action = (string)post('action', '');

    try {
        if ($action === 'claim') {
            DonationRepo::claim((int)post_int('donation_id', 0), $assocId, (int)$me['id']);
            flash('success', 'تم استلام التبرّع باسم جمعيتك. يمكنك الآن إصدار إيصال.');
            redirect('charity/donations_manage.php?tab=claimed');
        }
        if ($action === 'receive') {
            DonationRepo::markReceived((int)post_int('donation_id', 0), $assocId);
            flash('success', 'تم تسجيل وصول التبرّع.');
            redirect('charity/donations_manage.php?tab=claimed');
        }
        if ($action === 'distribute') {
            DonationRepo::markDistributed((int)post_int('donation_id', 0), $assocId);
            flash('success', 'تم تسجيل توزيع التبرّع على المستفيدين.');
            redirect('charity/donations_manage.php?tab=claimed');
        }
        if ($action === 'issue_receipt') {
            $res = ReceiptRepo::issue((int)post_int('donation_id', 0), $assocId, (int)$me['id'], [
                'items_summary'  => (string)post('items_summary', ''),
                'quantity'       => post_int('quantity'),
                'beneficiary_id' => post_int('beneficiary_id'),
                'beneficiary_ref'=> post('beneficiary_ref'),
                'signature_note' => post('signature_note'),
            ]);
            flash('success', 'تم إصدار الإيصال ' . $res['receipt_no'] . ' بنجاح.');
            redirect('charity/donations_manage.php?receipt=' . (int)$res['id']);
        }
        if ($action === 'link_campaign') {
            CampaignRepo::attachDonation((int)post_int('campaign_id', 0), (int)post_int('donation_id', 0), (int)$me['id']);
            flash('success', 'تم ربط التبرّع بالحملة وتحديث عدّاد المشاركة.');
            redirect('charity/donations_manage.php?tab=claimed');
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

if ($issueFor) {
    $receiptDonation = DonationRepo::find($issueFor);
    if (!$receiptDonation || (int)$receiptDonation['association_id'] !== $assocId) {
        $receiptDonation = null;
    }
}

$available = DonationRepo::availableForAssociation($assocId, 1, 40)[0];
$claimed   = DonationRepo::claimedByAssociation($assocId);
$receipts  = ReceiptRepo::forAssociation($assocId);
$campaigns = CampaignRepo::forAssociation($assocId, 'active');
$beneficiaries = BeneficiaryRepo::forAssociation($assocId, 'active');

$pageTitle   = 'إدارة التبرّعات | قيمة وأثر';
$currentPage = 'charity_panel';
$pageHead    = [
    'title'    => icon('hand-heart', 28) . ' إدارة التبرّعات',
    'subtitle' => 'استلم التبرّعات العينية، وثّق التوزيع، وأصدر الإيصالات.',
    'actions'  => '<a class="btn btn-outline btn-sm" href="' . e(url('charity/beneficiaries.php')) . '">' . icon('users', 15) . 'المستفيدون</a>',
];
require __DIR__ . '/../includes/header.php';
?>

<div class="container section" style="padding-top:24px">
  <?php if (!$perms['donations']): ?>
    <div class="alert alert-warning"><?= icon('lock', 18) ?><div class="grow">دورك الحالي لا يمنحك صلاحية استلام التبرّعات. يمكنك التصفّح فقط.</div></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error"><?= icon('alert-circle', 18) ?><div class="grow"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div></div>
  <?php endif; ?>

  <?php if ($receiptDonation): ?>
    <div class="panel mb-24">
      <h3><?= icon('receipt', 18) ?> إصدار إيصال للتبرّع «<?= e(excerpt((string)$receiptDonation['title'], 50)) ?>»</h3>
      <form method="post" class="mt-16">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="issue_receipt">
        <input type="hidden" name="donation_id" value="<?= (int)$receiptDonation['id'] ?>">
        <div class="form-grid">
          <div class="field full">
            <label for="items_summary">وصف العناصر المستلمة <i>*</i></label>
            <input type="text" id="items_summary" name="items_summary" required value="<?= e(excerpt((string)$receiptDonation['title'], 120)) ?>">
          </div>
          <div class="field">
            <label for="quantity">الكمية</label>
            <input type="number" id="quantity" name="quantity" min="1" value="1">
          </div>
          <div class="field">
            <label for="beneficiary_id">تسليم لمستفيد (اختياري)</label>
            <select id="beneficiary_id" name="beneficiary_id">
              <option value="">— تسجيل بالتوزيع لاحقاً —</option>
              <?php foreach ($beneficiaries as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= e((string)$b['code']) ?> — <?= e((string)$b['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="hint">اختيار مستفيد يسجّل عملية توزيع ويربطها بالإيصال تلقائياً.</span>
          </div>
          <div class="field full">
            <label for="signature_note">ملاحظة التوقيع</label>
            <input type="text" id="signature_note" name="signature_note" placeholder="اسم المستلم وتوقيعه / ملاحظة إضافية">
          </div>
        </div>
        <div class="form-actions">
          <a class="btn btn-ghost" href="<?= e(url('charity/donations_manage.php?tab=claimed')) ?>">إلغاء</a>
          <button class="btn btn-primary" type="submit"><?= icon('receipt', 16) ?>إصدار الإيصال</button>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div class="dash-tabs">
    <a class="dash-tab<?= $tab === 'available' ? ' active' : '' ?>" href="<?= e(url('charity/donations_manage.php?tab=available')) ?>">
      <?= icon('gift', 15) ?>متاحة للاستلام <span class="badge badge-soft"><?= count($available) ?></span>
    </a>
    <a class="dash-tab<?= $tab === 'claimed' ? ' active' : '' ?>" href="<?= e(url('charity/donations_manage.php?tab=claimed')) ?>">
      <?= icon('hand-heart', 15) ?>تبرّعات جمعيتي <span class="badge badge-soft"><?= count($claimed) ?></span>
    </a>
    <a class="dash-tab<?= $tab === 'receipts' ? ' active' : '' ?>" href="<?= e(url('charity/donations_manage.php?tab=receipts')) ?>">
      <?= icon('receipt', 15) ?>الإيصالات <span class="badge badge-soft"><?= count($receipts) ?></span>
    </a>
  </div>

  <?php if ($tab === 'available'): ?>
    <?php if (!$available): ?>
      <div class="panel"><?= empty_state('لا توجد تبرّعات متاحة الآن', 'التبرّعات العامة والتبرّعات الموجّهة لجمعيتك تظهر هنا فور إضافتها.', 'gift') ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>التبرّع</th><th>المتبرّع</th><th>النطاق</th><th>أُضيف</th><th>إجراء</th></tr></thead>
          <tbody>
            <?php foreach ($available as $d): ?>
              <tr>
                <td>
                  <span class="table-title"><?= e(excerpt((string)$d['title'], 56)) ?></span>
                  <span class="table-sub">#<?= (int)$d['id'] ?> · <?= e((string)$d['kind'] === 'in_kind' ? 'عيني' : (string)$d['kind']) ?></span>
                </td>
                <td><?= e((string)$d['donor_name']) ?></td>
                <td>
                  <?php if (($d['donation_scope'] ?? 'general') === 'targeted'): ?>
                    <span class="badge badge-donate"><?= icon('target', 12) ?>موجّه لجمعيتك</span>
                  <?php else: ?>
                    <span class="badge badge-soft"><?= icon('hand-heart', 12) ?>عام</span>
                  <?php endif; ?>
                </td>
                <td><span class="table-sub"><?= e(time_ago((string)$d['created_at'])) ?></span></td>
                <td>
                  <?php if ($perms['donations']): ?>
                    <form method="post" data-ajax="api/claim_donation.php" data-confirm="استلام هذا التبرّع؟" data-done="reload">
                      <?= csrf_field() ?>
                      <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                      <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 14) ?>استلام</button>
                    </form>
                  <?php else: ?>
                    <span class="badge badge-muted"><?= icon('lock', 12) ?>—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'claimed'): ?>
    <?php if (!$claimed): ?>
      <div class="panel"><?= empty_state('لم تستلم الجمعية تبرّعات بعد', 'ابدأ من تبويب «متاحة للاستلام».', 'hand-heart') ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>التبرّع</th><th>المتبرّع</th><th>الحالة</th><th>الاستلام</th><th>إجراءات</th></tr></thead>
          <tbody>
            <?php foreach ($claimed as $d): ?>
              <tr>
                <td>
                  <span class="table-title"><?= e(excerpt((string)$d['title'], 50)) ?></span>
                  <span class="table-sub">#<?= (int)$d['id'] ?></span>
                </td>
                <td><?= e((string)$d['donor_name']) ?></td>
                <td><?= donation_status((string)$d['status']) ?></td>
                <td><span class="table-sub"><?= e(time_ago((string)$d['claimed_at'] ?? null)) ?></span></td>
                <td>
                  <div class="row-actions">
                    <?php if ($perms['donations']): ?>
                      <?php if ($d['status'] === 'claimed'): ?>
                        <form method="post">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="receive">
                          <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                          <button class="icon-btn" type="submit"><?= icon('check-circle', 14) ?>وصل</button>
                        </form>
                      <?php endif; ?>
                      <?php if (in_array((string)$d['status'], ['claimed', 'received'], true)): ?>
                        <a class="icon-btn" href="<?= e(url('charity/donations_manage.php?issue=' . (int)$d['id'])) ?>"><?= icon('receipt', 14) ?>إيصال</a>
                      <?php endif; ?>
                      <?php if ($d['status'] === 'received'): ?>
                        <form method="post">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="distribute">
                          <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                          <button class="icon-btn" type="submit"><?= icon('package', 14) ?>توزيع</button>
                        </form>
                      <?php endif; ?>
                      <?php if (!$d['campaign_id'] && $campaigns): ?>
                        <form method="post" class="flex gap-6">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="link_campaign">
                          <input type="hidden" name="donation_id" value="<?= (int)$d['id'] ?>">
                          <select name="campaign_id" required style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:12.5px">
                            <option value="">ربط بحملة…</option>
                            <?php foreach ($campaigns as $c): ?>
                              <option value="<?= (int)$c['id'] ?>"><?= e(excerpt((string)$c['title'], 30)) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="icon-btn" type="submit"><?= icon('hash', 13) ?>ربط</button>
                        </form>
                      <?php elseif ($d['campaign_id']): ?>
                        <span class="badge badge-soft"><?= icon('megaphone', 12) ?>مرتبط بحملة</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php if (!$receipts): ?>
      <div class="panel"><?= empty_state('لا توجد إيصالات بعد', 'أصدر إيصالاً بعد استلام أي تبرّع لتوثيق العملية.', 'receipt') ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>رقم الإيصال</th><th>التبرّع</th><th>العناصر</th><th>أصدره</th><th>التاريخ</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($receipts as $r): ?>
              <tr>
                <td><span class="table-title" dir="ltr"><?= e((string)$r['receipt_no']) ?></span></td>
                <td><?= e(excerpt((string)$r['donation_title'], 44)) ?></td>
                <td><span class="table-sub"><?= e(excerpt((string)$r['items_summary'], 44)) ?></span></td>
                <td><?= e((string)$r['issuer_name']) ?></td>
                <td><span class="table-sub"><?= e((string)$r['issued_at']) ?></span></td>
                <td><a class="icon-btn" href="<?= e(url('charity/donations_manage.php?receipt=' . (int)$r['id'])) ?>"><?= icon('printer', 13) ?>عرض/طباعة</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
