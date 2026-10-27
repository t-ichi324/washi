#!/usr/bin/env bash
# Washi smoke test: boots both example apps on the PHP built-in server and checks the
# behaviours a new agent is most likely to break. Run from anywhere: `bash tests/smoke.sh`.
# Exit code 0 = all checks passed. Requires: php >= 8.1 with pdo_sqlite, curl.
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; J="$TMP/cookies"; PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL+1)); echo "  FAIL $1"; [ -n "${2:-}" ] && echo "       got: $2"; }
check() { # check <desc> <expected-substring> <actual>
  case "$3" in *"$2"*) ok "$1";; *) fail "$1" "$3";; esac
}
serve() { # serve <port> <dir> <args...>
  ( cd "$2" && nohup php -S "127.0.0.1:$1" "${@:3}" > "$TMP/server-$1.log" 2>&1 & )
  for _ in 1 2 3 4 5 6 7 8; do curl -s -o /dev/null "http://127.0.0.1:$1/" && return 0; sleep 0.5; done
  echo "server on $1 did not start"; cat "$TMP/server-$1.log"; exit 1
}
stop() { pkill -f "php -S 127.0.0.1:$1" 2>/dev/null; sleep 0.2; true; }
[ -d "$ROOT/vendor" ] || (cd "$ROOT" && composer install --no-interaction -q)

echo "== examples/hello (single file, explicit routes)"
rm -f "$ROOT/examples/hello/storage/app.db"*; serve 8571 "$ROOT/examples/hello" index.php
B=http://127.0.0.1:8571
check "string → text/html"        "Content-Type: text/html"        "$(curl -s -i $B/)"
check "route param (utf-8)"       "Hello, 世界"                     "$(curl -s $B/hello/%E4%B8%96%E7%95%8C)"
check "array → JSON"              '"tz":"Asia/Tokyo"'              "$(curl -s $B/api/time)"
check "JSON body parsed"          '{"a":1,"b":"x"}'                "$(curl -s -X POST -H 'Content-Type: application/json' -d '{"a":1,"b":"x"}' $B/api/echo)"
check "default SQLite works"      '{"hits":2}'                     "$(curl -s $B/db >/dev/null; curl -s $B/db)"
check "404 JSON when Accept json" '"status":404'                   "$(curl -s -H 'Accept: application/json' $B/nope)"
check "405 for wrong method"      "405"                            "$(curl -s -o /dev/null -w '%{http_code}' -X DELETE $B/api/time)"
check "panel: fragment → not injected" "0"                         "$(curl -s $B/ | grep -c washi-debug)"
check "security headers"          "X-Content-Type-Options: nosniff" "$(curl -s -i $B/)"
stop 8571

echo "== examples/notes (pages/ + views/ + Entity + CSRF + flash)"
rm -f "$ROOT/examples/notes/storage/app.db"* "$J"; serve 8572 "$ROOT/examples/notes" -t public public/index.php
B=http://127.0.0.1:8572; C="-s -c $J -b $J"
check "auto-migrate + empty list"  "まだメモがありません"           "$(curl $C $B/)"
TOKEN=$(curl $C $B/notes/new | grep -o 'name="_token" value="[a-f0-9]*"' | sed 's/.*value="//;s/"//')
[ -n "$TOKEN" ] && ok "csrf_field renders token" || fail "csrf_field renders token"
check "POST without token → 419"   "419"                            "$(curl $C -o /dev/null -w '%{http_code}' -X POST -d 'title=x' $B/notes/new)"
# 理由句なしの 419 は Apache(mod_php) で 500 に化ける。ステータス行ごと送っていること
check "status line has reason"     "419 Page Expired"               "$(curl $C -s -i -X POST -d 'title=x' $B/notes/new | head -1)"
check "back() redirects to referer" "Location: http://127.0.0.1:8572/notes/new" "$(curl $C -i -X POST -H 'Referer: http://127.0.0.1:8572/notes/new' -d "_token=$TOKEN&title=&body=b" $B/notes/new)"
PAGE="$(curl $C $B/notes/new)"
check "validate() → err() shown"   "タイトルを入力してください。"   "$PAGE"
check "old() restores input"       '<textarea name="body" rows="6">b<' "$PAGE"
check "valid POST → redirect /"    "Location: /"                    "$(curl $C -i -X POST -d "_token=$TOKEN&title=First+note&body=hello" $B/notes/new)"
PAGE="$(curl $C $B/)"
check "flash shown once"           "を保存しました"                 "$PAGE"
check "note listed"                'First note'                     "$PAGE"
check "flash gone on next request" "0"                              "$(curl $C $B/ | grep -c 'flash success')"
check "int param: /edit/1"         'value="First note"'             "$(curl $C $B/notes/edit/1)"
check "Validator chain → back(\$to)" "Location: /notes/edit/1"       "$(curl $C -i -X POST -d "_token=$TOKEN&title=" $B/notes/edit/1)"
check "chain error shown"          "タイトルを入力してください。"   "$(curl $C $B/notes/edit/1)"
check "int param: /edit/abc → 404" "404"                            "$(curl -s -o /dev/null -w '%{http_code}' $B/notes/edit/abc)"
check "findOrFail → 404 + _error view" "Note not found: id=999"    "$(curl -s $B/notes/edit/999)"
check "Paginated → JSON envelope"  '"total":1'                      "$(curl -s $B/api/notes)"
check "echo-style page works"      "<h1>About</h1>"                 "$(curl -s $B/about)"
# 余りセグメントは引数で使い切らなければ 404（未知 URL が pages/index.php で 200 にならない）
check "unknown URL → 404 (not index)" "404"                         "$(curl -s -o /dev/null -w '%{http_code}' $B/foo/bar)"
check "extra segment → 404"        "404"                            "$(curl -s -o /dev/null -w '%{http_code}' $B/notes/edit/1/x)"
check "echo page + extra → 404"    "404"                            "$(curl -s -o /dev/null -w '%{http_code}' $B/about/x)"
P="$ROOT/examples/notes/pages"; mkdir -p "$P/zz_dir"
printf '<?php\nreturn fn(string ...$rest) => implode(",", $rest);\n' > "$P/zz_rest.php"
printf '<?php\nreturn fn() => "dir index";\n' > "$P/zz_dir/index.php"
check "variadic takes the rest"    "a,b"                            "$(curl -s $B/zz_rest/a/b)"
check "dir/index.php exact match"  "dir index"                      "$(curl -s $B/zz_dir)"
check "dir/index.php no remainder" "404"                            "$(curl -s -o /dev/null -w '%{http_code}' $B/zz_dir/1)"
rm -rf "$P/zz_rest.php" "$P/zz_dir"
# デバッグパネル: タブ（CSS のみ）、解決したページ、描画したビュー、秘密っぽい値のマスク
PAGE="$(curl $C "$B/notes/new?password=hunter2&q=a")"
check "panel: page file shown"     "page pages/notes/new.php"       "$PAGE"
check "panel: views recorded"      "Views (2)"                      "$PAGE"
check "panel: secret masked"       "0"                              "$(printf '%s' "$PAGE" | grep -c hunter2)"
check "panel: session tab"         '<td class="k">_csrf</td><td>***' "$PAGE"
check "panel: navigate → injected" 'id="washi-debug"'               "$(curl -s -H 'Sec-Fetch-Mode: navigate' $B/)"
check "panel: fetch() → not injected" "0"                           "$(curl -s -H 'Sec-Fetch-Mode: cors' $B/ | grep -c washi-debug)"
check "panel: XHR → not injected"   "0"                              "$(curl -s -H 'X-Requested-With: XMLHttpRequest' $B/ | grep -c washi-debug)"
check "panel: htmx → not injected"  "0"                              "$(curl -s -H 'HX-Request: true' $B/ | grep -c washi-debug)"
check "_prefixed page not routable" "404"                           "$(curl -s -o /dev/null -w '%{http_code}' $B/_layout)"
check "path traversal blocked"     "404"                            "$(curl -s -o /dev/null -w '%{http_code}' "$B/..%2F..%2Fetc/passwd")"
check "delete → list empty"        '"total":0'                      "$(curl $C -X POST -d "_token=$TOKEN" $B/notes/delete/1 >/dev/null; curl -s $B/api/notes)"

# pages/**/_access.php: フォルダ単位のミドルウェア。ページは guard を通ってから include される
P="$ROOT/examples/notes/pages"; mkdir -p "$P/zz_locked" "$P/zz_open/sub"
printf '<?php\nreturn page()->use(fn($r, $next) => $next($r)->header("X-Robots-Tag", "noindex"))->auth();\n' > "$P/zz_locked/_access.php"
printf '<?php touch(__DIR__ . "/hit"); echo "secret";\n' > "$P/zz_locked/secret.php"
printf '<?php\nreturn function ($r, $next) { $res = $next($r); return $res->header("X-Chain", "outer>" . $res->getHeader("X-Chain")); };\n' > "$P/zz_open/_access.php"
printf '<?php\nWashi\\View::layout(false);\nreturn [fn($r, $next) => $next($r)->header("X-Chain", "inner")];\n' > "$P/zz_open/sub/_access.php"
printf '<?php\nreturn fn() => view("zz_bare");\n' > "$P/zz_open/sub/page.php"
printf '<?php\nreturn fn() => ["a" => 1];\n' > "$P/zz_open/data.php"
printf '<?php\nreturn "<html><body>doc</body></html>";\n' > "$P/zz_open/doc.php"
printf '<p>bare</p>\n' > "$ROOT/examples/notes/views/zz_bare.php"
RES="$(curl -s -i $B/zz_locked/secret)"
check "_access: guard → login"      "Location: /login"               "$RES"
check "_access: \$next() → Response" "X-Robots-Tag: noindex"          "$RES"
check "_access: page not included"  "absent"                         "$([ -e "$P/zz_locked/hit" ] && echo present || echo absent)"
check "_access: unknown URL guarded" "Location: /login"              "$(curl -s -i $B/zz_locked/nope)"
check "_access: guards x.php too"   "Location: /login"               "$(printf '<?php echo "x";\n' > "$P/zz_locked.php"; curl -s -i $B/zz_locked; rm -f "$P/zz_locked.php")"
check "_access: _private URL guarded" "Location: /login"             "$(curl -s -i $B/zz_locked/_access)"
RES="$(curl -s -i $B/zz_open/sub/page)"
check "_access: nested outer→inner" "X-Chain: outer>inner"           "$RES"
check "_access: View::layout(false)" "0"                             "$(printf '%s' "$RES" | grep -c '<html')"
check "_access: array → JSON in MW" '{"a":1}'                        "$(curl -s $B/zz_open/data)"
check "panel: _access.php listed"  "pages/zz_open/_access.php"      "$(curl -s $B/zz_open/doc)"
check "_access: not applied outside" "0"                             "$(curl -s -i $B/about | grep -c 'X-Chain')"
rm -rf "$P/zz_locked" "$P/zz_open" "$ROOT/examples/notes/views/zz_bare.php"

# ->ipAllowlist(): 一覧に無い送信元は 403、載っていれば通す、一覧が無ければ素通し（組み込みサーバーの送信元は 127.0.0.1）
P="$ROOT/examples/notes/pages"; S="$ROOT/examples/notes/storage"
printf '<?php\nreturn page()->ipAllowlist("storage/zz_allow.txt")->then(fn() => "ip ok");\n' > "$P/zz_ip.php"
printf '<?php\nreturn #[Washi\\Middleware\\RequireIp("storage/zz_allow.txt")] fn() => "attr ok";\n' > "$P/zz_ip_attr.php"
check "ip: no list → pass"         "ip ok"                          "$(rm -f "$S/zz_allow.txt"; curl -s $B/zz_ip)"
check "ip: unlisted → 403"         "403"                            "$(printf '203.0.113.10\n' > "$S/zz_allow.txt"; curl -s -o /dev/null -w '%{http_code}' $B/zz_ip)"
check "ip: attribute → 403"        "403"                            "$(curl -s -o /dev/null -w '%{http_code}' $B/zz_ip_attr)"
check "ip: listed (CIDR) → pass"   "ip ok"                          "$(printf '# local\n127.0.0.0/8\n' > "$S/zz_allow.txt"; curl -s $B/zz_ip)"
rm -f "$P/zz_ip.php" "$P/zz_ip_attr.php" "$S/zz_allow.txt"

# セッション固定対策: 未知の ID は採用しない（use_strict_mode）、Remember-me で復元したら ID を作り直す
SID() { printf '%s' "$1" | grep -o 'washi_sid=[^;]*' | head -1 | sed 's/washi_sid=//'; }
FIX="zzfix$(date +%s)$$"   # 毎回新しい値（固定値だと過去の実行で作られたセッションファイルが「既知の ID」になりうる）
RES="$(curl -s -i -H "Cookie: washi_sid=$FIX" $B/notes/new)"
check "session: unknown id → new id" "new" "$(N=$(SID "$RES"); [ -n "$N" ] && [ "$N" != "$FIX" ] && echo new || echo kept)"
printf '<?php\nWashi\\Auth::remember(fn(string $t) => $t === "zztok" ? ["id" => 7] : null);\nreturn fn() => "uid=" . Washi\\Auth::id();\n' > "$P/zz_remember.php"
OLD="$(SID "$(curl -s -i $B/notes/new)")"
RES="$(curl -s -i -H "Cookie: washi_sid=$OLD; washi_remember=zztok" $B/zz_remember)"
check "remember: user restored"      "uid=7" "$RES"
check "remember: restore → new id"   "new"   "$(N=$(SID "$RES"); [ -n "$N" ] && [ "$N" != "$OLD" ] && echo new || echo kept)"
rm -f "$P/zz_remember.php"

# オープンリダイレクト対策: redirect() / back() は外部 URL を '/' に差し替える。サイト内と同じ Host の絶対 URL は通す
printf '<?php\nreturn fn(Washi\\Request $r) => redirect($r->str("to"));\n' > "$P/zz_redir.php"
printf '<?php\nreturn fn() => back();\n' > "$P/zz_back.php"
LOC() { curl -s -i "$B/zz_redir?to=$1" | grep -i '^Location:' | tr -d '\r'; }
check "redirect: internal path kept"  "Location: /notes/new"          "$(LOC %2Fnotes%2Fnew)"
check "redirect: same host kept"      "Location: http://127.0.0.1:8572/about" "$(LOC http%3A%2F%2F127.0.0.1%3A8572%2Fabout)"
check "redirect: other host → /"      "Location: /"                    "$(LOC https%3A%2F%2Fevil.example%2Fx)"
check "redirect: //host → /"          "Location: /"                    "$(LOC %2F%2Fevil.example)"
check "redirect: /\\host → /"         "Location: /"                    "$(LOC %2F%5Cevil.example)"
check "redirect: tab trick → /"       "Location: /"                    "$(LOC %2F%09%2Fevil.example)"
check "redirect: javascript: → /"     "Location: /"                    "$(LOC javascript%3Aalert%281%29)"
check "back: same-site referer kept"  "Location: http://127.0.0.1:8572/about" "$(curl -s -i -H 'Referer: http://127.0.0.1:8572/about' $B/zz_back | tr -d '\r')"
check "back: external referer → /"    "Location: /"                    "$(curl -s -i -H 'Referer: https://evil.example/' $B/zz_back | grep -i '^Location:' | tr -d '\r')"
rm -f "$P/zz_redir.php" "$P/zz_back.php"

# ->throttle(): 送信元 + URL ごとに数え、超えたら 429 + Retry-After。by: で入力値ごとに分かれる
rm -rf "$ROOT/examples/notes/storage/cache/throttle"
printf '<?php\nreturn page()->throttle(2, 60)->then(fn() => "ok");\n' > "$P/zz_thr.php"
printf '<?php\nreturn page()->throttle(1, 60, by: "user")->then(fn() => "ok");\n' > "$P/zz_thr_by.php"
printf '<?php\nreturn #[Washi\\Middleware\\Throttle(1, 60)] fn() => "ok";\n' > "$P/zz_thr_attr.php"
CODE() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
check "throttle: within limit"     "200 200"                        "$(CODE $B/zz_thr) $(CODE $B/zz_thr)"
RES="$(curl -s -i $B/zz_thr)"
check "throttle: over limit → 429" "429 Too Many Requests"          "$RES"
check "throttle: Retry-After"      "Retry-After: "                   "$RES"
check "throttle: JSON 429"         '"status":429'                   "$(curl -s -H 'Accept: application/json' $B/zz_thr)"
check "throttle: per URL"          "200"                            "$(CODE $B/zz_thr_attr)"
check "throttle: attribute → 429"  "429"                            "$(CODE $B/zz_thr_attr)"
check "throttle: by input"         "200 429 200"                    "$(CODE -d user=Alice $B/zz_thr_by) $(CODE -d user=alice $B/zz_thr_by) $(CODE -d user=bob $B/zz_thr_by)"
rm -rf "$P/zz_thr.php" "$P/zz_thr_by.php" "$P/zz_thr_attr.php" "$ROOT/examples/notes/storage/cache/throttle"

# 500 は Log::exception 経由でスタックトレースと previous 連鎖を 1 行に残す（本番は画面に出ないため）
printf '<?php\nreturn fn() => throw new LogicException("kaboom", 0, new RuntimeException("root cause"));\n' > "$ROOT/examples/notes/pages/boom.php"
curl -s -o /dev/null $B/boom
LOGLINE="$(grep 'kaboom' "$ROOT/examples/notes/storage/log/$(date +%F).log" | tail -1)"
rm -f "$ROOT/examples/notes/pages/boom.php"
check "500 logged with trace"      '"trace":['                      "$LOGLINE"
check "500 logs previous chain"    'root cause'                     "$LOGLINE"
check "exception log stays 1 line" "0"                              "$(printf '%s' "$LOGLINE" | wc -l | tr -d ' ')"
# 組み込みサーバーのルータ越しでも public/ の実ファイルは静的配信される（PHP に飲まれない）
check "static css served"          "text/css"                       "$(curl -s -i $B/css/app.css)"
check "static css has content"     "body{color:#333}"               "$(curl -s $B/css/app.css)"
check "php source never served"    "0"                              "$(curl -s $B/index.php | grep -c 'new Washi')"
stop 8572

# サブディレクトリ設置ではセッション cookie も basePath に限定（同じホストの別アプリへ送らない）
serve 8573 "$ROOT/examples"
check "session cookie path = basePath" "path=/notes/public/"       "$(curl -s -i http://127.0.0.1:8573/notes/public/notes/new | grep -i '^Set-Cookie: washi_sid')"
stop 8573

# SESSION_IDLE / SESSION_LIFETIME: 期限を過ぎたらセッションを捨てて新しい ID（中身は空、Session::expired() が true）
P="$ROOT/examples/notes/pages"; K="$TMP/ttl"
printf '<?php\nreturn function () { $n = Washi\\Session::get("n", 0) + 1; Washi\\Session::set("n", $n);\n  return (Washi\\Session::expired() ? "expired" : "alive") . " n=$n"; };\n' > "$P/zz_ttl.php"
SESSION_IDLE=2 serve 8574 "$ROOT/examples/notes" -t public public/index.php
rm -f "$K"; curl -s -c "$K" -b "$K" -o /dev/null http://127.0.0.1:8574/zz_ttl
check "idle: within → kept"         "alive n=2"                      "$(curl -s -c "$K" -b "$K" http://127.0.0.1:8574/zz_ttl)"
sleep 3
check "idle: exceeded → expired"    "expired n=1"                    "$(curl -s -c "$K" -b "$K" http://127.0.0.1:8574/zz_ttl)"
stop 8574
SESSION_IDLE=10 SESSION_LIFETIME=2 serve 8574 "$ROOT/examples/notes" -t public public/index.php
rm -f "$K"; curl -s -c "$K" -b "$K" -o /dev/null http://127.0.0.1:8574/zz_ttl
sleep 1.6
check "lifetime: within → kept"     "alive n=2"                      "$(curl -s -c "$K" -b "$K" http://127.0.0.1:8574/zz_ttl)"
sleep 1.6   # 操作は続いていても（idle 内でも）初回から 3 秒超で切れる
check "lifetime: exceeded → expired" "expired n=1"                   "$(curl -s -c "$K" -b "$K" http://127.0.0.1:8574/zz_ttl)"
stop 8574
rm -f "$P/zz_ttl.php"

# where(fn) のグループ条件: 子 Query と親でプレースホルダ名が衝突しないこと、入れ子のグループが
# SQL に落ちること（どちらも以前は壊れていた。README の既知の問題）
echo
echo "== washi/db Query: where(fn) グループ"
QOUT="$(cd "$ROOT" && php tests/query_groups.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
  esac
done <<< "$QOUT"

# サブディレクトリ + .htaccess で public/ へ内部転送したときのパス解決
echo
echo "== washi Request: サブディレクトリ設置"
ROUT="$(cd "$ROOT" && php tests/request_basepath.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
  esac
done <<< "$ROUT"

# デバッグパネルの注入条件と明示ルートの記録（App::handle を直接呼ぶ）
echo
echo "== washi Debug: パネル"
DOUT="$(cd "$ROOT" && php tests/debug_panel.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
  esac
done <<< "$DOUT"

# バリデータ・old() の既定値・abs_url()
echo
echo "== washi Validator"
VOUT="$(cd "$ROOT" && php tests/validator.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
    *)        fail "validator.php: $line";;
  esac
done <<< "$VOUT"

# Collection: 列名が PHP 関数名（date など）でも keyBy / groupBy / sum が関数として呼ばない
echo
echo "== washi Collection"
COUT="$(cd "$ROOT" && php tests/collection.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
    *)        fail "collection.php: $line";;
  esac
done <<< "$COUT"

# lib/ の自動読み込み: サブフォルダ・名前空間なし/つき・重複・本番キャッシュ
echo
echo "== washi LibAutoloader"
LOUT="$(cd "$ROOT" && php tests/lib_autoload.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
    *)        fail "lib_autoload.php: $line";;
  esac
done <<< "$LOUT"

# .env.defaults → .env → 実環境変数 の重ね合わせ
echo
echo "== washi Env: .env.defaults"
EOUT="$(cd "$ROOT" && php tests/env_layers.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
  esac
done <<< "$EOUT"

# IpAllowlist: whitelist.txt の照合（IP・CIDR・IPv6・コメント・空なら制限なし）
echo
echo "== washi IpAllowlist"
IOUT="$(cd "$ROOT" && php tests/ip_allowlist.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
    *)        fail "ip_allowlist.php: $line";;
  esac
done <<< "$IOUT"

# RateLimiter / FileRateLimitStore: 期間・掃除・同時アクセス（flock）・保存先の差し替え
echo
echo "== washi RateLimiter"
ROUT="$(cd "$ROOT" && php tests/rate_limiter.php 2>&1)"
while IFS= read -r line; do
  case "$line" in
    "ok "*)   ok   "${line#ok }";;
    "FAIL "*) fail "${line#FAIL }";;
    *)        fail "rate_limiter.php: $line";;
  esac
done <<< "$ROUT"

echo; echo "passed: $PASS  failed: $FAIL"; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
