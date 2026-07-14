<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

db()->exec("CREATE TABLE IF NOT EXISTS invoice_expense_types (
    invoice_id INTEGER PRIMARY KEY,
    category TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    created_by INTEGER,
    created_at TEXT,
    updated_by INTEGER,
    updated_at TEXT
)");
ensure_column(db(), 'invoices', 'issuer_name', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_source', 'TEXT');
ensure_column(db(), 'invoices', 'issuer_confidence', 'INTEGER NOT NULL DEFAULT 0');
ensure_column(db(), 'invoices', 'issuer_parser_version', 'TEXT');

function fatura_turleri(): array
{
    return [
        'iplik' => 'İplik / Hammadde',
        'iade' => 'İade Faturası',
        'telefon' => 'Telefon / İnternet',
        'elektrik' => 'Elektrik',
        'dogalgaz' => 'Doğalgaz',
        'kargo' => 'Kargo / Nakliye',
        'akaryakit' => 'Akaryakıt',
        'bakim' => 'Makine / Bakım',
        'ambalaj' => 'Ambalaj',
        'personel' => 'Personel Gideri',
        'ofis' => 'Ofis / Genel Gider',
        'diger' => 'Diğer',
    ];
}

function fatura_tur_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
    ];
    $value = strtoupper(strtr($value, $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

function fatura_tur_muhtelif_cari(string $value): bool
{
    return strpos(fatura_tur_norm($value), 'MUHTELIF FATURA GIRISI') !== false;
}

function fatura_tur_kendi_unvani(string $value): bool
{
    $text = ' ' . fatura_tur_norm($value) . ' ';
    foreach (['DUMANLAR', 'BITKE', 'MOFIY', 'BAFIY'] as $marker) {
        if (strpos($text, ' ' . $marker . ' ') !== false) return true;
    }
    return false;
}

function fatura_tur_oner(string $value): array
{
    $text = fatura_tur_norm($value);
    $rules = [
        'iade' => ['IADE FATURASI'=>200,'SATIS IADE'=>190,'SATIS IADESI'=>190,'URUN IADESI'=>180,'MAL IADESI'=>180,'IADE EDILEN'=>170,'IADE'=>70],
        'telefon' => ['TURK TELEKOM'=>100,'TTNET'=>100,'TURKCELL'=>100,'VODAFONE'=>100,'SUPERONLINE'=>100,'TELEFON'=>80,'INTERNET'=>80,'GSM'=>70,'ILETISIM'=>45],
        'dogalgaz' => ['DOGALGAZ'=>110,'DOGAL GAZ'=>110,'AKSA GAZ'=>100,'AKSAGAZ'=>100,'ENERYA'=>100,'IGDAS'=>100,'GAZDAS'=>100],
        'elektrik' => ['ELEKTRIK'=>100,'ENERJI'=>65,'YEDAS'=>100,'CEDAS'=>100,'UEDAS'=>100,'CK ENERJI'=>100,'YESILIRMAK ELEKTRIK'=>110,'ULUDAG ELEKTRIK'=>110,'ELEKTRIK DAGITIM'=>110],
        'iplik' => ['IPLIK'=>110,'PAMUK'=>80,'POLYESTER'=>80,'ELYAF'=>80,'LIKRA'=>80,'BAMBU'=>65,'MODAL'=>65,'TEKSTIL HAMMADDE'=>100,'HAMMADDE'=>80],
        'kargo' => ['KARGO'=>100,'NAKLIYE'=>100,'LOJISTIK'=>90,'SURAT KARGO'=>120,'YURTICI KARGO'=>120,'ARAS KARGO'=>120,'MNG KARGO'=>120,'PTT KARGO'=>120],
        'akaryakit' => ['AKARYAKIT'=>110,'BENZIN'=>90,'MOTORIN'=>90,'PETROL'=>70,'OPET'=>100,'SHELL'=>100,'PETROL OFISI'=>110,'TOTAL ENERGIES'=>100,'BP PETROL'=>100],
        'bakim' => ['MAKINE'=>70,'BAKIM'=>100,'YEDEK PARCA'=>100,'SERVIS'=>55,'RULMAN'=>85,'KOMPRESOR'=>85,'ELEKTRONIK KART'=>75,'TEKNIK SERVIS'=>100],
        'ambalaj' => ['AMBALAJ'=>110,'KOLI'=>80,'KARTON'=>75,'POSET'=>75,'KUTU'=>65,'ETIKET'=>65,'BANT'=>55,'PAKETLEME'=>80],
        'personel' => ['PERSONEL'=>90,'MAAS'=>100,'SGK'=>100,'IS SAGLIGI'=>85,'YEMEK HIZMET'=>80,'PERSONEL SERVIS'=>90],
        'ofis' => ['KIRTASIYE'=>100,'OFIS'=>80,'TEMIZLIK'=>70,'MUHASEBE'=>70,'DANISMANLIK'=>65,'YAZILIM'=>60,'LISANS'=>60,'ABONELIK'=>55],
    ];

    $best = ['', 0, ''];
    foreach ($rules as $category => $keywords) {
        foreach ($keywords as $keyword => $score) {
            if ($keyword !== '' && strpos($text, $keyword) !== false && $score > $best[1]) {
                $best = [$category, $score, $keyword];
            }
        }
    }

    return ['category'=>$best[1] >= 55 ? $best[0] : '', 'confidence'=>$best[1], 'matched'=>$best[2]];
}

function fatura_tur_period(string $value): string
{
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function fatura_tur_payload(string $period): array
{
    $start = $period . '-01';
    $end = date('Y-m-t', strtotime($start));
    $types = fatura_turleri();

    $stmt = db()->prepare("SELECT i.id, i.direction, i.invoice_date, i.total_amount, i.currency,
        COALESCE(i.description,'') AS description,
        COALESCE(i.document_name,'') AS document_name,
        COALESCE(i.document_path,'') AS document_path,
        COALESCE(i.issuer_name,'') AS issuer_name,
        COALESCE(i.issuer_source,'') AS issuer_source,
        COALESCE(i.issuer_confidence,0) AS issuer_confidence,
        COALESCE(i.issuer_parser_version,'') AS issuer_parser_version,
        COALESCE(c.name,'') AS cari_name,
        COALESCE(t.category,'') AS category,
        COALESCE(t.source,'') AS category_source
        FROM invoices i
        LEFT JOIN cariler c ON c.id=i.cari_id
        LEFT JOIN invoice_expense_types t ON t.invoice_id=i.id
        WHERE COALESCE(i.is_cancelled,0)=0 AND i.invoice_date BETWEEN ? AND ?
        ORDER BY i.invoice_date DESC, i.id DESC");
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll();

    $items = [];
    $summary = [];
    foreach ($types as $key => $label) {
        $summary[$key] = ['key'=>$key,'label'=>$label,'count'=>0,'total'=>0.0,'confirmed_count'=>0,'suggested_count'=>0,'unidentified_count'=>0,'issuer_map'=>[]];
    }

    foreach ($rows as $row) {
        $direction = (string)$row['direction'];
        $cariName = trim((string)$row['cari_name']);
        $genericCari = fatura_tur_muhtelif_cari($cariName);
        $storedIssuer = trim((string)$row['issuer_name']);
        $displayIssuer = $storedIssuer;
        if ($displayIssuer === '' && $cariName !== '' && !$genericCari) $displayIssuer = $cariName;
        $assigned = array_key_exists((string)$row['category'], $types) ? (string)$row['category'] : '';
        $suggestion = ['category'=>'','confidence'=>0,'matched'=>''];
        if ($direction === 'gelen' && $assigned === '') {
            $suggestion = fatura_tur_oner($displayIssuer . ' ' . (!$genericCari ? $cariName : '') . ' ' . (string)$row['description'] . ' ' . (string)$row['document_name']);
        }
        $effective = $direction === 'gelen' ? ($assigned !== '' ? $assigned : (string)$suggestion['category']) : 'satis';

        if ($direction === 'gelen' && isset($summary[$effective])) {
            $summary[$effective]['count']++;
            if ((string)$row['currency'] === 'TL') $summary[$effective]['total'] += (float)$row['total_amount'];
            if ($assigned !== '') $summary[$effective]['confirmed_count']++; else $summary[$effective]['suggested_count']++;
            if ($displayIssuer !== '') {
                $issuerKey = fatura_tur_norm($displayIssuer);
                if (!isset($summary[$effective]['issuer_map'][$issuerKey])) {
                    $summary[$effective]['issuer_map'][$issuerKey] = ['name'=>$displayIssuer,'count'=>0,'total'=>0.0];
                }
                $summary[$effective]['issuer_map'][$issuerKey]['count']++;
                if ((string)$row['currency'] === 'TL') $summary[$effective]['issuer_map'][$issuerKey]['total'] += (float)$row['total_amount'];
            } else {
                $summary[$effective]['unidentified_count']++;
            }
        }

        $items[] = [
            'id'=>(int)$row['id'],
            'direction'=>$direction,
            'category'=>$assigned,
            'category_label'=>$assigned !== '' ? $types[$assigned] : '',
            'category_source'=>(string)$row['category_source'],
            'suggestion'=>(string)$suggestion['category'],
            'suggestion_label'=>!empty($suggestion['category']) ? $types[$suggestion['category']] : '',
            'suggestion_confidence'=>(int)$suggestion['confidence'],
            'suggestion_match'=>(string)$suggestion['matched'],
            'effective_category'=>$effective,
            'cari_name'=>$cariName,
            'is_generic_cari'=>$genericCari,
            'issuer_name'=>$displayIssuer,
            'issuer_is_stored'=>$storedIssuer !== '',
            'issuer_source'=>(string)$row['issuer_source'],
            'issuer_confidence'=>(int)$row['issuer_confidence'],
            'issuer_parser_version'=>(string)$row['issuer_parser_version'],
            'needs_issuer'=>$direction === 'gelen' && $genericCari && $storedIssuer === '' && (string)$row['issuer_source'] !== 'manual' && !in_array((string)$row['issuer_parser_version'], ['3.2.0','3.3.0'], true) && !empty($row['document_path']),
            'document_name'=>(string)$row['document_name'],
            'document_url'=>!empty($row['document_path']) ? 'fatura-indir.php?id=' . (int)$row['id'] : '',
            'has_document'=>!empty($row['document_path']),
        ];
    }

    foreach ($summary as &$summaryRow) {
        $issuers = array_values($summaryRow['issuer_map']);
        usort($issuers, function ($a, $b) {
            if ($a['total'] == $b['total']) return $b['count'] <=> $a['count'];
            return $a['total'] < $b['total'] ? 1 : -1;
        });
        $summaryRow['issuers'] = $issuers;
        unset($summaryRow['issuer_map']);
    }
    unset($summaryRow);

    $summaryRows = array_values(array_filter($summary, function ($row) { return (int)$row['count'] > 0; }));
    usort($summaryRows, function ($a, $b) {
        if ($a['total'] == $b['total']) return 0;
        return $a['total'] < $b['total'] ? 1 : -1;
    });

    return ['period'=>$period,'categories'=>$types,'items'=>$items,'summary'=>$summaryRows];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $action = trim((string)($_POST['action'] ?? 'category'));
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        if ($invoiceId <= 0) throw new RuntimeException('Fatura seçimi geçersiz.');

        $stmt = db()->prepare("SELECT id, direction, COALESCE(issuer_name,'') AS issuer_name, COALESCE(issuer_source,'') AS issuer_source FROM invoices WHERE id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();
        if (!$invoice) throw new RuntimeException('Fatura bulunamadı veya iptal edilmiş.');

        if ($action === 'issuer') {
            if ((string)$invoice['direction'] !== 'gelen') throw new RuntimeException('Gönderen firma yalnızca gelen faturada kaydedilir.');
            $issuerName = trim((string)($_POST['issuer_name'] ?? ''));
            if (function_exists('mb_substr')) $issuerName = mb_substr($issuerName, 0, 180, 'UTF-8');
            else $issuerName = substr($issuerName, 0, 180);
            $source = (string)($_POST['source'] ?? 'manual') === 'pdf' ? 'pdf' : 'manual';
            $confidence = max(0, min(100, (int)($_POST['confidence'] ?? ($source === 'manual' ? 100 : 0))));
            if ($issuerName !== '' && fatura_tur_kendi_unvani($issuerName)) throw new RuntimeException('Kendi şirket ünvanımız gönderen firma olarak kaydedilemez.');
            if ($issuerName !== '' && fatura_tur_muhtelif_cari($issuerName)) throw new RuntimeException('MUHTELİF cari adı gönderen firma olarak kaydedilemez.');
            if ($source === 'pdf' && trim((string)$invoice['issuer_source']) === 'manual') {
                throw new RuntimeException('Elle girilen gönderen firma otomatik olarak değiştirilemez.');
            }
            $parserVersion = '';
            if ($source === 'pdf') {
                $parserVersion = preg_replace('/[^A-Za-z0-9._-]/', '', trim((string)($_POST['parser_version'] ?? ''))) ?: '';
                $parserVersion = substr($parserVersion, 0, 32);
            }
            db()->prepare('UPDATE invoices SET issuer_name=?, issuer_source=?, issuer_confidence=?, issuer_parser_version=?, updated_at=? WHERE id=?')
                ->execute([$issuerName, $issuerName === '' && $source === 'manual' ? '' : $source, $issuerName === '' ? 0 : $confidence, $parserVersion, now(), $invoiceId]);
            log_action($issuerName === '' ? 'Fatura göndereni okunamadı' : 'Fatura göndereni güncellendi', '#' . $invoiceId . ($issuerName !== '' ? ' → ' . $issuerName : ''));
            if ($issuerName !== '') audit_action('fatura', $invoiceId, 'gonderen_firma_guncellendi', null, ['issuer_name'=>$issuerName,'source'=>$source,'confidence'=>$confidence], $issuerName);
            $message = $issuerName === '' ? 'Gönderen firma PDF’den güvenilir biçimde okunamadı.' : 'Gönderen firma kaydedildi.';
        } else {
            $category = trim((string)($_POST['category'] ?? ''));
            $source = trim((string)($_POST['source'] ?? 'manual'));
            $types = fatura_turleri();
            if ($category !== '' && !array_key_exists($category, $types)) throw new RuntimeException('Fatura türü geçersiz.');
            if ((string)$invoice['direction'] !== 'gelen') throw new RuntimeException('Fatura türü yalnızca gelen faturalarda kullanılır.');

            if ($category === '') {
                db()->prepare('DELETE FROM invoice_expense_types WHERE invoice_id=?')->execute([$invoiceId]);
                log_action('Fatura türü kaldırıldı', '#' . $invoiceId);
            } else {
                $userId = current_user()['id'] ?? null;
                $stmt = db()->prepare('SELECT invoice_id FROM invoice_expense_types WHERE invoice_id=?');
                $stmt->execute([$invoiceId]);
                if ($stmt->fetchColumn()) {
                    db()->prepare('UPDATE invoice_expense_types SET category=?, source=?, updated_by=?, updated_at=? WHERE invoice_id=?')
                        ->execute([$category, $source === 'pdf' ? 'pdf' : 'manual', $userId, now(), $invoiceId]);
                } else {
                    db()->prepare('INSERT INTO invoice_expense_types (invoice_id, category, source, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$invoiceId, $category, $source === 'pdf' ? 'pdf' : 'manual', $userId, now(), $userId, now()]);
                }
                log_action('Fatura türü güncellendi', '#' . $invoiceId . ' → ' . $types[$category]);
                audit_action('fatura', $invoiceId, 'fatura_turu_guncellendi', null, ['category'=>$category], $types[$category]);
            }
            $message = $category === '' ? 'Fatura türü kaldırıldı.' : 'Fatura türü kaydedildi.';
        }

        $period = fatura_tur_period((string)($_POST['period'] ?? ''));
        $payload = fatura_tur_payload($period);
        $payload['ok'] = true;
        $payload['message'] = $message;
        $payload['csrf_token'] = csrf_token();
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $period = fatura_tur_period((string)($_GET['period'] ?? ''));
    $payload = fatura_tur_payload($period);
    $payload['ok'] = true;
    $payload['can_write'] = can_write();
    $payload['csrf_token'] = csrf_token();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
