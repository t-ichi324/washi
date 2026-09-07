<?php
// GET /notes/new — 新規フォーム
return fn() => view('notes/form', ['title' => '新規メモ', 'note' => new Note()]);
