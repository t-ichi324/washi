<?php
// GET /api/notes?page=1 — Entity/Collection/Paginated を返すと JSON になる
return fn(Washi\Request $r) => Note::query()->orderBy('id', 'desc')->page($r->int('page', 1), 10);
