<?php

function pos_product_source_harden(): void
{
    $pdo = db();
    ensure_column($pdo, 'pos_products', 'product_source', "TEXT NOT NULL DEFAULT 'pos'");

    $migrationKey = 'migration_pos_offer_product_source_v2';
    if (setting_get($migrationKey, '0') === '1') return;

    $offerProductsExist = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='offer_products' LIMIT 1")->fetchColumn();
    if (!$offerProductsExist) {
        setting_set($migrationKey, '1');
        return;
    }

    // Teklif ekranından eski otomatik aktarım ile gelen kayıtları ayır.
    $pdo->exec("UPDATE pos_products
        SET product_source='offer_import', is_active=0, updated_at=COALESCE(updated_at, datetime('now'))
        WHERE COALESCE(created_by,0)=0
          AND COALESCE(product_source,'pos') IN ('pos','offer_import')
          AND EXISTS (
              SELECT 1 FROM offer_products op
              WHERE TRIM(COALESCE(op.barcode,''))=TRIM(COALESCE(pos_products.barcode,''))
                AND TRIM(COALESCE(op.name,''))=TRIM(COALESCE(pos_products.name,''))
                AND ABS(COALESCE(op.default_unit_price,0)-COALESCE(pos_products.sale_price,0))<0.01
          )");

    // Hiç POS satışında kullanılmamış otomatik teklif kopyaları güvenle kaldırılabilir.
    // Kullanılmış olanlar fiş geçmişinin FK bağlantısı bozulmasın diye pasif tutulur.
    $pdo->exec("DELETE FROM pos_products
        WHERE product_source='offer_import'
          AND NOT EXISTS (SELECT 1 FROM pos_sale_items psi WHERE psi.product_id=pos_products.id)");

    setting_set($migrationKey, '1');
    log_action('Barkodlu satış ürün havuzu ayrıldı', 'Teklif ürünlerinin otomatik POS aktarımı kapatıldı; eski teklif kopyaları gizlendi/temizlendi.');
}
