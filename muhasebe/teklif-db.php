<?php
require_once __DIR__ . '/bootstrap.php';

function teklif_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS offers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_no TEXT NOT NULL,
        offer_date TEXT NOT NULL,
        document_title TEXT NOT NULL DEFAULT 'TEKLİF FORMU',
        cari_id INTEGER,
        customer_name TEXT NOT NULL,
        customer_city TEXT,
        customer_address TEXT,
        customer_tax_office TEXT,
        customer_tax_no TEXT,
        customer_phone TEXT,
        currency TEXT NOT NULL DEFAULT 'TL',
        quantity_label TEXT NOT NULL DEFAULT 'DZ',
        note TEXT,
        footer_text TEXT,
        term_text TEXT,
        discount_enabled INTEGER NOT NULL DEFAULT 0,
        discount_rate REAL NOT NULL DEFAULT 0,
        discount_amount REAL NOT NULL DEFAULT 0,
        vat_enabled INTEGER NOT NULL DEFAULT 0,
        vat_rate REAL NOT NULL DEFAULT 10,
        subtotal REAL NOT NULL DEFAULT 0,
        vat_amount REAL NOT NULL DEFAULT 0,
        grand_total REAL NOT NULL DEFAULT 0,
        is_deleted INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    try {
        $cols = $pdo->query("PRAGMA table_info(offers)")->fetchAll();
        $existing = [];
        foreach ($cols as $col) $existing[(string)$col['name']] = true;
        $add = [
            'customer_address' => 'TEXT',
            'customer_tax_office' => 'TEXT',
            'customer_tax_no' => 'TEXT',
            'customer_phone' => 'TEXT',
            'discount_enabled' => 'INTEGER NOT NULL DEFAULT 0',
            'discount_rate' => 'REAL NOT NULL DEFAULT 0',
            'discount_amount' => 'REAL NOT NULL DEFAULT 0',
            'posted_to_cari' => 'INTEGER NOT NULL DEFAULT 0',
            'cari_movement_id' => 'INTEGER',
            'posted_at' => 'TEXT',
            'posted_by' => 'INTEGER',
        ];
        foreach ($add as $name => $type) {
            if (empty($existing[$name])) $pdo->exec("ALTER TABLE offers ADD COLUMN {$name} {$type}");
        }
    } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS offer_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        product_barcode TEXT,
        product_name TEXT,
        product_type TEXT,
        quantity REAL NOT NULL DEFAULT 0,
        unit_price REAL NOT NULL DEFAULT 0,
        line_total REAL NOT NULL DEFAULT 0,
        FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE
    )");
    try { ensure_column($pdo, 'offer_items', 'product_barcode', 'TEXT'); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS offer_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        barcode TEXT,
        name TEXT NOT NULL UNIQUE,
        product_type TEXT,
        default_unit_price REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    try { ensure_column($pdo, 'offer_products', 'barcode', 'TEXT'); } catch (Throwable $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offers_date ON offers(offer_date, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offer_items_offer ON offer_items(offer_id, sort_order)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offer_products_barcode ON offer_products(barcode)");
}

function teklif_products_for_select(): array
{
    teklif_db_ensure();
    return db()->query("SELECT * FROM offer_products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
}

function teklif_decimal($value): float
{
    $v = trim(str_replace(' ', '', (string)$value));
    if ($v === '') return 0.0;
    $hasComma = strpos($v, ',') !== false;
    $hasDot = strpos($v, '.') !== false;
    if ($hasComma) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif ($hasDot) {
        $parts = explode('.', $v);
        $last = end($parts);
        if (count($parts) > 2 || strlen((string)$last) === 3) {
            $v = str_replace('.', '', $v);
        }
    }
    return is_numeric($v) ? (float)$v : 0.0;
}

function teklif_money(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function teklif_ean13_check_digit(string $first12): string
{
    $digits = preg_replace('/\D+/', '', $first12);
    if (strlen($digits) !== 12) return '';
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$digits[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (string)((10 - ($sum % 10)) % 10);
}

function teklif_barcode_from_article(string $article): string
{
    $digits = preg_replace('/\D+/', '', $article);
    if (strlen($digits) !== 4) return '';
    $first12 = '86992348' . $digits;
    return $first12 . teklif_ean13_check_digit($first12);
}

function teklif_article_from_text(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (preg_match('/86992348\s*([0-9]{2})[\s\-\/.]*([0-9]{2})\s*[0-9]/u', $text, $m)) return $m[1] . $m[2];
    if (preg_match('/(?:^|[^0-9])([0-9]{2})\s*[\-\/.]\s*([0-9]{2})(?:[^0-9]|$)/u', $text, $m)) return $m[1] . $m[2];
    if (preg_match('/^\s*([0-9]{4})(?:[^0-9]|$)/u', $text, $m)) return $m[1];
    return '';
}

function teklif_normalize_barcode(string $barcode = '', string $productName = '', string $productType = ''): string
{
    $raw = trim($barcode);
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) === 13) return $digits;
    if (strlen($digits) === 12 && str_starts_with($digits, '86992348')) return $digits . teklif_ean13_check_digit($digits);
    if (strlen($digits) === 4) return teklif_barcode_from_article($digits);
    $article = teklif_article_from_text($raw) ?: teklif_article_from_text($productName) ?: teklif_article_from_text($productType);
    if ($article !== '') return teklif_barcode_from_article($article);
    return $raw;
}

function teklif_active_movement_id(int $movementId): int
{
    if ($movementId <= 0) return 0;
    try {
        $stmt = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1');
        $stmt->execute([$movementId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function teklif_category_id(?string $name): ?int
{
    $name = trim((string)$name);
    if ($name === '') return null;
    try {
        $stmt = db()->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
        $stmt->execute([$name]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function teklif_validate_posted_payload(array $payload): void
{
    if ((int)($payload['cari_id'] ?? 0) <= 0) {
        throw new RuntimeException('Bu sipariş fişi cariye işlenmiş. Düzeltme yaparken cari seçimi boş bırakılamaz.');
    }
    if ((float)($payload['grand_total'] ?? 0) <= 0) {
        throw new RuntimeException('Bu sipariş fişi cariye işlenmiş. Cariye aktarılmış tutar sıfır veya eksi olamaz.');
    }
    if ((string)($payload['currency'] ?? 'TL') !== 'TL') {
        throw new RuntimeException('Bu sipariş fişi cariye TL olarak işlenmiş. Para birimi değiştirilirse cari hareket otomatik güncellenemez.');
    }
}

function teklif_sync_posted_cari_movement(int $movementId, array $payload): void
{
    $movementId = teklif_active_movement_id($movementId);
    if ($movementId <= 0) return;
    teklif_validate_posted_payload($payload);

    $cariId = (int)$payload['cari_id'];
    $amount = (float)$payload['grand_total'];
    $date = trim((string)($payload['offer_date'] ?? '')) ?: date('Y-m-d');
    $no = trim((string)($payload['offer_no'] ?? ''));
    $title = trim((string)($payload['document_title'] ?? 'SİPARİŞ FİŞİ')) ?: 'SİPARİŞ FİŞİ';
    $description = $title . ' no: ' . $no . ' / Ürün satışı';
    $categoryId = teklif_category_id('Satış');
    $now = now();

    if ($categoryId) {
        db()->prepare('UPDATE movements SET cari_id=?, category_id=?, account_id=NULL, movement_type=?, amount=?, movement_date=?, due_date=NULL, payment_method=?, description=?, document_type=?, updated_at=? WHERE id=?')
            ->execute([$cariId, $categoryId, 'alacak', $amount, $date, 'Sipariş fişi', $description, 'siparis_fisi', $now, $movementId]);
    } else {
        db()->prepare('UPDATE movements SET cari_id=?, account_id=NULL, movement_type=?, amount=?, movement_date=?, due_date=NULL, payment_method=?, description=?, document_type=?, updated_at=? WHERE id=?')
            ->execute([$cariId, 'alacak', $amount, $date, 'Sipariş fişi', $description, 'siparis_fisi', $now, $movementId]);
    }

    if (function_exists('sync_movement_account_transaction')) {
        sync_movement_account_transaction($movementId);
    }
}

function teklif_next_offer_no(): string
{
    teklif_db_ensure();
    $max = 0;
    try {
        $rows = db()->query('SELECT offer_no FROM offers')->fetchAll();
        foreach ($rows as $row) {
            $raw = trim((string)($row['offer_no'] ?? ''));
            if ($raw !== '' && ctype_digit($raw)) {
                $max = max($max, (int)$raw);
            }
        }
    } catch (Throwable $e) {}
    return str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

function teklif_load(int $id): ?array
{
    teklif_db_ensure();
    if ($id <= 0) return null;
    $stmt = db()->prepare('SELECT o.*, c.name AS cari_name, c.address AS cari_address, c.tax_no AS cari_tax_no, c.tax_office AS cari_tax_office, c.phone AS cari_phone FROM offers o LEFT JOIN cariler c ON c.id=o.cari_id WHERE o.id=? AND COALESCE(o.is_deleted,0)=0');
    $stmt->execute([$id]);
    $offer = $stmt->fetch();
    if (!$offer) return null;

    foreach ([
        'customer_address' => 'cari_address',
        'customer_tax_no' => 'cari_tax_no',
        'customer_tax_office' => 'cari_tax_office',
        'customer_phone' => 'cari_phone',
    ] as $offerKey => $cariKey) {
        if (trim((string)($offer[$offerKey] ?? '')) === '' && trim((string)($offer[$cariKey] ?? '')) !== '') {
            $offer[$offerKey] = $offer[$cariKey];
        }
    }

    $stmt = db()->prepare('SELECT * FROM offer_items WHERE offer_id=? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$id]);
    $offer['items'] = $stmt->fetchAll();
    return $offer;
}

function teklif_parse_items_from_post(): array
{
    $barcodes = is_array($_POST['product_barcode'] ?? null) ? $_POST['product_barcode'] : [];
    $names = is_array($_POST['product_name'] ?? null) ? $_POST['product_name'] : [];
    $types = is_array($_POST['product_type'] ?? null) ? $_POST['product_type'] : [];
    $qtys = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $prices = is_array($_POST['unit_price'] ?? null) ? $_POST['unit_price'] : [];
    $max = max(count($barcodes), count($names), count($types), count($qtys), count($prices));
    $items = [];
    for ($i = 0; $i < $max; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $type = trim((string)($types[$i] ?? ''));
        $barcode = teklif_normalize_barcode((string)($barcodes[$i] ?? ''), $name, $type);
        $qty = teklif_decimal($qtys[$i] ?? '0');
        $price = teklif_decimal($prices[$i] ?? '0');
        if ($barcode === '' && $name === '' && $type === '' && $qty <= 0 && $price <= 0) continue;
        $line = $qty * $price;
        $items[] = ['product_barcode'=>$barcode, 'product_name'=>$name, 'product_type'=>$type, 'quantity'=>$qty, 'unit_price'=>$price, 'line_total'=>$line];
    }
    return $items;
}

function teklif_save_product_suggestion(string $name, string $type = '', float $price = 0, string $barcode = ''): void
{
    $name = trim($name);
    $barcode = teklif_normalize_barcode($barcode, $name, $type);
    if ($name === '') return;
    try {
        db()->prepare("INSERT INTO offer_products (barcode, name, product_type, default_unit_price, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)
            ON CONFLICT(name) DO UPDATE SET barcode=CASE WHEN excluded.barcode!='' THEN excluded.barcode ELSE offer_products.barcode END, product_type=CASE WHEN excluded.product_type!='' THEN excluded.product_type ELSE offer_products.product_type END, default_unit_price=CASE WHEN excluded.default_unit_price>0 THEN excluded.default_unit_price ELSE offer_products.default_unit_price END, updated_at=excluded.updated_at")
            ->execute([$barcode, $name, $type, $price, now(), now()]);
    } catch (Throwable $e) {}
}

function teklif_save_from_post(int $id = 0): int
{
    teklif_db_ensure();
    $items = teklif_parse_items_from_post();
    $subtotal = 0.0;
    foreach ($items as $item) $subtotal += (float)$item['line_total'];

    $discountEnabled = isset($_POST['discount_enabled']) && (string)$_POST['discount_enabled'] === '1' ? 1 : 0;
    $discountRate = teklif_decimal($_POST['discount_rate'] ?? '0');
    if ($discountRate < 0) $discountRate = 0;
    if ($discountRate > 100) $discountRate = 100;
    $discountAmount = $discountEnabled ? ($subtotal * $discountRate / 100) : 0.0;
    $discountedSubtotal = max(0.0, $subtotal - $discountAmount);

    $vatEnabled = isset($_POST['vat_enabled']) && (string)$_POST['vat_enabled'] === '1' ? 1 : 0;
    $vatRate = teklif_decimal($_POST['vat_rate'] ?? '10');
    if ($vatRate < 0) $vatRate = 0;
    $vatAmount = $vatEnabled ? ($discountedSubtotal * $vatRate / 100) : 0.0;
    $grandTotal = $discountedSubtotal + $vatAmount;

    $payload = [
        'offer_no' => trim((string)($_POST['offer_no'] ?? '')) ?: teklif_next_offer_no(),
        'offer_date' => trim((string)($_POST['offer_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
        'document_title' => trim((string)($_POST['document_title'] ?? 'TEKLİF FORMU')) ?: 'TEKLİF FORMU',
        'cari_id' => ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null,
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'customer_city' => trim((string)($_POST['customer_city'] ?? '')),
        'customer_address' => trim((string)($_POST['customer_address'] ?? '')),
        'customer_tax_office' => trim((string)($_POST['customer_tax_office'] ?? '')),
        'customer_tax_no' => trim((string)($_POST['customer_tax_no'] ?? '')),
        'customer_phone' => trim((string)($_POST['customer_phone'] ?? '')),
        'currency' => trim((string)($_POST['currency'] ?? 'TL')) ?: 'TL',
        'quantity_label' => trim((string)($_POST['quantity_label'] ?? 'DZ')) ?: 'DZ',
        'note' => trim((string)($_POST['note'] ?? '')),
        'footer_text' => trim((string)($_POST['footer_text'] ?? 'MALIMIZDAN HAYIR GÖRÜN.')),
        'term_text' => trim((string)($_POST['term_text'] ?? '')),
        'discount_enabled' => $discountEnabled,
        'discount_rate' => $discountRate,
        'discount_amount' => $discountAmount,
        'vat_enabled' => $vatEnabled,
        'vat_rate' => $vatRate,
        'subtotal' => $subtotal,
        'vat_amount' => $vatAmount,
        'grand_total' => $grandTotal,
    ];
    if ($payload['customer_name'] === '') throw new RuntimeException('Firma / müşteri adı boş olamaz.');
    if (!$items) throw new RuntimeException('En az bir ürün satırı girmelisin.');

    $pdo = db();
    $syncMovementId = 0;
    if ($id > 0) {
        $old = teklif_load($id);
        if (!$old) throw new RuntimeException('Düzenlenecek teklif bulunamadı.');
        $syncMovementId = teklif_active_movement_id((int)($old['cari_movement_id'] ?? 0));
        if ($syncMovementId > 0) teklif_validate_posted_payload($payload);

        $stmt = $pdo->prepare('UPDATE offers SET offer_no=:offer_no, offer_date=:offer_date, document_title=:document_title, cari_id=:cari_id, customer_name=:customer_name, customer_city=:customer_city, customer_address=:customer_address, customer_tax_office=:customer_tax_office, customer_tax_no=:customer_tax_no, customer_phone=:customer_phone, currency=:currency, quantity_label=:quantity_label, note=:note, footer_text=:footer_text, term_text=:term_text, discount_enabled=:discount_enabled, discount_rate=:discount_rate, discount_amount=:discount_amount, vat_enabled=:vat_enabled, vat_rate=:vat_rate, subtotal=:subtotal, vat_amount=:vat_amount, grand_total=:grand_total, updated_at=:updated_at WHERE id=:id');
        $payload['updated_at'] = now();
        $payload['id'] = $id;
        $stmt->execute($payload);
        $pdo->prepare('DELETE FROM offer_items WHERE offer_id=?')->execute([$id]);
        $offerId = $id;
        log_action('Teklif güncellendi', $payload['offer_no'] . ' ' . $payload['customer_name']);
        audit_action('teklif', $offerId, 'guncellendi', $old, $payload, $payload['offer_no']);
    } else {
        $stmt = $pdo->prepare('INSERT INTO offers (offer_no, offer_date, document_title, cari_id, customer_name, customer_city, customer_address, customer_tax_office, customer_tax_no, customer_phone, currency, quantity_label, note, footer_text, term_text, discount_enabled, discount_rate, discount_amount, vat_enabled, vat_rate, subtotal, vat_amount, grand_total, created_by, created_at, updated_at) VALUES (:offer_no, :offer_date, :document_title, :cari_id, :customer_name, :customer_city, :customer_address, :customer_tax_office, :customer_tax_no, :customer_phone, :currency, :quantity_label, :note, :footer_text, :term_text, :discount_enabled, :discount_rate, :discount_amount, :vat_enabled, :vat_rate, :subtotal, :vat_amount, :grand_total, :created_by, :created_at, :updated_at)');
        $payload['created_by'] = current_user()['id'] ?? null;
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $stmt->execute($payload);
        $offerId = (int)$pdo->lastInsertId();
        log_action('Teklif eklendi', $payload['offer_no'] . ' ' . $payload['customer_name']);
        audit_action('teklif', $offerId, 'eklendi', null, $payload, $payload['offer_no']);
    }

    $stmtItem = $pdo->prepare('INSERT INTO offer_items (offer_id, sort_order, product_barcode, product_name, product_type, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $idx => $item) {
        $stmtItem->execute([$offerId, $idx + 1, $item['product_barcode'], $item['product_name'], $item['product_type'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        teklif_save_product_suggestion($item['product_name'], $item['product_type'], (float)$item['unit_price'], $item['product_barcode']);
    }
    if ($syncMovementId > 0) {
        teklif_sync_posted_cari_movement($syncMovementId, $payload);
        audit_action('teklif', $offerId, 'cari_hareketi_guncellendi', null, ['movement_id'=>$syncMovementId,'amount'=>$payload['grand_total']], $payload['offer_no']);
        log_action('Teklife bağlı cari hareket güncellendi', $payload['offer_no'] . ' - ' . teklif_money((float)$payload['grand_total']) . ' TL');
    }
    return $offerId;
}

function teklif_delete(int $id): bool
{
    teklif_db_ensure();
    if ($id <= 0) return false;
    $old = teklif_load($id);
    if (!$old) return false;
    db()->prepare('UPDATE offers SET is_deleted=1, updated_at=? WHERE id=?')->execute([now(), $id]);
    log_action('Teklif silindi', ($old['offer_no'] ?? '') . ' ' . ($old['customer_name'] ?? ''));
    audit_action('teklif', $id, 'silindi', $old, null, $old['offer_no'] ?? '');
    return true;
}

function teklifler_list(int $limit = 100): array
{
    teklif_db_ensure();
    $limit = max(1, min(500, $limit));
    return db()->query('SELECT o.*, c.name AS cari_name FROM offers o LEFT JOIN cariler c ON c.id=o.cari_id WHERE COALESCE(o.is_deleted,0)=0 ORDER BY o.offer_date DESC, o.id DESC LIMIT ' . $limit)->fetchAll();
}
