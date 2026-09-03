<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/kredi-kartlari-lib.php';
require_login();
require_private_finance_modules();

$pdo = db();
ensure_column($pdo, 'movements', 'card_key', 'TEXT');
ensure_column($pdo, 'movements', 'report_excluded', 'INTEGER NOT NULL DEFAULT 0');

function kart_odeme_excel_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function kart_odeme_excel_date(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $time = strtotime($value);
    return $time ? date('d.m.Y', $time) : $value;
}

$sql = "SELECT m.*, c.name AS cari_name, c.city AS cari_city
    FROM movements m
    LEFT JOIN cariler c ON c.id=m.cari_id
    WHERE COALESCE(m.is_cancelled,0)=0
      AND m.movement_type='odeme'
      AND COALESCE(m.report_excluded,0)=1
      AND COALESCE(m.card_key,'')<>''
    ORDER BY m.movement_date ASC, m.id ASC";
$rows = $pdo->query($sql)->fetchAll() ?: [];
$cards = muhasebe_kredi_kartlari();
$filename = 'kart-ile-yapilan-odemeler-' . date('Y-m-d') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$columns = ['İşlem Tarihi','Cari','Şehir','Kart','Banka','Kart Son 4','Tutar','Para Birimi','Açıklama','Hareket No'];

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
  <Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1F4E3D" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/></Style>
  <Style ss:ID="Text"><Alignment ss:Vertical="Center" ss:WrapText="1"/></Style>
  <Style ss:ID="Money"><NumberFormat ss:Format="#,##0.00 \&quot;TL\&quot;"/></Style>
  <Style ss:ID="Total"><Font ss:Bold="1"/><Interior ss:Color="#FFF2CC" ss:Pattern="Solid"/><NumberFormat ss:Format="#,##0.00 \&quot;TL\&quot;"/></Style>
 </Styles>
 <Worksheet ss:Name="Kartlı Ödemeler">
  <Table>
   <Column ss:Width="105"/><Column ss:Width="190"/><Column ss:Width="90"/><Column ss:Width="230"/><Column ss:Width="130"/><Column ss:Width="75"/><Column ss:Width="100"/><Column ss:Width="80"/><Column ss:Width="250"/><Column ss:Width="90"/>
   <Row ss:Height="26"><Cell ss:MergeAcross="9" ss:StyleID="Title"><Data ss:Type="String">Kart ile Yapılan Cari Ödemeler</Data></Cell></Row>
   <Row><Cell ss:MergeAcross="9" ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml('Oluşturma tarihi: ' . date('d.m.Y H:i') . ' · Toplam kayıt: ' . count($rows)); ?></Data></Cell></Row>
   <Row>
<?php foreach ($columns as $column): ?>
    <Cell ss:StyleID="Header"><Data ss:Type="String"><?php echo kart_odeme_excel_xml($column); ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php $total=0.0; foreach($rows as $row):
    $key=(string)($row['card_key'] ?? '');
    $card=$cards[$key] ?? ['name'=>(string)($row['payment_method'] ?? 'Kredi Kartı'),'bank_name'=>'','last4'=>''];
    $amount=(float)($row['amount'] ?? 0);
    $total+=$amount;
?>
   <Row>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml(kart_odeme_excel_date($row['movement_date'] ?? '')); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)($row['cari_name'] ?? '')); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)($row['cari_city'] ?? '')); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)$card['name']); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)$card['bank_name']); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)$card['last4']); ?></Data></Cell>
    <Cell ss:StyleID="Money"><Data ss:Type="Number"><?php echo number_format($amount,2,'.',''); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)($row['currency'] ?? 'TL')); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml((string)($row['description'] ?? '')); ?></Data></Cell>
    <Cell ss:StyleID="Text"><Data ss:Type="String"><?php echo kart_odeme_excel_xml('#' . (int)$row['id']); ?></Data></Cell>
   </Row>
<?php endforeach; ?>
   <Row><Cell ss:MergeAcross="5" ss:StyleID="Title"><Data ss:Type="String">GENEL TOPLAM</Data></Cell><Cell ss:StyleID="Total"><Data ss:Type="Number"><?php echo number_format($total,2,'.',''); ?></Data></Cell></Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>3</SplitHorizontal><TopRowBottomPane>3</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions>
 </Worksheet>
</Workbook>
