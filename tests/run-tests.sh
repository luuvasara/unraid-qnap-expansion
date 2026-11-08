#!/bin/bash
# Offline test suite of qnap-expansion (build host only; touches no server): the Status checks, the kit's enclosure tests as
# shipped in kit/, lint, and the render test (pages of the built package in headless Chrome).
set -u
HERE=$(cd "$(dirname "$0")/.." && pwd); T=$HERE/tests
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
W=$T/.work; mkdir -p "$W"
P=$HERE/src/usr/local/emhttp/plugins/qnap-expansion
rm -rf "$P/kit"; mkdir -p "$P/kit/enclosures"; cp "$HERE"/kit/runtime/drivemap/*.php "$P/kit/"; cp "$HERE"/kit/runtime/drivemap/enclosures/*.json "$P/kit/enclosures/"
trap 'rm -rf "$P/kit"' EXIT
out=$(php "$T/status-test.php" "$W/status" 2>&1) && ok "Status: $(grep -c '^ok' <<<"$out") checks" || { bad "Status:"; grep -v '^ok' <<<"$out"; }
rm -rf "$P/kit"
for f in "$P"/include/*.php "$P"/*.page; do php -d short_open_tag=1 -l "$f" >/dev/null || bad "php -l $f"; done; ok "PHP parses"
# read-only: the plugin's own code runs no command and writes nothing
if grep -nE '\b(exec|shell_exec|system|passthru|proc_open|popen|file_put_contents|fopen|unlink|rename|mkdir)\s*\(' "$P"/include/*.php "$P"/*.page; then bad "a command or a write in the plugin's own code"; else ok "the plugin's own code runs no command and writes nothing"; fi
bash "$T/render-test.sh" || bad "render test"
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
