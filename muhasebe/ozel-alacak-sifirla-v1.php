<?php

/**
 * Özel alacak kayıtlarını tek seferlik olarak aktif listeden temizler.
 *
 * Güvenlik sınırı:
 * - Yalnızca private_receivables tablosuna dokunur.
 * - checks, movements, account_transactions ve cari bakiyeler değiştirilmez.
 * - Silinen satırlar geri dönüş için arşiv tablosuna kopyalanır.
 */
function ozel_alacaklari_tek_seferlik_sifirla_v1(): void
{
    $migrationKey = 'migration_private_receivables_reset_20260725_v1';
    if (setting_get($migrationKey, '0') === '1') return;

    $pdo = db();
    $archiveTable = 'private_receivables_reset_archive_20260725';
    $batch = '20260725-v1';
    $archivedAt = now();

    $pdo->beginTransaction();
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$archiveTable} (
            archive_id INTEGER PRIMARY KEY AUTOINCREMENT,
            original_id INTEGER,
            cari_id INTEGER,
            status TEXT,
            amount REAL,
            receivable_date TEXT,
            description TEXT,
            document_type TEXT,
            document_path TEXT,
            document_name TEXT,
            document_mime TEXT,
            created_by INTEGER,
            created_at TEXT,
            updated_at TEXT,
            reset_batch TEXT NOT NULL,
            archived_at TEXT NOT NULL
        )");

        $summary = $pdo->query('SELECT COUNT(*) AS total_count, COALESCE(SUM(amount),0) AS total_amount FROM private_receivables')->fetch();
        $count = (int)($summary['total_count'] ?? 0);
        $amount = (float)($summary['total_amount'] ?? 0);

        if ($count > 0) {
            $archiveStmt = $pdo->prepare("INSERT INTO {$archiveTable}
                (original_id, cari_id, status, amount, receivable_date, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at, reset_batch, archived_at)
                SELECT id, cari_id, status, amount, receivable_date, description, document_type, document_path, document_name, document_mime, created_by, created_at, updated_at, ?, ?
                FROM private_receivables");
            $archiveStmt->execute([$batch, $archivedAt]);

            // Yalnızca özel alacaklar temizlenir. Çek ve cari hareket tablolarına dokunulmaz.
            $pdo->exec('DELETE FROM private_receivables');
        }

        $settingStmt = $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, '1', ?)
            ON CONFLICT(key) DO UPDATE SET value='1', updated_at=excluded.updated_at");
        $settingStmt->execute([$migrationKey, $archivedAt]);

        $pdo->commit();

        if ($count > 0) {
            log_action('Özel alacakların tamamı sıfırlandı', $count . ' kayıt · ' . money($amount) . ' · Çek kayıtlarına dokunulmadı');
            audit_action('ozel_alacak', null, 'silindi', [
                'kayit_sayisi' => $count,
                'toplam_tutar' => $amount,
                'arsiv_tablosu' => $archiveTable,
            ], [
                'kayit_sayisi' => 0,
                'toplam_tutar' => 0,
            ], 'Tüm özel alacaklar güvenli arşive alınarak sıfırlandı; alınan/verilen çekler korunmuştur.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

try {
    ozel_alacaklari_tek_seferlik_sifirla_v1();
} catch (Throwable $e) {
    // Muhasebe ekranını kilitlememek için hata sessizce loglanır; işlem tamamlanmadıysa sonraki istekte tekrar denenir.
    try {
        log_action('Özel alacak sıfırlama başarısız', $e->getMessage());
    } catch (Throwable $ignored) {}
}
