<?php
require __DIR__ . '/../../../vendor/autoload.php';

// .env が無くても動くように、サンプルではデバッグとマイグレーションを明示 ON にしている
$app = new Washi\App(dirname(__DIR__), ['debug' => true, 'auto_migrate' => true]);
// return しているのは、PHP 組み込みサーバーのルータとして動くときに
// 実ファイル（css・画像など）は false を返してサーバー自身に配信させるため
return $app->run();
