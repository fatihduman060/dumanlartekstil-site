<?php

function rapor_manuel_ozet_hazirla(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS report_manual_monthly_totals (
        period TEXT PRIMARY KEY,
        income_amount REAL NOT NULL DEFAULT 0,
        expense_amount REAL NOT NULL DEFAULT 0,
        note TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )");

    $rows = [
        ['2026-01', 487233.00, 4061325.66],
        ['2026-02', 1265865.00, 3382544.00],
        ['2026-03', 458700.00, 3967992.00],
        ['2026-04', 1084200.00, 4004629.00],
        ['2026-05', 1388075.00, 3571816.00],
        ['2026-06', 595000.00, 3795888.00],
    ];
    $insert = db()->prepare('INSERT OR IGNORE INTO report_manual_monthly_totals (period,income_amount,expense_amount,note,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    foreach ($rows as $row) {
        $insert->execute([$row[0], $row[1], $row[2], 'Kullanıcı tarafından bildirilen geçmiş dönem rapor özeti', now(), now()]);
    }
}

function rapor_manuel_ozet_yil(int $year): array
{
    rapor_manuel_ozet_hazirla();
    $stmt = db()->prepare('SELECT period,income_amount,expense_amount FROM report_manual_monthly_totals WHERE period BETWEEN ? AND ? ORDER BY period');
    $stmt->execute([sprintf('%04d-01', $year), sprintf('%04d-12', $year)]);
    return $stmt->fetchAll() ?: [];
}
