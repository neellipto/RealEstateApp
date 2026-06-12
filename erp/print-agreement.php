<?php
require_once 'includes/layout.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: agreement-records.php'); exit; }

$agreement = dbFetch("SELECT a.*, c.name AS customer_name, c.phone, c.address, c.nid_number, c.father_name FROM agreements a JOIN customers c ON c.id=a.customer_id WHERE a.id=?", [$id]);
if (!$agreement) { header('Location: agreement-records.php'); exit; }

$emiSchedule = dbFetchAll("SELECT * FROM emi_schedules WHERE agreement_id=? ORDER BY installment_number", [$id]);
$cfg = getSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Agreement <?= h($agreement['agreement_number']) ?></title>
  <style>
    body { font-family: 'Times New Roman', serif; font-size: 12pt; margin: 0; padding: 20px; background: #fff; }
    .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
    .company-name { font-size: 22pt; font-weight: bold; color: #000; }
    .agreement-title { font-size: 16pt; font-weight: bold; text-decoration: underline; margin: 10px 0; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { border: 1px solid #999; padding: 6px 10px; }
    th { background: #f0f0f0; font-weight: bold; }
    .signature-row { margin-top: 40px; }
    .sig-box { border-top: 1px solid #333; width: 200px; padding-top: 5px; text-align: center; font-size: 10pt; }
    .no-print { display: block; margin-bottom: 20px; }
    @media print { .no-print { display: none !important; } body { padding: 10px; font-size: 11pt; } }
    pre { font-family: 'Times New Roman', serif; font-size: 11pt; white-space: pre-wrap; line-height: 1.8; }
  </style>
</head>
<body>

<div class="no-print">
  <button onclick="window.print()" style="background:#0d6efd;color:white;border:none;padding:10px 20px;cursor:pointer;border-radius:6px;font-size:14px">🖨️ Print Agreement</button>
  <a href="agreement-records.php?id=<?= $id ?>" style="margin-left:10px;padding:10px 20px;background:#6c757d;color:white;border-radius:6px;text-decoration:none">← Back</a>
</div>

<div class="header">
  <div class="company-name"><?= h($cfg['company_name'] ?? 'COLORJET Bangladesh') ?></div>
  <div><?= h($cfg['company_address'] ?? 'Dhaka, Bangladesh') ?> | <?= h($cfg['company_phone'] ?? '') ?></div>
  <div class="agreement-title">INSTALLMENT SALE AGREEMENT</div>
  <div><strong>Agreement No:</strong> <?= h($agreement['agreement_number']) ?> | <strong>Date:</strong> <?= formatDate($agreement['date']) ?></div>
</div>

<?php if ($agreement['agreement_text']): ?>
<pre><?= h($agreement['agreement_text']) ?></pre>
<?php else: ?>
<p>Agreement text not available. Please regenerate from the Agreement Generator.</p>
<?php endif; ?>

<?php if ($emiSchedule): ?>
<div style="page-break-inside:avoid;margin-top:20px">
  <h4 style="text-align:center;text-decoration:underline">EMI Payment Schedule</h4>
  <table>
    <thead>
      <tr><th>#</th><th>Due Date</th><th>Amount (৳)</th><th>Cheque Number</th><th>Signature</th></tr>
    </thead>
    <tbody>
      <?php foreach ($emiSchedule as $e): ?>
      <tr>
        <td><?= $e['installment_number'] ?></td>
        <td><?= formatDate($e['due_date']) ?></td>
        <td style="font-weight:bold"><?= money($e['amount']) ?></td>
        <td style="min-width:100px">&nbsp;</td>
        <td style="min-width:100px">&nbsp;</td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="2" style="font-weight:bold">Total</td><td style="font-weight:bold"><?= money(array_sum(array_column($emiSchedule,'amount'))) ?></td><td colspan="2"></td></tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<div class="signature-row" style="margin-top:50px">
  <table style="border:none;width:100%">
    <tr>
      <td style="border:none;width:33%;text-align:center">
        <div class="sig-box">Seller Authorized Representative</div>
        <div style="margin-top:5px;font-size:10pt"><?= h($cfg['company_name'] ?? 'COLORJET Bangladesh') ?></div>
      </td>
      <td style="border:none;width:33%;text-align:center">
        <div class="sig-box">Buyer Signature + Thumbprint</div>
        <div style="margin-top:5px;font-size:10pt"><?= h($agreement['customer_name']) ?></div>
      </td>
      <td style="border:none;width:33%;text-align:center">
        <div class="sig-box">Witness 1</div>
        <div style="margin-top:5px;font-size:10pt"><?= h($agreement['witness1_name'] ?? '') ?></div>
      </td>
    </tr>
  </table>
</div>

<div style="text-align:center;margin-top:30px;font-size:10pt;color:#666">
  <em><?= h($cfg['company_name'] ?? 'COLORJET Bangladesh') ?> — Quality • Commitment • Service | <?= h($cfg['company_website'] ?? '') ?></em>
</div>

</body>
</html>
