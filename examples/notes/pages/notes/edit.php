<?php
// GET /notes/edit/{id} — 余った URL セグメントが引数になる。int 型なら数字以外は 404。
return fn(int $id) => view('notes/form', ['title' => 'メモを編集', 'note' => Note::findOrFail($id)]);
