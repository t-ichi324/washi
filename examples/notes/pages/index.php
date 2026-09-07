<?php
// GET /  — 一覧。クロージャを return するだけ。
return fn() => view('notes/index', [
    'title' => 'Notes',
    'notes' => Note::query()->orderBy('id', 'desc')->all(),
]);
