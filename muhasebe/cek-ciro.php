<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cek_ciro_schema_ensure(): void
{
    $pdo = db();
    ensure_column($pdo, 'checks', 'endorsed_to_cari_id', 'INTEGER');
    ensure_column($pdo, 'checks', 'endorsement_movement_id', 'INTEGER');
    ensure_column($pdo, 'checks', 'endorsed_at', 'TEXT');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checks_endorsed_to_cari ON checks(endorsed_to_cari_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checks_endorsement_movement ON checks(endorsement_movement_id)');
}

function cek_ciro_check(int $checkId): ?array
{
    if ($checkId <= 0) return null;
    $stmt = db()->prepare("SELECT ch.*, c.name AS source_cari_name, ec.name AS endorsed_cari_name
        FROM checks ch
        LEFT JOIN cariler c ON c.id=ch.cari_id
        LEFT JOIN cariler ec ON ec.id=ch.endorsed_to_cari_id
        WHERE ch.id=? LIMIT 1");
    $stmt->execute([$checkId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cek_ciro_target_cari(int $cariId): ?array
{
    if ($cariId <= 0) return null;
    $stmt = db()->prepare('SELECT id, name FROM cariler WHERE id=? LIMIT 1');
    $stmt->execute([$cariId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cek_ciro_description(array $check, array $target): string
{
    $parts = ['Ciro edilen müşteri çeki'];
    if (!empty($check['check_no'])) $parts[] = 'Çek no: ' . trim((string)$check['check_no']);
    if (!empty($check['source_cari_name'])) $parts[] = 'Alınan cari: ' . trim((string)$check['source_cari_name']);
    $parts[] = 'Ciro edilen cari: ' . trim((string)$target['name']);
    return implode(' / ', $parts);
}

function cek_ciro_bank_account_ok(?int $accountId): bool
{
    if (!$accountId) return false;
    $stmt = db()->prepare("SELECT COUNT(*) FROM accounts WHERE id=? AND account_type='banka' AND is_active=1");
    $stmt->execute([$accountId]);
    return (int)$stmt->fetchColumn() > 0;
}

cek_ciro_schema_ensure();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $checkId = (int)($_GET['check_id'] ?? 0);
        $check = cek_ciro_check($checkId);
        if (!$check) throw new RuntimeException('Çek bulunamadı.');
        echo json_encode([
            'ok'=>true,
            'check_id'=>$checkId,
            'status'=>(string)($check['status'] ?? 'bekliyor'),
            'source_cari_id'=>(int)($check['cari_id'] ?? 0),
            'source_cari_name'=>(string)($check['source_cari_name'] ?? ''),
            'endorsed_to_cari_id'=>(int)($check['endorsed_to_cari_id'] ?? 0),
            'endorsed_cari_name'=>(string)($check['endorsed_cari_name'] ?? ''),
            'endorsed_at'=>(string)($check['endorsed_at'] ?? ''),
            'amount'=>(float)($check['amount'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!can_write()) throw new RuntimeException('Bu işlem için düzenleme yetkin yok.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar dene.');

    $action = trim((string)($_POST['action'] ?? 'endorse'));
    $checkId = (int)($_POST['check_id'] ?? 0);
    $check = cek_ciro_check($checkId);
    if (!$check || (int)($check['is_cancelled'] ?? 0) === 1) throw new RuntimeException('Aktif çek bulunamadı.');
    if ((string)($check['direction'] ?? '') !== 'alinacak') throw new RuntimeException('Yalnızca müşteriden alınan çek ciro edilebilir.');

    $pdo = db();
    $userId = current_user()['id'] ?? null;

    if ($action === 'endorse') {
        $targetCariId = (int)($_POST['cari_id'] ?? 0);
        $target = cek_ciro_target_cari($targetCariId);
        if (!$target) throw new RuntimeException('Ciro edilecek cariyi seçmelisin.');
        if ($targetCariId === (int)($check['cari_id'] ?? 0)) throw new RuntimeException('Çeki aldığımız cari ile ciro edeceğimiz cari aynı olamaz.');

        $currentStatus = (string)($check['status'] ?? 'bekliyor');
        if (!in_array($currentStatus, ['bekliyor','ciro_edildi'], true)) {
            throw new RuntimeException('Bu çek şu anda ' . $currentStatus . ' durumunda. Ciro için önce Bekliyor durumuna almalısın.');
        }

        $endorsementDate = trim((string)($_POST['endorsement_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endorsementDate) || strtotime($endorsementDate) === false) {
            throw new RuntimeException('Ciro tarihini kontrol etmelisin.');
        }

        $categoryId = (int)(check_category_id($pdo) ?: 0);
        $description = cek_ciro_description($check, $target);
        $movementId = (int)($check['endorsement_movement_id'] ?? 0);
        $existingMovement = null;
        if ($movementId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
            $stmt->execute([$movementId]);
            $existingMovement = $stmt->fetch() ?: null;
            if (!$existingMovement || (int)($existingMovement['is_cancelled'] ?? 0) === 1) {
                $movementId = 0;
                $existingMovement = null;
            }
        }

        $pdo->beginTransaction();
        try {
            if ($movementId > 0) {
                $pdo->prepare("UPDATE movements SET cari_id=?, category_id=?, account_id=NULL, movement_type='odeme', amount=?, movement_date=?, due_date=NULL,
                        payment_method='ÇEK CİRO', description=?, is_cancelled=0, cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL, updated_at=?
                    WHERE id=?")
                    ->execute([$targetCariId, $categoryId ?: null, (float)$check['amount'], $endorsementDate, $description, now(), $movementId]);
            } else {
                $pdo->prepare("INSERT INTO movements
                    (cari_id, category_id, account_id, movement_type, amount, movement_date, due_date, payment_method, description, created_by, created_at, updated_at, is_cancelled)
                    VALUES (?, ?, NULL, 'odeme', ?, ?, NULL, 'ÇEK CİRO', ?, ?, ?, ?, 0)")
                    ->execute([$targetCariId, $categoryId ?: null, (float)$check['amount'], $endorsementDate, $description, $userId, now(), now()]);
                $movementId = (int)$pdo->lastInsertId();
            }

            sync_movement_account_transaction($movementId);
            $pdo->prepare("UPDATE checks SET status='ciro_edildi', closed_at=?, account_id=NULL, endorsed_to_cari_id=?, endorsement_movement_id=?, endorsed_at=?, updated_at=? WHERE id=?")
                ->execute([$endorsementDate, $targetCariId, $movementId, $endorsementDate, now(), $checkId]);
            sync_check_to_movement($checkId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        audit_action('cek', $checkId, 'ciro', $check, [
            'status'=>'ciro_edildi',
            'endorsed_to_cari_id'=>$targetCariId,
            'endorsement_movement_id'=>$movementId,
            'endorsed_at'=>$endorsementDate,
        ], 'Ciro: ' . $target['name']);
        audit_action('hareket', $movementId, $existingMovement ? 'guncellendi' : 'eklendi', $existingMovement, [
            'type'=>'odeme',
            'amount'=>(float)$check['amount'],
            'cari_id'=>$targetCariId,
            'payment_method'=>'ÇEK CİRO',
        ], 'Çek cirosu');
        log_action('Çek ciro edildi', '#' . $checkId . ' → ' . $target['name'] . ' / ' . money($check['amount']));

        echo json_encode([
            'ok'=>true,
            'message'=>'Çek ciro edildi. ' . $target['name'] . ' cari borcundan ' . money($check['amount']) . ' düşüldü.',
            'movement_id'=>$movementId,
            'cari_id'=>$targetCariId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'reverse') {
        if ((string)($check['status'] ?? '') !== 'ciro_edildi') throw new RuntimeException('Bu çek ciro edilmiş durumda değil.');
        $newStatus = trim((string)($_POST['new_status'] ?? 'bekliyor'));
        $allowed = ['bekliyor','bankaya_verildi','tahsil_edildi','iade','karsiliksiz','protestolu'];
        if (!in_array($newStatus, $allowed, true)) $newStatus = 'bekliyor';
        if (in_array($newStatus, ['bankaya_verildi','tahsil_edildi'], true)
            && !cek_ciro_bank_account_ok(!empty($check['account_id']) ? (int)$check['account_id'] : null)) {
            throw new RuntimeException('Ciroyu geri aldıktan sonra bankaya/tahsile geçmek için önce çek düzenle bölümünden tahsil bankasını seçmelisin.');
        }

        $movementId = (int)($check['endorsement_movement_id'] ?? 0);
        $oldMovement = null;
        if ($movementId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
            $stmt->execute([$movementId]);
            $oldMovement = $stmt->fetch() ?: null;
        }
        $closedAt = in_array($newStatus, ['bekliyor','bankaya_verildi'], true) ? null : date('Y-m-d');

        $pdo->beginTransaction();
        try {
            if ($oldMovement && (int)($oldMovement['is_cancelled'] ?? 0) === 0) {
                $pdo->prepare("UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?")
                    ->execute([now(), $userId, 'Çek cirosu geri alındı', now(), $movementId]);
                sync_movement_account_transaction($movementId);
            }
            $pdo->prepare("UPDATE checks SET status=?, closed_at=?, endorsed_to_cari_id=NULL, endorsement_movement_id=NULL, endorsed_at=NULL, updated_at=? WHERE id=?")
                ->execute([$newStatus, $closedAt, now(), $checkId]);
            sync_check_to_movement($checkId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        audit_action('cek', $checkId, 'ciro_geri_alindi', $check, ['status'=>$newStatus], 'Çek cirosu geri alındı');
        if ($oldMovement) audit_action('hareket', $movementId, 'iptal', $oldMovement, ['is_cancelled'=>1], 'Çek cirosu geri alındı');
        log_action('Çek cirosu geri alındı', '#' . $checkId . ' → ' . $newStatus);

        echo json_encode(['ok'=>true,'message'=>'Ciro geri alındı. Cari ödeme hareketi iptal edildi.','status'=>$newStatus], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new RuntimeException('Geçersiz işlem.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
