<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_admin();
maas_puantaj_db_ensure();

$payrollId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT spd.*, se.full_name, se.department, se.position, se.phone,
    sr.paid_amount, sr.remaining_amount, sr.payment_date, sr.status, a.name AS account_name, a.bank_name
    FROM salary_payroll_details spd
    JOIN salary_employees se ON se.id=spd.employee_id
    LEFT JOIN salary_records sr ON sr.id=spd.salary_record_id
    LEFT JOIN accounts a ON a.id=sr.account_id
    WHERE spd.id=? LIMIT 1");
$stmt->execute([$payrollId]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); exit('Bordro bulunamadı.'); }

$totalDeductions = (float)$row['absence_deduction_amount'] + (float)$row['other_deduction_amount'] + (float)$row['advance_amount'];
$statusLabel = ['bekliyor'=>'Bekliyor','kismi'=>'Kısmi ödendi','odendi'=>'Ödendi'][$row['status'] ?? ''] ?? ($row['status'] ?? '-');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($row['full_name']); ?> - Bordro <?php echo e(month_label($row['period'])); ?></title>
<style>
@page{size:A4 portrait;margin:13mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17251d;margin:0;font-size:12px}.toolbar{display:flex;justify-content:flex-end;margin-bottom:12px}.toolbar button{border:0;border-radius:8px;padding:9px 14px;background:#16482e;color:#fff;font-weight:700}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;border-bottom:2px solid #16482e;padding-bottom:12px}.head h1{margin:0 0 5px;font-size:24px}.period{font-size:19px;font-weight:700;color:#16482e}.employee{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px;margin:15px 0;padding:12px;border:1px solid #ced9d0;border-radius:9px}.employee div{display:grid;grid-template-columns:120px 1fr}.employee span{color:#617067}.columns{display:grid;grid-template-columns:1fr 1fr;gap:14px}.box{border:1px solid #cbd5cd;border-radius:9px;overflow:hidden}.box h2{margin:0;padding:10px 12px;background:#edf4ef;color:#16482e;font-size:14px}.row{display:flex;justify-content:space-between;gap:12px;padding:9px 12px;border-top:1px solid #e3e9e4}.row:first-of-type{border-top:0}.row.total{background:#f8f1e5;font-size:14px;font-weight:700}.net{margin-top:14px;padding:17px;border-radius:10px;background:#16482e;color:#fff;display:flex;justify-content:space-between;align-items:center}.net span{font-size:13px}.net strong{font-size:24px}.payment{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:12px}.payment div{border:1px solid #cbd5cd;border-radius:8px;padding:10px}.payment span{display:block;color:#617067;font-size:10px}.payment b{display:block;margin-top:4px}.note{margin-top:12px;border:1px solid #cbd5cd;border-radius:8px;padding:10px;min-height:48px}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-top:14px}.summary div{padding:9px;border:1px solid #cbd5cd;border-radius:8px}.summary span{display:block;color:#617067;font-size:10px}.summary b{display:block;margin-top:4px}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:70px;margin-top:48px;text-align:center}.signatures div{padding-top:32px;border-top:1px solid #555}@media print{.toolbar{display:none}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Yazdır / PDF</button></div>
<header class="head"><div><h1>Maaş Bordrosu</h1><div>Dumanlar Tekstil · Şirket içi bordro</div></div><div class="period"><?php echo e(month_label($row['period'])); ?></div></header>
<section class="employee">
  <div><span>Personel</span><b><?php echo e($row['full_name']); ?></b></div>
  <div><span>Bölüm / Görev</span><b><?php echo e(trim(($row['department'] ?? '') . ' / ' . ($row['position'] ?? ''), ' /') ?: '-'); ?></b></div>
  <div><span>Telefon</span><b><?php echo e($row['phone'] ?: '-'); ?></b></div>
  <div><span>Puantaj dönemi</span><b><?php echo e(month_label($row['period'])); ?></b></div>
</section>
<div class="columns">
  <section class="box"><h2>Kazançlar</h2>
    <div class="row"><span>Aylık ücret</span><b><?php echo e(money($row['base_salary'])); ?></b></div>
    <div class="row"><span>Fazla mesai</span><b><?php echo e(money($row['overtime_amount'])); ?></b></div>
    <div class="row"><span>Prim</span><b><?php echo e(money($row['bonus_amount'])); ?></b></div>
    <div class="row"><span>Diğer ek ödeme</span><b><?php echo e(money($row['other_addition_amount'])); ?></b></div>
    <div class="row total"><span>Brüt hakediş</span><b><?php echo e(money($row['gross_earning'])); ?></b></div>
  </section>
  <section class="box"><h2>Kesintiler</h2>
    <div class="row"><span>Eksik gün kesintisi</span><b><?php echo e(money($row['absence_deduction_amount'])); ?></b></div>
    <div class="row"><span>Diğer kesinti</span><b><?php echo e(money($row['other_deduction_amount'])); ?></b></div>
    <div class="row"><span>Avans</span><b><?php echo e(money($row['advance_amount'])); ?></b></div>
    <div class="row total"><span>Toplam kesinti</span><b><?php echo e(money($totalDeductions)); ?></b></div>
  </section>
</div>
<div class="net"><span>Net ödenecek</span><strong><?php echo e(money($row['net_payable'])); ?></strong></div>
<div class="payment">
  <div><span>Ödenen</span><b><?php echo e(money($row['paid_amount'] ?? 0)); ?></b></div>
  <div><span>Kalan</span><b><?php echo e(money($row['remaining_amount'] ?? $row['net_payable'])); ?></b></div>
  <div><span>Durum</span><b><?php echo e($statusLabel); ?></b></div>
  <div><span>Ödeme tarihi</span><b><?php echo e(tr_date($row['payment_date'] ?? null)); ?></b></div>
  <div><span>Ödeme hesabı</span><b><?php echo e(trim(($row['account_name'] ?? '') . ' ' . ($row['bank_name'] ?? '')) ?: '-'); ?></b></div>
  <div><span>Fazla mesai süresi</span><b><?php echo e(number_format((float)$row['overtime_hours'],1,',','.')); ?> saat</b></div>
</div>
<div class="summary">
  <div><span>Çalıştı</span><b><?php echo e($row['work_days']); ?> gün</b></div>
  <div><span>İzin / Rapor</span><b><?php echo e((float)$row['paid_leave_days'] + (float)$row['report_days']); ?> gün</b></div>
  <div><span>Gelmedi</span><b><?php echo e($row['absent_days']); ?> gün</b></div>
  <div><span>Tatil</span><b><?php echo e((float)$row['weekly_off_days'] + (float)$row['holiday_days']); ?> gün</b></div>
</div>
<div class="note"><strong>Açıklama:</strong> <?php echo nl2br(e($row['note'] ?: '-')); ?></div>
<div class="signatures"><div>Personel imzası</div><div>İşveren / Yetkili</div></div>
</body>
</html>
