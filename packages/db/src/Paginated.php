<?php

namespace Washi\Db;

use Washi\Collection;

/**
 * Paginated Collection — encapsulates a slice of data along with pagination metadata.
 *
 * Use to handle the output of `Query::page()` or `Db::page()`.
 * Typical uses: displaying list views with pagination controls, JSON-returning API results.
 *
 * - Holds the current page data (rows) and total record count.
 * - Calculates total pages, next/previous page numbers, and item offsets.
 * - Generates HTML pagination links compatible with modern CSS frameworks.
 *
 * @template T of object
 * @extends Collection<int, T>
 */
class Paginated extends Collection
{
    /** 総件数 */
    public int $total = 0;
    /** 現在ページ */
    public int $currentPage = 1;
    /** 1ページあたり件数 */
    public int $perPage = 20;
    /** 最大ページ数 */
    public int $lastPage = 1;

    /**
     * @param array<int, T>|Collection<int, T> $items
     * @param int $total 総件数（0 の場合は items 件数を使用）
     * @param int $currentPage 現在ページ（1始まり）
     * @param int $perPage 1ページあたりの件数
     */
    public function __construct($items = [], int $total = 0, int $currentPage = 1, int $perPage = 20)
    {
        parent::__construct($items);
        $this->total = $total ?: $this->count();
        $this->currentPage = max(1, $currentPage);
        $this->perPage = $perPage;
        $this->lastPage = ($perPage > 0)
            ? (int)max(1, ceil($this->total / $perPage))
            : ($this->total > 0 ? 1 : 0);
    }

    // isEmpty()/isNotEmpty() は Collection と同じ「現在ページの行」基準（範囲外ページは空扱い）。
    // 検索結果全体の有無は ->total で判定すること。

    /** 前ページ存在判定 */
    public function hasPrev(): bool
    {
        return $this->currentPage > 1;
    }

    /** 次ページ存在判定 */
    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /** 前ページ番号取得 */
    public function prevPage(): ?int
    {
        return $this->hasPrev() ? $this->currentPage - 1 : null;
    }

    /** 次ページ番号取得 */
    public function nextPage(): ?int
    {
        return $this->hasNext() ? $this->currentPage + 1 : null;
    }

    /** 開始アイテム番号（1始まり） */
    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->currentPage - 1) * $this->perPage + 1;
    }

    /** 終了アイテム番号（1始まり） */
    public function to(): int
    {
        return $this->total === 0 ? 0 : min($this->currentPage * $this->perPage, $this->total);
    }

    /** 総行数（Collection の count と同じ） */
    public function rowCount(): int
    {
        return $this->count();
    }

    /** ページネーションリンク生成 */
    public function links(string $baseUrl = '?', string $pageParam = 'page', int $range = 5): string
    {
        if ($this->lastPage <= 1) return '';
        $html = '<nav class="pagination">';
        $sep = (strpos($baseUrl, '?') === false) ? '?' : '&';
        if ($this->hasPrev()) $html .= '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . $sep . htmlspecialchars($pageParam, ENT_QUOTES, 'UTF-8') . '=' . ($this->currentPage - 1) . '">&laquo;</a> ';
        $start = max(1, $this->currentPage - $range);
        $end   = min($this->lastPage, $this->currentPage + $range);
        for ($i = $start; $i <= $end; $i++) {
            $active = ($i === $this->currentPage) ? ' class="active"' : '';
            $html .= '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . $sep . htmlspecialchars($pageParam, ENT_QUOTES, 'UTF-8') . '=' . $i . '"' . $active . '>' . $i . '</a> ';
        }
        if ($this->hasNext()) $html .= '<a href="' . htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . $sep . htmlspecialchars($pageParam, ENT_QUOTES, 'UTF-8') . '=' . ($this->currentPage + 1) . '">&raquo;</a>';
        $html .= '</nav>';
        return $html;
    }
}
