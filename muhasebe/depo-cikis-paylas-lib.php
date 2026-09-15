<?php
require_once __DIR__.'/depo-cikis-lib.php';
require_once __DIR__.'/magaza-kullanici.php';

function depo_cikis_can_view(array $row): bool
{
    return can_access_warehouse_dispatch()
        && (!is_warehouse_user() || (int)($row['created_by']??0)===(int)(current_user()['id']??0));
}

function depo_cikis_share_schema(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS warehouse_dispatch_shares (
        token_hash TEXT PRIMARY KEY, dispatch_id INTEGER NOT NULL,
        fingerprint TEXT NOT NULL, expires_at INTEGER NOT NULL,
        FOREIGN KEY(dispatch_id) REFERENCES warehouse_dispatches(id) ON DELETE CASCADE
    )');
}

function depo_cikis_fingerprint(array $row): string
{
    return hash('sha256', serialize($row));
}

function depo_cikis_create_share(array $row): string
{
    if (!depo_cikis_can_view($row)) throw new RuntimeException('Bu fişi paylaşma yetkiniz yok.');
    depo_cikis_share_schema();
    $token=bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM warehouse_dispatch_shares WHERE expires_at<=?')->execute([time()]);
    db()->prepare('INSERT INTO warehouse_dispatch_shares(token_hash,dispatch_id,fingerprint,expires_at) VALUES(?,?,?,?)')
        ->execute([hash('sha256',$token),(int)$row['id'],depo_cikis_fingerprint($row),time()+7*86400]);
    return $token;
}

function depo_cikis_shared_row(string $token): ?array
{
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$token)) return null;
    depo_cikis_share_schema();
    $s=db()->prepare('SELECT dispatch_id,fingerprint FROM warehouse_dispatch_shares WHERE token_hash=? AND expires_at>?');
    $s->execute([hash('sha256',$token),time()]);
    $share=$s->fetch();
    if (!$share) return null;
    $row=depo_cikis_load((int)$share['dispatch_id']);
    return $row && hash_equals($share['fingerprint'],depo_cikis_fingerprint($row)) ? $row : null;
}

function depo_cikis_share_form(int $id): void
{
    echo '<form id="wdShare'.$id.'" method="post" action="depo-cikis-paylas.php" target="_blank" rel="noopener noreferrer">'
        .csrf_field().'<input type="hidden" name="id" value="'.$id.'"></form>';
}

function depo_cikis_share_button(int $id): void
{
    echo '<button type="submit" form="wdShare'.$id.'" title="Fişe özel PDF bağlantısı 7 gün geçerlidir; bağlantıyı alan kişi fişi açabilir.">WhatsApp ile paylaş</button>';
}
