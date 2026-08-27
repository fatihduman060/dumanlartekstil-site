<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
if (!can_manage_store_sales()) { http_response_code(403); exit('Yetkisiz işlem.'); }
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="Dumanlar-Magaza-Kasa.cmd"');
header('Cache-Control: no-store');
$url = 'https://bitke.com.tr/muhasebe/barkod-satis.php';
echo "@echo off\r\n";
echo "setlocal\r\n";
echo "set \"KASA_URL=" . $url . "\"\r\n";
echo "set \"KASA_PROFILE=%LOCALAPPDATA%\\DumanlarMagazaKasa\"\r\n";
echo "set \"CHROME=%ProgramFiles%\\Google\\Chrome\\Application\\chrome.exe\"\r\n";
echo "if exist \"%CHROME%\" goto chrome\r\n";
echo "set \"CHROME=%ProgramFiles(x86)%\\Google\\Chrome\\Application\\chrome.exe\"\r\n";
echo "if exist \"%CHROME%\" goto chrome\r\n";
echo "set \"EDGE=%ProgramFiles(x86)%\\Microsoft\\Edge\\Application\\msedge.exe\"\r\n";
echo "if exist \"%EDGE%\" goto edge\r\n";
echo "echo Chrome veya Microsoft Edge bulunamadi.\r\n";
echo "pause\r\n";
echo "exit /b 1\r\n";
echo ":chrome\r\n";
echo "start \"\" \"%CHROME%\" --app=\"%KASA_URL%\" --kiosk-printing --user-data-dir=\"%KASA_PROFILE%\"\r\n";
echo "exit /b 0\r\n";
echo ":edge\r\n";
echo "start \"\" \"%EDGE%\" --app=\"%KASA_URL%\" --kiosk-printing --user-data-dir=\"%KASA_PROFILE%\"\r\n";
echo "exit /b 0\r\n";
