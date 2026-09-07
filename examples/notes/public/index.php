<?php
require __DIR__ . '/../../../vendor/autoload.php';

// .env が無くても動くように、サンプルではデバッグとマイグレーションを明示 ON にしている
$app = new Washi\App(dirname(__DIR__), ['debug' => true, 'auto_migrate' => true]);
$app->run();
