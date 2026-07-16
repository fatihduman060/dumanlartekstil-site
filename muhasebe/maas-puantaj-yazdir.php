<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_salary_access();
maas_aylik_kayit_db_ensure();

$employeeId = (int)($_GET['employee_id'] ?? 0);
$period = maas_puantaj_period((string)($_GET['period'] ?? date('Y-m')));
$employee = maas_puantaj_employee($employeeId);
if (!$employee) { http_response_code(404); exit('Personel bulunamadı.'); }

$entries = maas_puantaj_entries($employeeId, $period);
$summary = maas_aylik_kayit_effective_summary($employeeId, $period);
$basis = maas_puantaj_salary_basis($employeeId, $period);
$record = maas_aylik_kayit_record($employeeId, $period);
$monthlySummary = $record && (int)($record['attendance_override_enabled'] ?? 0) === 1;
$statuses = maas_puantaj_statuses();
$days = (int)date('t', strtotime($period . '-01'));
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($employee['full_name']); ?> - Puantaj <?php echo e(month_label($period)); ?></title>
<style>
@page{size:A4 landscape;margin:9mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17251d;margin:0;font-size:10.5px}.toolbar{display:flex;justify-content:flex-end;margin-bottom:9px}.toolbar button{border:0;border-radius:8px;padding:9px 14px;background:#16482e;color:#fff;font-weight:700}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;border-bottom:2px solid #16482e;padding-bottom:9px;margin-bottom:10px}.head h1{margin:0 0 4px;font-size:21px}.head p{margin:2px 0}.period{font-size:17px;font-weight:700;color:#16482e}.rule{margin:0 0 9px;padding:7px 9px;border:1px solid #cbd5cd;border-radius:7px;background:#f5f8f6}.monthly-note{margin:-2px 0 9px;padding:7px 9px;border:1px solid #d9bd79;border-radius:7px;background:#fff8e7;color:#725313}.table-wrap{overflow:hidden}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #aebcaf;text-align:center;padding:4px 1px}th{background:#edf4ef}.day{font-weight:700}.weekend{background:#f8f1e5}.status{font-size:14px;font-weight:900;line-height:1.05}.status small{font-size:8px;font-weight:700;white-space:nowrap}.summary{display:grid;grid-template-columns:repeat(8,1fr);gap:5px;margin-top:10px}.summary div{border:1px solid #cbd5cd;border-radius:7px;padding:7px}.summary span{display:block;color:#5e6e63;font-size:8.5px}.summary b{display:block;margin-top:3px;font-size:13px}.summary .primary{background:#16482e;color:#fff}.summary .primary span,.summary .primary b{color:#fff}.signatures{display:grid;grid-template-columns:1fr 1fr 1fr;gap:40px;margin-top:22px;text-align:center}.signatures div{padding-top:25px;border-top:1px solid #666}@media print{.toolbar{display:none}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Yazdır / PDF</button></div>
<header class="head">
  <div><h1>Puantaj Cetveli</h1><p><strong><?php echo e($employee['full_name']); ?></strong></p><p><?php echo e(trim(($employee['department'] ?? '') . ' / ' . ($employee['position'] ?? ''), ' /') ?: '-'); ?></p></div>
  <div class="period"><?php echo e(month_label($period)); ?></div>
</header>
<div class="rule"><strong>Ücret hesabı:</strong> Aylık maaş ÷ 30 = günlük yevmiye · Günlük yevmiye ÷ 9 = saatlik ücret. Bordro günü: 30 − devamsızlık günü.</div>
<?php if ($monthlySummary): ?><div class="monthly-note"><strong>Toplu aylık kayıt:</strong> Devamsızlık ve eksik saat toplamı Aylık Maaş Kaydı formundan alınmıştır. Günlerin tarih bazında işaretlenmesi için günlük puantaj kullanılabilir.</div><?php endif; ?>
<div class="table-wrap">
<table>
<thead><tr><?php for($d=1;$d<=$days;$d++): $date=sprintf('%s-%02d',$period,$d); $weekDay=(int)date('N',strtotime($date)); ?><th class="<?php echo $weekDay>=6?'weekend':''; ?>"><span class="day"><?php echo $d; ?></span><br><small><?php echo ['','Pzt','Sal','Çar','Per','Cum','Cmt','Paz'][$weekDay]; ?></small></th><?php endfor; ?></tr></thead>
<tbody><tr><?php for($d=1;$d<=$days;$d++): $date=sprintf('%s-%02d',$period,$d); $entry=$entries[$date]??null; $status=$entry['status']??''; $missing=(float)($entry['missing_hours']??0); $overtime=(float)($entry['overtime_hours']??0); ?><td class="status" title="<?php echo e($entry['note']??''); ?>"><?php echo e($statuses[$status]['short']??'-'); ?><?php if($missing>0): ?><br><small>-<?php echo e(number_format($missing,1,',','.')); ?>s</small><?php endif; ?><?php if($overtime>0): ?><br><small>+<?php echo e(number_format($overtime,1,',','.')); ?>s</small><?php endif; ?></td><?php endfor; ?></tr></tbody>
</table>
</div>
<div class="summary">
  <div class="primary"><span>Bordro günü</span><b><?php echo e(number_format((float)$summary['paid_days'], 0, ',', '.')); ?> gün</b></div>
  <div><span>Devamsızlık</span><b><?php echo e(number_format((float)$summary['absent_days'], 0, ',', '.')); ?> gün</b></div>
  <div><span>Eksik / geç giriş</span><b><?php echo e(number_format((float)$summary['missing_hours'],1,',','.')); ?> saat</b></div>
  <div><span>Aylık maaş</span><b><?php echo e(money($basis['base_salary'])); ?></b></div>
  <div><span>Günlük yevmiye</span><b><?php echo e(money($basis['daily_rate'])); ?></b></div>
  <div><span>Saatlik ücret</span><b><?php echo e(money($basis['hourly_rate'])); ?></b></div>
  <div><span>Çalıştı</span><b><?php echo e(number_format((float)$summary['work_days'],0,',','.')); ?> gün</b></div>
  <div><span>Fazla mesai</span><b><?php echo e(number_format((float)$summary['overtime_hours'],1,',','.')); ?> saat</b></div>
</div>
<div class="signatures"><div>Personel</div><div>Bölüm sorumlusu</div><div>İşveren / Yetkili</div></div>
</body>
</html>
