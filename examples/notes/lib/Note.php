<?php

use Washi\Db\Entity;

/** notes テーブルの 1 行。public プロパティ = カラム。これが「モデル」の全部。 */
class Note extends Entity
{
    public ?int $id = null;
    public string $title = '';
    public string $body = '';
    public ?string $created_at = null;
}
