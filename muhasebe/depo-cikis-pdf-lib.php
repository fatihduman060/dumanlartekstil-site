<?php
require_once __DIR__.'/lib/tfpdf/tfpdf.php';
require_once __DIR__.'/lib/tfpdf/font/unifont/ttfonts.php';

class WarehouseDispatchPdf extends tFPDF
{
    public $dispatchNo='';
    private $widths=[42,66,20,27,31];

    public function Header()
    {
        $this->SetFont('DejaVu','B',14);
        $this->SetTextColor(16,40,24);
        $this->Cell(186,8,'DUMANLAR TEKSTİL',0,1,'C');
        $this->SetFont('DejaVu','B',11);
        $this->MultiCell(186,7,'SİPARİŞ FİŞİ #'.$this->dispatchNo,0,'C');
        $this->Ln(3);
        if ($this->PageNo()>1) $this->tableHeader();
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('DejaVu','',8);
        $this->SetTextColor(90,90,90);
        $this->Cell(186,5,'Sayfa '.$this->PageNo(),0,0,'R');
    }

    public function tableHeader(): void
    {
        $this->SetFont('DejaVu','B',9);
        $this->SetFillColor(16,40,24);
        $this->SetTextColor(255,255,255);
        foreach (['Barkod','Ürün','Miktar','Birim fiyat','Tutar'] as $i=>$text) {
            $this->Cell($this->widths[$i],7,$text,1,0,'C',true);
        }
        $this->Ln();
        $this->SetTextColor(16,40,24);
        $this->SetFont('DejaVu','',9);
    }

    private function lines(string $text,float $width): array
    {
        $text=str_replace(["\r\n","\r"],"\n",$text);
        $lines=[];
        foreach (explode("\n",$text) as $paragraph) {
            $line='';
            foreach (preg_split('/(?<=\s)/u',$paragraph,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if ($line!=='' && $this->GetStringWidth($line.$word)>$width-3) {
                    $lines[]=rtrim($line); $line='';
                }
                // A barcode or a single long word may itself exceed the column.
                foreach (preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    if ($line!=='' && $this->GetStringWidth($line.$char)>$width-3) {
                        $lines[]=$line; $line='';
                    }
                    $line.=$char;
                }
            }
            $lines[]=$line;
        }
        return $lines;
    }

    public function productRow(array $values,bool $shade): void
    {
        $this->SetFont('DejaVu','',9);
        $columns=[];
        foreach ($values as $i=>$text) $columns[]=$this->lines((string)$text,$this->widths[$i]);
        $count=max(array_map('count',$columns));
        // Keep normal rows together; exceptionally tall rows continue without clipping.
        if ($count*5<220 && $this->GetY()+$count*5>277) $this->AddPage();
        for ($line=0;$line<$count;$line++) {
            if ($this->GetY()+5>277) $this->AddPage();
            $this->SetFillColor(...($shade?[242,239,233]:[255,255,255]));
            foreach ($columns as $i=>$parts) {
                $border='LR'.($line===0?'T':'').($line===$count-1?'B':'');
                $this->Cell($this->widths[$i],5,$parts[$line]??'',$border,0,$i>1?'R':'L',true);
            }
            $this->Ln();
        }
    }
}

function depo_cikis_pdf(array $row): WarehouseDispatchPdf
{
    $pdf=new WarehouseDispatchPdf('P','mm','A4');
    $pdf->SetMargins(12,10,12);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddFont('DejaVu','','DejaVuSansCondensed.ttf',true);
    $pdf->AddFont('DejaVu','B','DejaVuSansCondensed-Bold.ttf',true);
    $pdf->dispatchNo=(string)$row['dispatch_no'];
    $pdf->SetTitle('Depo çıkış fişi #'.$row['dispatch_no'],true);
    $pdf->AddPage();
    $pdf->SetFont('DejaVu','B',11);
    $pdf->MultiCell(186,6,(string)$row['customer_name']);
    $pdf->SetFont('DejaVu','',10);
    $pdf->MultiCell(186,5,trim($row['customer_city']."\n".$row['customer_address']));
    $pdf->Cell(186,8,'Tarih: '.tr_date($row['dispatch_date']),0,1);
    $pdf->Ln(3);
    if ($pdf->GetY()>265) $pdf->AddPage(); else $pdf->tableHeader();
    foreach ($row['items'] as $i=>$item) {
        $pdf->productRow([$item['product_barcode'],$item['product_name'],
            number_format((float)$item['quantity'],0,',','.'),
            money((float)$item['unit_price']),money((float)$item['line_total'])],$i%2===1);
    }
    if ($pdf->GetY()>240) $pdf->AddPage();
    $pdf->Ln(5);
    $pdf->SetFont('DejaVu','',10);
    $pdf->Cell(186,6,'Ara toplam: '.money((float)($row['subtotal']??$row['total'])).' TL',0,1,'R');
    if ((int)($row['discount_enabled']??0)===1) {
        $pdf->Cell(186,6,'İskonto (%'.$row['discount_rate'].'): -'.money((float)$row['discount_amount']).' TL',0,1,'R');
    }
    if ((int)($row['vat_enabled']??0)===1) {
        $pdf->Cell(186,6,'KDV (%'.$row['vat_rate'].'): '.money((float)$row['vat_amount']).' TL',0,1,'R');
    }
    $pdf->SetFont('DejaVu','B',12);
    $pdf->Cell(186,8,'GENEL TOPLAM: '.money((float)$row['total']).' TL',0,1,'R');
    if (trim((string)$row['note'])!=='') {
        $pdf->Ln(5);
        $pdf->SetFont('DejaVu','',9);
        $pdf->MultiCell(186,5,'NOT: '.$row['note']);
    }
    return $pdf;
}
