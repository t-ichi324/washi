<?php
// POST /notes/new — 保存。CSRF 検証はチェーンで宣言する。
// validate() が失敗すると、エラーと入力値を flash して元のフォームへ戻る（JSON を求める相手には 422）。
return page()->csrf()->then(function (Washi\Request $r) {
    $data = $r->validate(['title' => 'required|max:100', 'body' => 'max:10000'], ['title' => 'タイトル', 'body' => '本文']);

    $note = Note::from($data);
    $note->save();
    flash('success', "「{$note->title}」を保存しました");
    return redirect('/');
});
