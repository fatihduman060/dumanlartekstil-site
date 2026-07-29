<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

function chc_table_exists(string $table): bool
{
    try {
        $stmt = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function chc_source_for_movement(int $movementId): array
{
    $sources = [];

    if ($movementId > 0 && chc_table_exists('offers')) {
        try {
            $stmt = db()->prepare("SELECT id, offer_no, document_title FROM offers WHERE cari_movement_id=? AND COALESCE(is_deleted,0)=0 LIMIT 2");
            $stmt->execute([$movementId]);
            foreach ($stmt->fetchAll() as $row) {
                $title = trim((string)($row['document_title'] ?? '')) ?: 'Teklif / sipariş fişi';
                $no = trim((string)($row['offer_no'] ?? ''));
                $sources[] = [
                    'type' => 'offer',
                    'table' => 'offers',
                    'id' => (int)$row['id'],
                    'label' => $title . ($no !== '' ? ' no: ' . $no : ''),
                ];
            }
        } catch (Throwable $e) {}
    }

    if ($movementId > 0 && chc_table_exists('invoices')) {
        try {
            $stmt = db()->prepare("SELECT id, invoice_no, direction FROM invoices WHERE cari_movement_id=? AND COALESCE(is_cancelled,0)=0 LIMIT 2");
            $stmt->execute([$movementId]);
            foreach ($stmt->fetchAll() as $row) {
                $no = trim((string)($row['invoice_no'] ?? ''));
                $label = ((string)($row['direction'] ?? 'gelen') === 'giden' ? 'Giden fatura' : 'Gelen fatura');
                $sources[] = [
                    'type' => 'invoice',
                    'table' => 'invoices',
                    'id' => (int)$row['id'],
                    'label' => $label . ($no !== '' ? ' no: ' . $no : ''),
                ];
            }
        } catch (Throwable $e) {}
    }

    return $sources;
}

function chc_movement(int $movementId): ?array
{
    if ($movementId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
    $stmt->execute([$movementId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $rawIds = (string)($_GET['ids'] ?? $_GET['id'] ?? '');
    $ids = [];
    foreach (preg_split('/[^0-9]+/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $value) {
        $value = (int)$value;
        if ($value > 0) $ids[$value] = $value;
        if (count($ids) >= 500) break;
    }

    $out = [];
    foreach ($ids as $movementId) {
        $movement = chc_movement($movementId);
        $sources = $movement ? chc_source_for_movement($movementId) : [];
        $active = $movement && (int)($movement['is_cancelled'] ?? 0) === 0;
        $hasCheck = $movement && (int)($movement['check_id'] ?? 0) > 0;
        $eligible = $active && !$hasCheck && count($sources) === 1;
        $out[(string)$movementId] = [
            'eligible' => $eligible,
            'source_type' => $eligible ? $sources[0]['type'] : '',
            'source_label' => $eligible ? $sources[0]['label'] : '',
        ];
    }

    echo json_encode(['items'=>$out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_write();
require_csrf();

$movementId = (int)($_POST['movement_id'] ?? 0);
$cariId = (int)($_POST['cari_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? 'Mükerrer kayıt nedeniyle cariden çıkarıldı'));
if ($reason === '') $reason = 'Mükerrer kayıt nedeniyle cariden çıkarıldı';
$redirectUrl = 'cari-detay.php?id=' . $cariId . '#hareketler';

try {
    $movement = chc_movement($movementId);
    if (!$movement || (int)($movement['cari_id'] ?? 0) !== $cariId) {
        throw new RuntimeException('Cari hareketi bulunamadı.');
    }
    if ((int)($movement['is_cancelled'] ?? 0) === 1) {
        throw new RuntimeException('Bu hareket zaten cariden çıkarılmış veya iptal edilmiş.');
    }
    if ((int)($movement['check_id'] ?? 0) > 0) {
        throw new RuntimeException('Çeke bağlı hareket bu ekrandan cariden çıkarılamaz. Çek kaydından işlem yapılmalıdır.');
    }

    $sources = chc_source_for_movement($movementId);
    if (count($sources) !== 1) {
        throw new RuntimeException('Bu hareketin bağlı teklif veya fatura kaydı güvenli biçimde belirlenemedi. İşlem yapılmadı.');
    }
    $source = $sources[0];
    $now = now();
    $userId = current_user()['id'] ?? null;
    $pdo = db();
    $pdo->beginTransaction();

    $pdo->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0')
        ->execute([$now, $userId, $reason, $now, $movementId]);

    if ($source['type'] === 'offer') {
        $pdo->prepare('UPDATE offers SET cari_movement_id=NULL, posted_to_cari=0, posted_at=NULL, posted_by=NULL, updated_at=? WHERE id=? AND cari_movement_id=?')
            ->execute([$now, $source['id'], $movementId]);
    } elseif ($source['type'] === 'invoice') {
        $pdo->prepare('UPDATE invoices SET cari_movement_id=NULL, posted_to_cari=0, posted_at=NULL, posted_by=NULL, updated_at=? WHERE id=? AND cari_movement_id=?')
            ->execute([$now, $source['id'], $movementId]);
    }

    sync_movement_account_transaction($movementId);

    audit_action('hareket', $movementId, 'cariden_cikarildi', $movement, [
        'is_cancelled'=>1,
        'cancel_reason'=>$reason,
        'source_type'=>$source['type'],
        'source_id'=>$source['id'],
    ], $source['label']);
    audit_action($source['type'] === 'offer' ? 'teklif' : 'fatura', $source['id'], 'cariden_cikarildi', [
        'cari_movement_id'=>$movementId,
        'posted_to_cari'=>1,
    ], [
        'cari_movement_id'=>null,
        'posted_to_cari'=>0,
    ], $source['label']);
    log_action('Belge cariden çıkarıldı', $source['label'] . ' · hareket #' . $movementId . ' · ' . money((float)($movement['amount'] ?? 0)));

    $pdo->commit();
    flash('success', $source['label'] . ' cari bakiyeden çıkarıldı. Belge silinmedi; hareket iptal geçmişinde korunuyor.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    flash('error', $e->getMessage());
}

redirect($redirectUrl);
