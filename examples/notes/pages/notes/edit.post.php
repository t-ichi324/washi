<?php
// チェーン形式のバリデータ。戻り先を選ぶ、DB を見てエラーを足す、など手で制御したいときはこちら
return page()->csrf()->then(function (int $id, Washi\Request $r) {
    $note = Note::findOrFail($id);
    $v = Washi\Validator::from($r);
    $v->rule('title', 'タイトル')->required()->max(100);
    $v->rule('body', '本文')->max(10000);
    if (!$v->validate()) return $v->back("/notes/edit/$id");
    $note->merge($v->validated())->save();
    flash('success', '更新しました');
    return redirect('/');
});
