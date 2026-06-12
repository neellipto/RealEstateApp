<?php
require_once 'includes/layout.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: invoices.php'); exit; }

$invoice = dbFetch("SELECT i.*, c.name AS customer_name, c.phone, c.address, c.email FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=?", [$id]);
if (!$invoice) { header('Location: invoices.php'); exit; }

$items = dbFetchAll("SELECT ii.*, p.name AS product_name FROM invoice_items ii LEFT JOIN products p ON p.id=ii.product_id WHERE ii.invoice_id=?", [$id]);
$cfg   = getSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Invoice <?= h($invoice['invoice_number']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    body { font-family: 'Times New Roman', serif; font-size: 12pt; background: #fff; }
    .header { border-bottom: 3px solid #0d6efd; padding-bottom: 15px; margin-bottom: 20px; }
    .company-name { font-size: 26pt; font-weight: bold; color: #0d6efd; }
    .invoice-title { font-size: 20pt; color: #333; text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #0d6efd; color: #fff; padding: 8px; text-align: left; }
    td { padding: 7px 8px; border-bottom: 1px solid #eee; }
    .total-row { background: #f0f7ff; font-weight: bold; }
    .grand-total { background: #0d6efd; color: #fff; font-size: 14pt; }
    .footer { margin-top: 40px; border-top: 1px solid #ccc; padding-top: 15px; font-size: 10pt; color: #666; }
    .no-print { display: none; }
    @media screen { .no-print { display: block; } body { padding: 20px; max-width: 800px; margin: 0 auto; } }
    @media print { .no-print { display: none !important; } body { padding: 0; font-size: 11pt; } }
  </style>
</head>
<body>

<div class="no-print mb-3">
  <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print me-2"></i>Print Invoice</button>
  <a href="invoices.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Back</a>
</div>

<div class="header">
  <div class="row">
    <div class="col-7">
      <div class="company-name"><?= h($cfg['company_name'] ?? 'COLORJET Bangladesh') ?></div>
      <div><?= nl2br(h($cfg['company_address'] ?? 'Dhaka, Bangladesh')) ?></div>
      <div>Phone: <?= h($cfg['company_phone'] ?? '') ?> | Email: <?= h($cfg['company_email'] ?? '') ?></div>
      <div>Website: <?= h($cfg['company_website'] ?? '') ?></div>
    </div>
    <div class="col-5 text-end">
      <div class="invoice-title">INVOICE</div>
      <div><strong>#<?= h($invoice['invoice_number']) ?></strong></div>
      <div>Date: <?= formatDate($invoice['date']) ?></div>
      <?php if ($invoice['due_date']): ?><div>Due: <?= formatDate($invoice['due_date']) ?></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="row mb-4">
  <div class="col-6">
    <strong>Bill To:</strong>
    <div><?= h($invoice['customer_name']) ?></div>
    <div><?= h($invoice['phone']) ?></div>
    <?php if ($invoice['email']): ?><div><?= h($invoice['email']) ?></div><?php endif; ?>
    <?php if ($invoice['address']): ?><div><?= h($invoice['address']) ?></div><?php endif; ?>
  </div>
  <div class="col-6 text-end">
    <div class="mt-3">
      <strong>Status:</strong> <?= strtoupper($invoice['status']) ?>
    </div>
  </div>
</div>

<table>
  <thead>
    <tr><th>#</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th>Total</th></tr>
  </thead>
  <tbody>
    <?php foreach ($items as $i => $item): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= h($item['product_name'] ?: $item['description']) ?></td>
      <td><?= $item['quantity'] ?></td>
      <td><?= money($item['unit_price']) ?></td>
      <td><?= $item['discount'] > 0 ? money($item['discount']) : '—' ?></td>
      <td><?= money($item['total']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="total-row"><td colspan="5" class="text-end">Subtotal</td><td><?= money($invoice['subtotal']) ?></td></tr>
    <?php if ($invoice['discount'] > 0): ?><tr class="total-row"><td colspan="5" class="text-end">Discount</td><td>-<?= money($invoice['discount']) ?></td></tr><?php endif; ?>
    <?php if ($invoice['tax'] > 0): ?><tr class="total-row"><td colspan="5" class="text-end">Tax</td><td><?= money($invoice['tax']) ?></td></tr><?php endif; ?>
    <tr class="grand-total"><td colspan="5" class="text-end">TOTAL</td><td><?= money($invoice['total']) ?></td></tr>
    <tr><td colspan="5" class="text-end">Paid Amount</td><td class="text-success"><?= money($invoice['paid_amount']) ?></td></tr>
    <tr style="color:<?= $invoice['due_amount']>0?'red':'green' ?>;font-weight:bold"><td colspan="5" class="text-end">Amount Due</td><td><?= money($invoice['due_amount']) ?></td></tr>
  </tfoot>
</table>

<?php if ($invoice['notes']): ?>
<div class="mt-3"><strong>Notes:</strong> <?= h($invoice['notes']) ?></div>
<?php endif; ?>

<div class="footer">
  <div class="row">
    <div class="col-6">
      <div class="mt-4">Customer Signature: _______________________</div>
    </div>
    <div class="col-6 text-end">
      <div class="mt-4">Authorized Signature: _______________________</div>
    </div>
  </div>
  <div class="text-center mt-3">
    <em>Thank you for your business! — <?= h($cfg['company_name'] ?? 'COLORJET Bangladesh') ?></em>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/js/all.min.js"></script>
</body>
</html>
