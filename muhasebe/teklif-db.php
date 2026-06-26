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
        currency TEXT NOT NULL DEFAULT 'TL',
        quantity_label TEXT NOT NULL DEFAULT 'DZ',
        note TEXT,
        footer_text TEXT,
        term_text TEXT,
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS offer_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        offer_id INTEGER NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        product_name TEXT,
        product_type TEXT,
        quantity REAL NOT NULL DEFAULT 0,
        unit_price REAL NOT NULL DEFAULT 0,
        line_total REAL NOT NULL DEFAULT 0,
        FOREIGN KEY(offer_id) REFERENCES offers(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS offer_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        product_type TEXT,
        default_unit_price REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offers_date ON offers(offer_date, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_offer_items_offer ON offer_items(offer_id, sort_order)");
}

function teklif_products_for_select(): array
{
    teklif_db_ensure();
    return db()->query("SELECT * FROM offer_products WHERE is_active=1 ORDER BY name ASC")->fetchAll();
}

function teklif_decimal($value): float
{
    if (function_exists('decimal_from_input')) return decimal_from_input($value);
    $v = trim(str_replace(' ', '', (string)$value));
    $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return (float)$v;
}

function teklif_money(float $value): string
{
    return number_format($value, 2, ',', '.');
}

function teklif_load(int $id): ?array
{
    teklif_db_ensure();
    if ($id <= 0) return null;
    $stmt = db()->prepare('SELECT o.*, c.name AS cari_name FROM offers o LEFT JOIN cariler c ON c.id=o.cari_id WHERE o.id=? AND COALESCE(o.is_deleted,0)=0');
    $stmt->execute([$id]);
    $offer = $stmt->fetch();
    if (!$offer) return null;
    $stmt = db()->prepare('SELECT * FROM offer_items WHERE offer_id=? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$id]);
    $offer['items'] = $stmt->fetchAll();
    return $offer;
}

function teklif_parse_items_from_post(): array
{
    $names = is_array($_POST['product_name'] ?? null) ? $_POST['product_name'] : [];
    $types = is_array($_POST['product_type'] ?? null) ? $_POST['product_type'] : [];
    $qtys = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $prices = is_array($_POST['unit_price'] ?? null) ? $_POST['unit_price'] : [];
    $max = max(count($names), count($types), count($qtys), count($prices));
    $items = [];
    for ($i = 0; $i < $max; $i++) {
        $name = trim((string)($names[$i] ?? ''));
        $type = trim((string)($types[$i] ?? ''));
        $qty = teklif_decimal($qtys[$i] ?? '0');
        $price = teklif_decimal($prices[$i] ?? '0');
        if ($name === '' && $type === '' && $qty <= 0 && $price <= 0) continue;
        $line = $qty * $price;
        $items[] = ['product_name'=>$name, 'product_type'=>$type, 'quantity'=>$qty, 'unit_price'=>$price, 'line_total'=>$line];
    }
    return $items;
}

function teklif_save_product_suggestion(string $name, string $type = '', float $price = 0): void
{
    $name = trim($name);
    if ($name === '') return;
    try {
        db()->prepare("INSERT INTO offer_products (name, product_type, default_unit_price, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)
            ON CONFLICT(name) DO UPDATE SET product_type=CASE WHEN excluded.product_type!='' THEN excluded.product_type ELSE offer_products.product_type END, default_unit_price=CASE WHEN excluded.default_unit_price>0 THEN excluded.default_unit_price ELSE offer_products.default_unit_price END, updated_at=excluded.updated_at")
            ->execute([$name, $type, $price, now(), now()]);
    } catch (Throwable $e) {}
}

function teklif_save_from_post(int $id = 0): int
{
    teklif_db_ensure();
    $items = teklif_parse_items_from_post();
    $subtotal = 0.0;
    foreach ($items as $item) $subtotal += (float)$item['line_total'];
    $vatEnabled = isset($_POST['vat_enabled']) && (string)$_POST['vat_enabled'] === '1' ? 1 : 0;
    $vatRate = teklif_decimal($_POST['vat_rate'] ?? '10');
    if ($vatRate < 0) $vatRate = 0;
    $vatAmount = $vatEnabled ? ($subtotal * $vatRate / 100) : 0.0;
    $grandTotal = $subtotal + $vatAmount;

    $payload = [
        'offer_no' => trim((string)($_POST['offer_no'] ?? '')) ?: ('TV-' . date('Ymd-His')),
        'offer_date' => trim((string)($_POST['offer_date'] ?? date('Y-m-d'))) ?: date('Y-m-d'),
        'document_title' => trim((string)($_POST['document_title'] ?? 'TEKLİF FORMU')) ?: 'TEKLİF FORMU',
        'cari_id' => ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null,
        'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
        'customer_city' => trim((string)($_POST['customer_city'] ?? '')),
        'currency' => trim((string)($_POST['currency'] ?? 'TL')) ?: 'TL',
        'quantity_label' => trim((string)($_POST['quantity_label'] ?? 'DZ')) ?: 'DZ',
        'note' => trim((string)($_POST['note'] ?? '')),
        'footer_text' => trim((string)($_POST['footer_text'] ?? 'MALIMIZDAN HAYIR GÖRÜN.')),
        'term_text' => trim((string)($_POST['term_text'] ?? '')),
        'vat_enabled' => $vatEnabled,
        'vat_rate' => $vatRate,
        'subtotal' => $subtotal,
        'vat_amount' => $vatAmount,
        'grand_total' => $grandTotal,
    ];
    if ($payload['customer_name'] === '') throw new RuntimeException('Firma / müşteri adı boş olamaz.');
    if (!$items) throw new RuntimeException('En az bir ürün satırı girmelisin.');

    $pdo = db();
    if ($id > 0) {
        $old = teklif_load($id);
        if (!$old) throw new RuntimeException('Düzenlenecek teklif bulunamadı.');
        $stmt = $pdo->prepare('UPDATE offers SET offer_no=:offer_no, offer_date=:offer_date, document_title=:document_title, cari_id=:cari_id, customer_name=:customer_name, customer_city=:customer_city, currency=:currency, quantity_label=:quantity_label, note=:note, footer_text=:footer_text, term_text=:term_text, vat_enabled=:vat_enabled, vat_rate=:vat_rate, subtotal=:subtotal, vat_amount=:vat_amount, grand_total=:grand_total, updated_at=:updated_at WHERE id=:id');
        $payload['updated_at'] = now();
        $payload['id'] = $id;
        $stmt->execute($payload);
        $pdo->prepare('DELETE FROM offer_items WHERE offer_id=?')->execute([$id]);
        $offerId = $id;
        log_action('Teklif güncellendi', $payload['offer_no'] . ' ' . $payload['customer_name']);
        audit_action('teklif', $offerId, 'guncellendi', $old, $payload, $payload['offer_no']);
    } else {
        $stmt = $pdo->prepare('INSERT INTO offers (offer_no, offer_date, document_title, cari_id, customer_name, customer_city, currency, quantity_label, note, footer_text, term_text, vat_enabled, vat_rate, subtotal, vat_amount, grand_total, created_by, created_at, updated_at) VALUES (:offer_no, :offer_date, :document_title, :cari_id, :customer_name, :customer_city, :currency, :quantity_label, :note, :footer_text, :term_text, :vat_enabled, :vat_rate, :subtotal, :vat_amount, :grand_total, :created_by, :created_at, :updated_at)');
        $payload['created_by'] = current_user()['id'] ?? null;
        $payload['created_at'] = now();
        $payload['updated_at'] = now();
        $stmt->execute($payload);
        $offerId = (int)$pdo->lastInsertId();
        log_action('Teklif eklendi', $payload['offer_no'] . ' ' . $payload['customer_name']);
        audit_action('teklif', $offerId, 'eklendi', null, $payload, $payload['offer_no']);
    }

    $stmtItem = $pdo->prepare('INSERT INTO offer_items (offer_id, sort_order, product_name, product_type, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $idx => $item) {
        $stmtItem->execute([$offerId, $idx + 1, $item['product_name'], $item['product_type'], $item['quantity'], $item['unit_price'], $item['line_total']]);
        teklif_save_product_suggestion($item['product_name'], $item['product_type'], (float)$item['unit_price']);
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
