<h1><?= h($status) ?> <?= h(Washi\HttpException::title($status)) ?></h1>
<p><?= h($message) ?></p>
<?php if ($exception): ?><pre style="white-space:pre-wrap"><?= h($exception->getTraceAsString()) ?></pre><?php endif; ?>
<p><a href="<?= url('/') ?>">ホームへ</a></p>
