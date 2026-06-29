<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function cd_add_cols(string $table): void
{
    try {
        ensure_column(db(), $table, 'posted_to_cari', 'INTEGER NOT NULL DEFAULT 0');
        ensure_column(db(), $table, 'cari_movement_id', 'INTEGER');
        ensure_column(db(), $table, 'posted_at', 'TEXT');
        ensure_column(db(), $table, 'posted_by', 'INTEGER');
    } catch (Throwable $e) {}
}

function cd_active_movement(int $id): bool
{
    if ($id <= 0) return false;
    try {
        $s = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
        $s->execute([$id]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) { return false; }
}

$source = (string)($_GET['source_type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$out = ['posted'=>false, 'movement_id'=>0];
try {
    if ($source === 'offer') {
        cd_add_cols('offers');
        $s = db()->prepare('SELECT cari_movement_id FROM offers WHERE id=? AND COALESCE(is_deleted,0)=0');
        $s->execute([$id]);
        $mid = (int)($s->fetchColumn() ?: 0);
        $out = ['posted'=>cd_active_movement($mid), 'movement_id'=>$mid];
    } elseif ($source === 'tahsilat') {
        cd_add_cols('collection_receipts');
        $s = db()->prepare('SELECT cari_movement_id FROM collection_receipts WHERE id=? AND COALESCE(is_deleted,0)=0');
        $s->execute([$id]);
        $mid = (int)($s->fetchColumn() ?: 0);
        $out = ['posted'=>cd_active_movement($mid), 'movement_id'=>$mid];
    }
} catch (Throwable $e) {}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
