<?php
return page()->csrf()->then(function (int $id, Washi\Request $r) {
    $note = Note::findOrFail($id);
    if ($r->str('title') === '') return back(['title' => 'タイトルは必須です']);
    $note->merge($r->only(['title', 'body']))->save();
    flash('success', '更新しました');
    return redirect('/');
});
