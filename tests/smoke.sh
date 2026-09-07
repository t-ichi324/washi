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
check "debug panel injected"      'id="washi-debug"'               "$(curl -s $B/)"
check "security headers"          "X-Content-Type-Options: nosniff" "$(curl -s -i $B/)"
stop 8571

echo "== examples/notes (pages/ + views/ + Entity + CSRF + flash)"
rm -f "$ROOT/examples/notes/storage/app.db"* "$J"; serve 8572 "$ROOT/examples/notes" -t public public/index.php
B=http://127.0.0.1:8572; C="-s -c $J -b $J"
check "auto-migrate + empty list"  "まだメモがありません"           "$(curl $C $B/)"
TOKEN=$(curl $C $B/notes/new | grep -o 'name="_token" value="[a-f0-9]*"' | sed 's/.*value="//;s/"//')
[ -n "$TOKEN" ] && ok "csrf_field renders token" || fail "csrf_field renders token"
check "POST without token → 419"   "419"                            "$(curl $C -o /dev/null -w '%{http_code}' -X POST -d 'title=x' $B/notes/new)"
check "back() redirects to referer" "Location: http://127.0.0.1:8572/notes/new" "$(curl $C -i -X POST -H 'Referer: http://127.0.0.1:8572/notes/new' -d "_token=$TOKEN&title=&body=b" $B/notes/new)"
PAGE="$(curl $C $B/notes/new)"
check "err() shows field error"    "タイトルは必須です"             "$PAGE"
check "old() restores input"       '<textarea name="body" rows="6">b<' "$PAGE"
check "valid POST → redirect /"    "Location: /"                    "$(curl $C -i -X POST -d "_token=$TOKEN&title=First+note&body=hello" $B/notes/new)"
PAGE="$(curl $C $B/)"
check "flash shown once"           "を保存しました"                 "$PAGE"
check "note listed"                'First note'                     "$PAGE"
check "flash gone on next request" "0"                              "$(curl $C $B/ | grep -c 'flash success')"
check "int param: /edit/1"         'value="First note"'             "$(curl $C $B/notes/edit/1)"
check "int param: /edit/abc → 404" "404"                            "$(curl -s -o /dev/null -w '%{http_code}' $B/notes/edit/abc)"
check "findOrFail → 404 + _error view" "Note not found: id=999"    "$(curl -s $B/notes/edit/999)"
check "Paginated → JSON envelope"  '"total":1'                      "$(curl -s $B/api/notes)"
check "echo-style page works"      "<h1>About</h1>"                 "$(curl -s $B/about)"
check "_prefixed page not routable" "404"                           "$(curl -s -o /dev/null -w '%{http_code}' $B/_layout)"
check "path traversal blocked"     "404"                            "$(curl -s -o /dev/null -w '%{http_code}' "$B/..%2F..%2Fetc/passwd")"
check "delete → list empty"        '"total":0'                      "$(curl $C -X POST -d "_token=$TOKEN" $B/notes/delete/1 >/dev/null; curl -s $B/api/notes)"
stop 8572

echo; echo "passed: $PASS  failed: $FAIL"; rm -rf "$TMP"; [ "$FAIL" -eq 0 ]
