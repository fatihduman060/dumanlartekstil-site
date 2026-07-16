<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_salary_access();
maas_aylik_kayit_db_ensure();

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

$dailyRate = (float)$row['base_salary'] / 30;
$hourlyRate = $dailyRate / 9;
$advanceRows = maas_avans_period_rows((string)$row['period'], (int)$row['employee_id']);
$advanceTotal = array_reduce($advanceRows, fn($sum, $advance) => $sum + (float)$advance['amount'], 0.0);
$totalDeductions = (float)$row['absence_deduction_amount']
    + (float)($row['hour_deduction_amount'] ?? 0)
    + (float)($row['garnishment_amount'] ?? 0)
    + (float)$row['other_deduction_amount']
    + $advanceTotal;
$statusLabel = ['bekliyor'=>'Bekliyor','kismi'=>'Kısmi ödendi','odendi'=>'Ödendi'][$row['status'] ?? ''] ?? ($row['status'] ?? '-');
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($row['full_name']); ?> - Bordro <?php echo e(month_label($row['period'])); ?></title>
<style>
@page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17251d;margin:0;font-size:11.5px}.toolbar{display:flex;justify-content:flex-end;margin-bottom:10px}.toolbar button{border:0;border-radius:8px;padding:9px 14px;background:#16482e;color:#fff;font-weight:700}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;border-bottom:2px solid #16482e;padding-bottom:10px}.head h1{margin:0 0 5px;font-size:23px}.period{font-size:18px;font-weight:700;color:#16482e}.employee{display:grid;grid-template-columns:1fr 1fr;gap:7px 18px;margin:13px 0;padding:11px;border:1px solid #ced9d0;border-radius:9px}.employee div{display:grid;grid-template-columns:115px 1fr}.employee span{color:#617067}.rates{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}.rates div{padding:9px;border:1px solid #cbd5cd;border-radius:8px;background:#f7faf8}.rates span{display:block;color:#617067;font-size:9px}.rates b{display:block;margin-top:3px}.columns{display:grid;grid-template-columns:1fr 1fr;gap:12px}.box{border:1px solid #cbd5cd;border-radius:9px;overflow:hidden}.box h2{margin:0;padding:9px 11px;background:#edf4ef;color:#16482e;font-size:14px}.row{display:flex;justify-content:space-between;gap:12px;padding:8px 11px;border-top:1px solid #e3e9e4}.row:first-of-type{border-top:0}.row.total{background:#f8f1e5;font-size:14px;font-weight:700}.row.garnishment{background:#fff8e7}.row.advance{background:#eef4ff}.net{margin-top:12px;padding:15px;border-radius:10px;background:#16482e;color:#fff;display:flex;justify-content:space-between;align-items:center}.net span{font-size:13px}.net strong{font-size:23px}.payment{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:11px}.payment div{border:1px solid #cbd5cd;border-radius:8px;padding:9px}.payment span{display:block;color:#617067;font-size:9px}.payment b{display:block;margin-top:4px}.advance-detail{margin-top:11px;border:1px solid #b8c8da;border-radius:9px;overflow:hidden}.advance-detail h2{margin:0;padding:8px 10px;background:#eef4ff;color:#254f85;font-size:13px}.advance-detail table{width:100%;border-collapse:collapse}.advance-detail th,.advance-detail td{padding:6px 9px;border-top:1px solid #dce5ef;text-align:left}.advance-detail th{font-size:9px;color:#617067}.advance-detail td:last-child,.advance-detail th:last-child{text-align:right}.advance-detail tfoot td{font-weight:700;background:#f7faff}.note{margin-top:11px;border:1px solid #cbd5cd;border-radius:8px;padding:9px;min-height:44px}.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-top:12px}.summary div{padding:8px;border:1px solid #cbd5cd;border-radius:8px}.summary span{display:block;color:#617067;font-size:9px}.summary b{display:block;margin-top:4px}.summary .primary{background:#16482e;color:#fff}.summary .primary span,.summary .primary b{color:#fff}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:70px;margin-top:42px;text-align:center}.signatures div{padding-top:28px;border-top:1px solid #555}@media print{.toolbar{display:none}}
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
<div class="rates">
  <div><span>Aylık maaş</span><b><?php echo e(money($row['base_salary'])); ?></b></div>
  <div><span>Günlük yevmiye (maaş ÷ 30)</span><b><?php echo e(money($dailyRate)); ?></b></div>
  <div><span>Saatlik ücret (yevmiye ÷ 9)</span><b><?php echo e(money($hourlyRate)); ?></b></div>
</div>
<div class="columns">
  <section class="box"><h2>Kazançlar</h2>
    <div class="row"><span>Aylık ücret</span><b><?php echo e(money($row['base_salary'])); ?></b></div>
    <div class="row"><span>Fazla mesai</span><b><?php echo e(money($row['overtime_amount'])); ?></b></div>
    <div class="row"><span>Prim</span><b><?php echo e(money($row['bonus_amount'])); ?></b></div>
    <div class="row"><span>Diğer ek ödeme</span><b><?php echo e(money($row['other_addition_amount'])); ?></b></div>
    <div class="row total"><span>Brüt hakediş</span><b><?php echo e(money($row['gross_earning'])); ?></b></div>
  </section>
  <section class="box"><h2>Kesintiler</h2>
    <div class="row"><span>Devamsızlık kesintisi</span><b><?php echo e(money($row['absence_deduction_amount'])); ?></b></div>
    <div class="row"><span>Eksik saat kesintisi</span><b><?php echo e(money($row['hour_deduction_amount'] ?? 0)); ?></b></div>
    <div class="row garnishment"><span>Haciz kesintisi</span><b><?php echo e(money($row['garnishment_amount'] ?? 0)); ?></b></div>
    <div class="row"><span>Diğer kesinti</span><b><?php echo e(money($row['other_deduction_amount'])); ?></b></div>
    <div class="row advance"><span>Ay içi avans toplamı</span><b><?php echo e(money($advanceTotal)); ?></b></div>
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
<?php if ($advanceRows): ?>
<section class="advance-detail"><h2>Ay içi avans hareketleri</h2><table><thead><tr><th>Tarih</th><th>Hesap / Açıklama</th><th>Tutar</th></tr></thead><tbody>
<?php foreach ($advanceRows as $advance): ?><tr><td><?php echo e(tr_date($advance['advance_date'])); ?></td><td><?php echo e(trim(($advance['account_name'] ?? '') . ' ' . ($advance['bank_name'] ?? '')) ?: 'Sadece kayıt'); ?><?php if (!empty($advance['note'])): ?> · <?php echo e($advance['note']); ?><?php endif; ?></td><td><?php echo e(money($advance['amount'])); ?></td></tr><?php endforeach; ?>
</tbody><tfoot><tr><td colspan="2">Avans toplamı</td><td><?php echo e(money($advanceTotal)); ?></td></tr></tfoot></table></section>
<?php endif; ?>
<div class="summary">
  <div class="primary"><span>Bordro günü</span><b><?php echo e(number_format((float)($row['paid_days'] ?? (30-(float)$row['absent_days'])),0,',','.')); ?> gün</b></div>
  <div><span>Devamsızlık</span><b><?php echo e(number_format((float)$row['absent_days'],0,',','.')); ?> gün</b></div>
  <div><span>Eksik / geç giriş</span><b><?php echo e(number_format((float)($row['missing_hours'] ?? 0),1,',','.')); ?> saat</b></div>
  <div><span>İzin / Rapor</span><b><?php echo e(number_format((float)$row['paid_leave_days'] + (float)$row['report_days'],0,',','.')); ?> gün</b></div>
  <div><span>Tatil</span><b><?php echo e(number_format((float)$row['weekly_off_days'] + (float)$row['holiday_days'],0,',','.')); ?> gün</b></div>
</div>
<div class="note"><strong>Açıklama:</strong> <?php echo nl2br(e($row['note'] ?: '-')); ?></div>
<div class="signatures"><div>Personel imzası</div><div>İşveren / Yetkili</div></div>
</body>
</html>
