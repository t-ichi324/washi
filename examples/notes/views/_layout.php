<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title ?? 'Notes') ?></title>
<style>
body{font-family:system-ui,sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;color:#1e293b}
a{color:#0369a1}.btn{display:inline-block;padding:.4rem .9rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;text-decoration:none;cursor:pointer}
.flash{padding:.6rem 1rem;border-radius:6px;margin:.5rem 0}.flash.success{background:#dcfce7}.flash.info{background:#e0f2fe}.flash.error{background:#fee2e2}
.form-error{color:#b91c1c;margin:.2rem 0 0;font-size:.9rem}label{display:block;margin-top:1rem;font-weight:600}
input[type=text],textarea{width:100%;box-sizing:border-box;padding:.5rem;border:1px solid #cbd5e1;border-radius:6px;font:inherit}
.note{border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin:.75rem 0}.note h3{margin:0 0 .3rem}.meta{color:#64748b;font-size:.85rem}
</style>
</head>
<body>
<header><a href="<?= url('/') ?>">📝 Notes</a> · <a href="<?= url('/notes/new') ?>">新規</a> · <a href="<?= url('/api/notes') ?>">API</a> · <a href="<?= url('/about') ?>">About</a></header>
<?php foreach (flashes() as $m): ?>
  <div class="flash <?= h($m['type']) ?>"><?= h($m['text']) ?></div>
<?php endforeach; ?>
<main><?= $content ?></main>
</body>
</html>
