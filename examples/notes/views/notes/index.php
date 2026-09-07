<h1><?= h($title) ?></h1>
<?php if ($notes->isEmpty()): ?>
  <p>まだメモがありません。<a href="<?= url('/notes/new') ?>">最初のメモを書く</a></p>
<?php endif; ?>
<?php foreach ($notes as $note): ?>
  <div class="note">
    <h3><a href="<?= url("/notes/edit/{$note->id}") ?>"><?= h($note->title) ?></a></h3>
    <div><?= nl2br(h($note->body)) ?></div>
    <div class="meta"><?= h($note->created_at) ?>
      <form method="post" action="<?= url("/notes/delete/{$note->id}") ?>" style="display:inline" onsubmit="return confirm('削除しますか？')">
        <?= csrf_field() ?><button class="btn">削除</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
