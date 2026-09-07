<h1><?= h($title) ?></h1>
<form method="post">
  <?= csrf_field() ?>
  <label>タイトル</label>
  <input type="text" name="title" value="<?= old('title', $note->title) ?>">
  <?= err('title') ?>
  <label>本文</label>
  <textarea name="body" rows="6"><?= old('body', $note->body) ?></textarea>
  <p><button class="btn">保存</button> <a href="<?= url('/') ?>">キャンセル</a></p>
</form>
