<?php
// POST /notes/new — 保存。CSRF 検証はチェーンで宣言する。
return page()->csrf()->then(function (Washi\Request $r) {
    $title = $r->str('title');
    if ($title === '') return back(['title' => 'タイトルは必須です']);

    $note = Note::from($r->only(['title', 'body']));
    $note->save();
    flash('success', "「{$note->title}」を保存しました");
    return redirect('/');
});
