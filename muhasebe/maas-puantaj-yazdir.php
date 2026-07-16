<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_admin();
maas_puantaj_db_ensure();

$employeeId = (int)($_GET['employee_id'] ?? 0);
$period = maas_puantaj_period((string)($_GET['period'] ?? date('Y-m')));
$employee = maas_puantaj_employee($employeeId);
if (!$employee) { http_response_code(404); exit('Personel bulunamadı.'); }

$entries = maas_puantaj_entries($employeeId, $period);
$summary = maas_puantaj_summary($employeeId, $period);
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
@page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#17251d;margin:0;font-size:11px}.toolbar{display:flex;justify-content:flex-end;margin-bottom:10px}.toolbar button{border:0;border-radius:8px;padding:9px 14px;background:#16482e;color:#fff;font-weight:700}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;border-bottom:2px solid #16482e;padding-bottom:10px;margin-bottom:12px}.head h1{margin:0 0 5px;font-size:22px}.head p{margin:2px 0}.period{font-size:18px;font-weight:700;color:#16482e}.table-wrap{overflow:hidden}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #aebcaf;text-align:center;padding:5px 2px}th{background:#edf4ef}.day{font-weight:700}.weekend{background:#f8f1e5}.status{font-size:15px;font-weight:900}.note-row td{text-align:left;height:34px}.summary{display:grid;grid-template-columns:repeat(8,1fr);gap:6px;margin-top:12px}.summary div{border:1px solid #cbd5cd;border-radius:7px;padding:8px}.summary span{display:block;color:#5e6e63;font-size:9px}.summary b{display:block;margin-top:3px;font-size:14px}.signatures{display:grid;grid-template-columns:1fr 1fr 1fr;gap:40px;margin-top:26px;text-align:center}.signatures div{padding-top:28px;border-top:1px solid #666}@media print{.toolbar{display:none}}
</style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Yazdır / PDF</button></div>
<header class="head">
  <div><h1>Puantaj Cetveli</h1><p><strong><?php echo e($employee['full_name']); ?></strong></p><p><?php echo e(trim(($employee['department'] ?? '') . ' / ' . ($employee['position'] ?? ''), ' /') ?: '-'); ?></p></div>
  <div class="period"><?php echo e(month_label($period)); ?></div>
</header>
<div class="table-wrap">
<table>
<thead><tr><?php for($d=1;$d<=$days;$d++): $date=sprintf('%s-%02d',$period,$d); $weekDay=(int)date('N',strtotime($date)); ?><th class="<?php echo $weekDay>=6?'weekend':''; ?>"><span class="day"><?php echo $d; ?></span><br><small><?php echo ['','Pzt','Sal','Çar','Per','Cum','Cmt','Paz'][$weekDay]; ?></small></th><?php endfor; ?></tr></thead>
<tbody><tr><?php for($d=1;$d<=$days;$d++): $date=sprintf('%s-%02d',$period,$d); $entry=$entries[$date]??null; $status=$entry['status']??''; ?><td class="status" title="<?php echo e($entry['note']??''); ?>"><?php echo e($statuses[$status]['short']??'-'); ?><?php if((float)($entry['overtime_hours']??0)>0): ?><br><small>+<?php echo e(number_format((float)$entry['overtime_hours'],1,',','.')); ?>s</small><?php endif; ?></td><?php endfor; ?></tr></tbody>
</table>
</div>
<div class="summary">
  <div><span>Kayıtlı gün</span><b><?php echo e($summary['recorded_days']); ?></b></div>
  <div><span>Çalıştı</span><b><?php echo e($summary['work_days']); ?></b></div>
  <div><span>İzinli</span><b><?php echo e($summary['paid_leave_days']); ?></b></div>
  <div><span>Raporlu</span><b><?php echo e($summary['report_days']); ?></b></div>
  <div><span>Gelmedi</span><b><?php echo e($summary['absent_days']); ?></b></div>
  <div><span>Hafta tatili</span><b><?php echo e($summary['weekly_off_days']); ?></b></div>
  <div><span>Resmî tatil</span><b><?php echo e($summary['holiday_days']); ?></b></div>
  <div><span>Fazla mesai</span><b><?php echo e(number_format((float)$summary['overtime_hours'],1,',','.')); ?> saat</b></div>
</div>
<div class="signatures"><div>Personel</div><div>Bölüm sorumlusu</div><div>İşveren / Yetkili</div></div>
</body>
</html>
