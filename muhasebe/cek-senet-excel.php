<?php
require_once __DIR__ . '/layout.php';
require_login();
require_private_finance_modules();

// Ciro alanları eski veritabanlarında da güvenle hazır olsun.
$pdo = db();
ensure_column($pdo, 'checks', 'endorsed_to_cari_id', 'INTEGER');
ensure_column($pdo, 'checks', 'endorsement_movement_id', 'INTEGER');
ensure_column($pdo, 'checks', 'endorsed_at', 'TEXT');

$type = trim((string)($_GET['type'] ?? 'gelen'));
if (!in_array($type, ['gelen', 'giden'], true)) $type = 'gelen';

function cek_senet_excel_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function cek_senet_excel_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $time = strtotime($value);
    return $time ? date('d.m.Y', $time) : $value;
}

function cek_senet_excel_status(string $status): string
{
    $map = [
        'bekliyor'=>'Bekliyor',
        'bankaya_verildi'=>'Bankaya verildi',
        'tahsil_edildi'=>'Tahsil edildi',
        'odendi'=>'Ödendi',
        'ciro_edildi'=>'Ciro edildi',
        'iade'=>'İade',
        'karsiliksiz'=>'Karşılıksız',
        'protestolu'=>'Protestolu',
        'iptal'=>'İptal',
    ];
    return $map[$status] ?? $status;
}

$baseSql = "SELECT ch.*,
        c.name AS source_cari_name,
        c.city AS source_cari_city,
        ec.name AS endorsed_cari_name,
        ec.city AS endorsed_cari_city,
        m.payment_method AS movement_payment_method
    FROM checks ch
    LEFT JOIN cariler c ON c.id=ch.cari_id
    LEFT JOIN cariler ec ON ec.id=ch.endorsed_to_cari_id
    LEFT JOIN movements m ON m.id=ch.movement_id";

if ($type === 'gelen') {
    $where = " WHERE ch.direction='alinacak' AND COALESCE(ch.is_cancelled,0)=0";
} else {
    // Giden döküm: doğrudan verdiğimiz evraklar + müşteriden alıp başka cariye ciro ettiklerimiz.
    $where = " WHERE COALESCE(ch.is_cancelled,0)=0 AND (ch.direction='verilecek' OR (ch.direction='alinacak' AND ch.endorsed_to_cari_id IS NOT NULL))";
}

$sql = $baseSql . $where . " ORDER BY COALESCE(ch.endorsed_at,ch.created_at) ASC, ch.due_date ASC, ch.id ASC";
$rows = $pdo->query($sql)->fetchAll() ?: [];

$title = $type === 'gelen' ? 'Gelen Çek ve Senetler' : 'Giden / Ciro Edilen Çek ve Senetler';
$filePrefix = $type === 'gelen' ? 'gelen-cek-senetler' : 'giden-ciro-cek-senetler';
$filename = $filePrefix . '-' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$columns = $type === 'gelen'
    ? ['Evrak Türü','Alınış / Kayıt Tarihi','Çek / Senet Tarihi','Vade Tarihi','Kimden Alındı','Çek / Senet Sahibi','Banka','Şube','Tutar','Şehir','Çek / Senet No','Durum','Kime Ciro Edildi','Ciro Tarihi','Açıklama']
    : ['Evrak Türü','Gönderim Türü','Alınış / Kayıt Tarihi','Çek / Senet Tarihi','Vade Tarihi','Kimden Alındı','Çek / Senet Sahibi','Banka','Şube','Tutar','Şehir','Çek / Senet No','Kime Gönderildi / Ciro Edildi','Gönderim / Ciro Tarihi','Durum','Açıklama'];

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<?mso-application progid=\"Excel.Sheet\"?>\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Font ss:FontName="Calibri" ss:Size="10"/></Style>
  <Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14"/><Interior ss:Color="#D9EAD3" ss:Pattern="Solid"/></Style>
  <Style ss:ID="Header"><Font ss:Bold="1"/><Interior ss:Color="#1F4E3D" ss:Pattern="Solid"/><Font ss:Bold="1" ss:Color="#FFFFFF"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style>
  <Style ss:ID="Text"><Alignment ss:Vertical="Center" ss:WrapText="1"/></Style>
  <Style ss:ID="Money"><NumberFormat ss:Format="#,##0.00 \&quot;TL\&quot;"/></Style>
  <Style ss:ID="Total"><Font ss:Bold="1"/><Interior ss:Color="#FFF2CC" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.00 \&quot;TL\&quot;"/></Style>
 </Styles>
 <Worksheet ss:Name="<?php echo cek_senet_excel_xml($type === 'gelen' ? 'Gelenler' : 'Gidenler'); ?>">
  <Table>
   <Column ss:Width="85"/><Column ss:Width="115"/><Column ss:Width="105"/><Column ss:Width="105"/><Column ss:Width="170"/><Column ss:Width="160"/><Column ss:Width="120"/><Column ss:Width="110"/><Column ss:Width="95"/><Column ss:Width="90"/><Column ss:Width="110"/><Column ss:Width="105"/><Column ss:Width="180"/><Column ss:Width="115"/><Column ss:Width="120"/><Column ss:Width="220"/>
   <Row ss:Height="26"><Cell ss:MergeAcross="<?php echo count($columns)-1; ?>" ss:StyleID="Title"><Data ss:Type="String"><?php echo cek_senet_excel_xml($title); ?></Data></Cell></Row>
   <Row><Cell ss:MergeAcross="<?php echo count($columns)-1; ?>" ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml('Oluşturma tarihi: ' . date('d.m.Y H:i') . ' · Toplam kayıt: ' . count($rows)); ?></Data></Cell></Row>
   <Row>
<?php foreach ($columns as $column): ?>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?php echo cek_senet_excel_xml($column); ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php
$total = 0.0;
foreach ($rows as $row):
    $paymentMethod = strtoupper(trim((string)($row['movement_payment_method'] ?? '')));
    $isNote = strcasecmp(trim((string)($row['bank_name'] ?? '')), 'Senet') === 0 || strpos($paymentMethod, 'SENET') !== false;
    $instrument = $isNote ? 'Senet' : 'Çek';
    $amount = (float)($row['amount'] ?? 0);
    $total += $amount;
    $receivedDate = cek_senet_excel_date(substr((string)($row['created_at'] ?? ''), 0, 10));
    $issueDate = cek_senet_excel_date($row['issue_date'] ?? '');
    $dueDate = cek_senet_excel_date($row['due_date'] ?? '');
    $sourceCari = trim((string)($row['source_cari_name'] ?? ''));
    $drawer = trim((string)($row['drawer'] ?? ''));
    $bank = $isNote ? 'Senet' : trim((string)($row['bank_name'] ?? ''));
    $branch = trim((string)($row['branch_name'] ?? ''));
    $city = trim((string)($row['source_cari_city'] ?? ''));
    $checkNo = trim((string)($row['check_no'] ?? ''));
    $status = cek_senet_excel_status((string)($row['status'] ?? 'bekliyor'));
    $endorsedTo = trim((string)($row['endorsed_cari_name'] ?? ''));
    $endorsedAt = cek_senet_excel_date($row['endorsed_at'] ?? '');
    $description = trim((string)($row['description'] ?? ''));
    if ($type === 'giden') {
        $isEndorsed = (string)($row['direction'] ?? '') === 'alinacak' && (int)($row['endorsed_to_cari_id'] ?? 0) > 0;
        $sendType = $isEndorsed ? 'Müşteri evrakı ciro' : 'Doğrudan verilen evrak';
        $destination = $isEndorsed ? $endorsedTo : $sourceCari;
        $sendDate = $isEndorsed ? $endorsedAt : cek_senet_excel_date(substr((string)($row['created_at'] ?? ''), 0, 10));
        if (!$isEndorsed && $city === '') $city = trim((string)($row['source_cari_city'] ?? ''));
    }
?>
   <Row>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($instrument); ?></Data></Cell>
<?php if ($type === 'giden'): ?>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($sendType); ?></Data></Cell>
<?php endif; ?>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($receivedDate); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($issueDate); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($dueDate); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($sourceCari); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($drawer); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($bank); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($branch); ?></Data></Cell>
    <Cell ss:StyleID="Money"><Data ss:Type="Number"><?php echo number_format($amount, 2, '.', ''); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($city); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($checkNo); ?></Data></Cell>
<?php if ($type === 'gelen'): ?>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($status); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($endorsedTo); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($endorsedAt); ?></Data></Cell>
<?php else: ?>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($destination); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($sendDate); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($status); ?></Data></Cell>
<?php endif; ?>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo cek_senet_excel_xml($description); ?></Data></Cell>
   </Row>
<?php endforeach; ?>
   <Row>
    <Cell ss:MergeAcross="<?php echo $type === 'gelen' ? 7 : 8; ?>" ss:StyleID="Title"><Data ss:Type="String">GENEL TOPLAM</Data></Cell>
    <Cell ss:StyleID="Total"><Data ss:Type="Number"><?php echo number_format($total, 2, '.', ''); ?></Data></Cell>
   </Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions>
 </Worksheet>
</Workbook>
