<?php
require_once __DIR__ . '/layout.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function hcs_kind_from_check(array $check, ?array $movement = null): string
{
    if (strcasecmp(trim((string)($check['bank_name'] ?? '')), 'Senet') === 0) return 'senet';
    if ($movement && stripos((string)($movement['payment_method'] ?? ''), 'SENET') !== false) return 'senet';
    return 'cek';
}

function hcs_linked_check(int $movementId): ?array
{
    if ($movementId <= 0) return null;
    $stmt = db()->prepare("SELECT ch.* FROM checks ch
        LEFT JOIN movements m ON m.id=?
        WHERE COALESCE(ch.is_cancelled,0)=0
          AND (ch.id=m.check_id OR ch.movement_id=?)
        ORDER BY CASE WHEN ch.id=m.check_id THEN 0 ELSE 1 END, ch.id ASC
        LIMIT 1");
    $stmt->execute([$movementId, $movementId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function hcs_recent_duplicate(PDO $pdo, int $cariId, string $type, float $amount, string $date, string $dueDate, string $paymentMethod, string $instrumentNo, int $userId): ?array
{
    $cutoff = date('Y-m-d H:i:s', time() - 180);
    $stmt = $pdo->prepare("SELECT ch.id AS check_id, ch.movement_id
        FROM checks ch
        JOIN movements m ON m.id=ch.movement_id
        WHERE COALESCE(ch.is_cancelled,0)=0
          AND COALESCE(m.is_cancelled,0)=0
          AND ch.cari_id=?
          AND m.movement_type=?
          AND ABS(ch.amount-?)<0.005
          AND COALESCE(ch.issue_date,'')=?
          AND ch.due_date=?
          AND UPPER(COALESCE(m.payment_method,''))=?
          AND TRIM(COALESCE(ch.check_no,''))=?
          AND COALESCE(ch.created_by,0)=?
          AND ch.created_at>=?
        ORDER BY ch.id DESC
        LIMIT 1");
    $stmt->execute([$cariId, $type, $amount, $date, $dueDate, strtoupper($paymentMethod), $instrumentNo, $userId, $cutoff]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$transactionOpen = false;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $movementId = (int)($_GET['movement_id'] ?? 0);
        if ($movementId <= 0) {
            echo json_encode(['ok'=>true, 'instrument'=>null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $stmt = db()->prepare('SELECT * FROM movements WHERE id=? LIMIT 1');
        $stmt->execute([$movementId]);
        $movement = $stmt->fetch() ?: null;
        if (!$movement) throw new RuntimeException('Hareket bulunamadı.');
        $check = hcs_linked_check($movementId);
        if (!$check) {
            echo json_encode(['ok'=>true, 'instrument'=>null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        echo json_encode([
            'ok'=>true,
            'instrument'=>[
                'kind'=>hcs_kind_from_check($check, $movement),
                'number'=>(string)($check['check_no'] ?? ''),
                'check_id'=>(int)$check['id'],
                'has_document'=>!empty($check['document_path']),
                'document_name'=>(string)($check['document_name'] ?? ''),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    require_write();
    require_csrf();

    $id = (int)($_POST['id'] ?? 0);
    $kind = trim((string)($_POST['instrument_kind'] ?? ''));
    $instrumentNo = trim((string)($_POST['instrument_no'] ?? ''));
    $type = trim((string)($_POST['movement_type'] ?? ''));
    $amount = decimal_from_input($_POST['amount'] ?? '0');
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'TL')));
    $date = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
    $dueDate = trim((string)($_POST['due_date'] ?? ''));
    $cariId = (int)($_POST['cari_id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));

    if (!in_array($kind, ['cek','senet'], true)) throw new RuntimeException('Çek veya senet türünü seçmelisin.');
    if (!in_array($type, ['tahsilat','odeme'], true)) throw new RuntimeException('Çek/senet için işlem türü Tahsilat veya Ödeme olmalı.');
    if ($amount <= 0) throw new RuntimeException('Tutarı kontrol etmelisin.');
    if ($currency !== 'TL') throw new RuntimeException('Çek/senet kaydı bu ekranda yalnızca TL olarak girilebilir.');
    if ($cariId <= 0) throw new RuntimeException('Çek/senet için cari seçmelisin.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('İşlem tarihini kontrol etmelisin.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) throw new RuntimeException('Çek/senet için vade tarihi zorunludur.');
    if ($instrumentNo === '') throw new RuntimeException($kind === 'senet' ? 'Senet numarasını yazmalısın.' : 'Çek numarasını yazmalısın.');
    if (function_exists('mb_substr')) $instrumentNo = mb_substr($instrumentNo, 0, 120, 'UTF-8');
    else $instrumentNo = substr($instrumentNo, 0, 120);

    $oldMovement = null;
    $oldCheck = null;
    $oldDoc = null;
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1');
        $stmt->execute([$id]);
        $oldMovement = $stmt->fetch() ?: null;
        if (!$oldMovement) throw new RuntimeException('Düzenlenecek hareket bulunamadı.');
        $oldCheck = hcs_linked_check($id);
        $oldDoc = [
            'path'=>$oldMovement['document_path'] ?? ($oldCheck['document_path'] ?? null),
            'name'=>$oldMovement['document_name'] ?? ($oldCheck['document_name'] ?? null),
            'mime'=>$oldMovement['document_mime'] ?? ($oldCheck['document_mime'] ?? null),
        ];
    }

    if ($categoryId <= 0) $categoryId = (int)(check_category_id(db()) ?: 0);
    $paymentMethod = $kind === 'senet' ? 'SENET' : 'ÇEK';
    $documentType = $kind === 'senet' ? 'senet_gorseli' : 'cek_gorseli';
    $direction = $type === 'odeme' ? 'verilecek' : 'alinacak';
    $userId = (int)(current_user()['id'] ?? 0);
    $pdo = db();

    // PDO SQLite, elle başlatılan BEGIN IMMEDIATE işlemini inTransaction()/commit()
    // ile güvenilir biçimde takip etmiyor. Kilidi yine BEGIN IMMEDIATE ile al,
    // fakat kapanışı da doğrudan SQL COMMIT/ROLLBACK ile yap.
    $pdo->exec('BEGIN IMMEDIATE');
    $transactionOpen = true;
    try {
        if ($id <= 0) {
            $duplicate = hcs_recent_duplicate($pdo, $cariId, $type, $amount, $date, $dueDate, $paymentMethod, $instrumentNo, $userId);
            if ($duplicate) {
                $pdo->exec('COMMIT');
                $transactionOpen = false;
                echo json_encode([
                    'ok'=>true,
                    'deduplicated'=>true,
                    'movement_id'=>(int)($duplicate['movement_id'] ?? 0),
                    'check_id'=>(int)($duplicate['check_id'] ?? 0),
                    'redirect'=>'hareketler.php',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        try {
            $doc = handle_upload('instrument_document', $oldDoc);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage());
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE movements SET cari_id=?, category_id=?, account_id=NULL, movement_type=?, amount=?, currency='TL',
                    movement_date=?, due_date=?, payment_method=?, description=?, document_type=?, document_path=?, document_name=?, document_mime=?, updated_at=?
                WHERE id=?")
                ->execute([$cariId, $categoryId ?: null, $type, $amount, $date, $dueDate, $paymentMethod, $description, $documentType,
                    $doc['path'], $doc['name'], $doc['mime'], now(), $id]);
            $movementId = $id;
        } else {
            $pdo->prepare("INSERT INTO movements
                (cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date, payment_method, description,
                 document_type, document_path, document_name, document_mime, created_by, created_at, updated_at)
                VALUES (?, ?, NULL, ?, ?, 'TL', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$cariId, $categoryId ?: null, $type, $amount, $date, $dueDate, $paymentMethod, $description,
                    $documentType, $doc['path'], $doc['name'], $doc['mime'], $userId ?: null, now(), now()]);
            $movementId = (int)$pdo->lastInsertId();
        }

        $check = $oldCheck ?: hcs_linked_check($movementId);
        $checkId = $check ? (int)$check['id'] : 0;
        $bankName = $kind === 'senet' ? 'Senet' : (($check && strcasecmp(trim((string)($check['bank_name'] ?? '')), 'Senet') !== 0) ? ($check['bank_name'] ?? null) : null);
        $status = $check ? (string)($check['status'] ?? 'bekliyor') : 'bekliyor';
        if ($status === '' || $status === 'iptal') $status = 'bekliyor';

        if ($checkId > 0) {
            $pdo->prepare("UPDATE checks SET cari_id=?, movement_id=?, direction=?, status=?, amount=?, issue_date=?, due_date=?, bank_name=?,
                    check_no=?, description=?, document_path=?, document_name=?, document_mime=?, updated_at=?, is_cancelled=0,
                    cancelled_at=NULL, cancelled_by=NULL, cancel_reason=NULL WHERE id=?")
                ->execute([$cariId, $movementId, $direction, $status, $amount, $date, $dueDate, $bankName,
                    $instrumentNo, $description, $doc['path'], $doc['name'], $doc['mime'], now(), $checkId]);
        } else {
            $pdo->prepare("INSERT INTO checks
                (cari_id, movement_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer,
                 description, document_path, document_name, document_mime, created_by, created_at, updated_at)
                VALUES (?, ?, ?, 'bekliyor', ?, ?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$cariId, $movementId, $direction, $amount, $date, $dueDate, $bankName, $instrumentNo,
                    $description, $doc['path'], $doc['name'], $doc['mime'], $userId ?: null, now(), now()]);
            $checkId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE movements SET check_id=?, account_id=NULL, updated_at=? WHERE id=?')
            ->execute([$checkId, now(), $movementId]);
        sync_movement_account_transaction($movementId);
        sync_check_account_transaction($checkId);

        $pdo->exec('COMMIT');
        $transactionOpen = false;
    } catch (Throwable $e) {
        if ($transactionOpen) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $rollbackError) {}
            $transactionOpen = false;
        }
        throw $e;
    }

    if ($id > 0) delete_replaced_upload($oldDoc, $doc);
    audit_action('hareket', $movementId, $id > 0 ? 'guncellendi' : 'eklendi', $oldMovement, [
        'type'=>$type,
        'amount'=>$amount,
        'cari_id'=>$cariId,
        'instrument_kind'=>$kind,
        'instrument_no'=>$instrumentNo,
        'check_id'=>$checkId,
        'due_date'=>$dueDate,
    ], $kind === 'senet' ? 'Senet' : 'Çek');
    audit_action('cek', $checkId, $oldCheck ? 'guncellendi' : 'eklendi', $oldCheck, [
        'movement_id'=>$movementId,
        'direction'=>$direction,
        'amount'=>$amount,
        'check_no'=>$instrumentNo,
        'instrument_kind'=>$kind,
        'has_document'=>!empty($doc['path']),
    ], $kind === 'senet' ? 'Senet' : 'Çek');
    log_action($kind === 'senet' ? 'Senet hareketi kaydedildi' : 'Çek hareketi kaydedildi', '#' . $movementId . ' / No: ' . $instrumentNo);

    echo json_encode([
        'ok'=>true,
        'movement_id'=>$movementId,
        'check_id'=>$checkId,
        'redirect'=>'hareketler.php',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($transactionOpen) {
        try { db()->exec('ROLLBACK'); } catch (Throwable $rollbackError) {}
        $transactionOpen = false;
    }
    $message = $e->getMessage();
    if (stripos($message, 'no active transaction') !== false) {
        $message = 'Çek/senet kaydı tamamlanırken işlem oturumu kapandı. Sayfayı yenileyip tekrar deneyin.';
    }
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
