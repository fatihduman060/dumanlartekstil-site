<?php

function muhasebe_kredi_kartlari(): array
{
    return [
        'garanti_9029' => [
            'name' => 'Garanti Bankası Kredi Kartı •••• 9029',
            'bank_name' => 'Garanti BBVA',
            'last4' => '9029',
        ],
        'garanti_1018' => [
            'name' => 'Garanti Bankası Kredi Kartı •••• 1018',
            'bank_name' => 'Garanti BBVA',
            'last4' => '1018',
        ],
        'isbank_3833' => [
            'name' => 'İş Bankası Kredi Kartı •••• 3833',
            'bank_name' => 'Türkiye İş Bankası',
            'last4' => '3833',
        ],
        'ziraat_7754' => [
            'name' => 'Ziraat Bankası Kredi Kartı •••• 7754',
            'bank_name' => 'T.C. Ziraat Bankası',
            'last4' => '7754',
        ],
        'ziraat_4091' => [
            'name' => 'Ziraat Bankası Kredi Kartı •••• 4091',
            'bank_name' => 'T.C. Ziraat Bankası',
            'last4' => '4091',
        ],
        'kuveyt_4357' => [
            'name' => 'Kuveyt Türk Kredi Kartı •••• 4357',
            'bank_name' => 'Kuveyt Türk Katılım Bankası',
            'last4' => '4357',
        ],
        'vakif_1125' => [
            'name' => 'VakıfBank Kredi Kartı •••• 1125',
            'bank_name' => 'VakıfBank',
            'last4' => '1125',
        ],
    ];
}

function muhasebe_kredi_karti(?string $key): ?array
{
    $key = trim((string)$key);
    $cards = muhasebe_kredi_kartlari();
    if ($key === '' || !isset($cards[$key])) return null;
    $card = $cards[$key];
    $card['key'] = $key;
    return $card;
}
