<?php

namespace Washi;

/**
 * IP allowlist: 送信元 IP が一覧（単一 IP か CIDR、IPv4/IPv6）に含まれるかを判定する。
 *
 * 一覧はプロジェクト直下の `whitelist.txt`（config `ip_allowlist` / `IP_ALLOWLIST` で変更可）。1 行 1 件:
 *     203.0.113.10          単一 IP
 *     198.51.100.0/24       CIDR
 *     2001:db8::/32         IPv6 も可
 *     # コメント            行頭、または空白のあとの # 以降は無視
 * ファイルが無い、または有効な行が 1 件も無いときは制限なし（全 IP を許可）。
 *
 *     return page()->ipAllowlist()->then(...);            // 許可外は 403（Middleware\RequireIp）
 *     if (!ip_allowed()) return back(['id' => 'このIP(' . client_ip() . ')ではログインできません']);
 */
final class IpAllowlist
{
    /** @var array<string, self> */
    private static array $cache = [];

    /** @param list<string> $entries */
    public function __construct(private array $entries) {}

    /** 一覧ファイルを読む。無ければ空（= 制限なし） */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) return new self([]);
        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim((string)preg_replace('/(^|\s)#.*$/', '', $line));
            if ($line !== '') $entries[] = $line;
        }
        return new self($entries);
    }

    /** App の設定 `ip_allowlist`（root 相対か絶対）の一覧。1 リクエスト内では 1 回だけ読む */
    public static function forApp(?string $file = null): self
    {
        $app = App::current();
        $path = $app->path($file ?? (string)$app->config('ip_allowlist', 'whitelist.txt'));
        return self::$cache[$path] ??= self::fromFile($path);
    }

    public function isEmpty(): bool { return $this->entries === []; }

    /** @return list<string> */
    public function entries(): array { return $this->entries; }

    /** 一覧が空なら常に true。IP として解釈できない値は false */
    public function allows(string $ip): bool
    {
        if ($this->isEmpty()) return true;
        foreach ($this->entries as $entry) {
            if (self::matches($ip, $entry)) return true;
        }
        return false;
    }

    /** $ip が $entry（単一 IP か CIDR）に含まれるか。IPv4 と IPv6 は互いに一致しない */
    public static function matches(string $ip, string $entry): bool
    {
        $bin = @inet_pton($ip);
        [$net, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);
        $net = @inet_pton(trim($net));
        if ($bin === false || $net === false || strlen($bin) !== strlen($net)) return false;

        $max = strlen($net) * 8;
        if ($bits === null) {
            $bits = $max;
        } elseif (ctype_digit(trim($bits)) && (int)$bits <= $max) {
            $bits = (int)$bits;
        } else {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (strncmp($bin, $net, $bytes) !== 0) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
