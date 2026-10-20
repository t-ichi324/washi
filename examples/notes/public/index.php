<?php
require __DIR__ . '/../../../vendor/autoload.php';

// APP_DEBUG / DB_AUTO_MIGRATE は ../.env.defaults（上書きは ../.env）。環境で変わる値は配列に書かない（配列は env より強い）
$app = new Washi\App(dirname(__DIR__));
// return しているのは、PHP 組み込みサーバーのルータとして動くときに
// 実ファイル（css・画像など）は false を返してサーバー自身に配信させるため
return $app->run();
