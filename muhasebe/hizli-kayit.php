<?php
require_once __DIR__ . '/bootstrap.php';
flash('info', 'Hızlı kayıt ekranı sadeleştirme için kaldırıldı. İşlemleri Hareketler, Çekler veya Belgeler ekranından yapabilirsiniz.');
redirect('hareketler.php');
