#!/bin/bash
# shellcheck disable=SC2015
# The pages of the built package with Unraid's real stylesheets in headless Chrome: the Enclosures tab on a made-up server
# with a TL-D1600S (the kit's enclosure fixture: the cabling, PCI ids, links and contents of a measured unit, invented
# serials), in all four themes, and the About tab. OUT=<dir> keeps the screenshots. Workstation only.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=qnap-expansion
OUT=${OUT:-$HERE/tests/.work/shots}; mkdir -p "$OUT"
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh" >/dev/null
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$HERE/$NAME.plg")
K=$HERE/tests/.work/server-k; rm -rf "$K"; mkdir -p "$K"
php "$HERE/kit/render/enclosure-fixture.php" "$K" && php "$HERE/kit/render/enclosure-state-fixture.php" "$K" || { bad "fixture"; exit 1; }
export DRIVEMAP_ROOT=$K
for theme in black white gray azure; do
  web=$W/web-$theme; stage_web "$web" || exit 1
  port=$(free_port); start_harness "$web" "$port" "$theme" "Status=QnapExpansionStatus,Enclosures=QnapExpansionEnclosures,About=QnapExpansionAbout"
  echo "$port" >"$W/port-$theme"
  shot "http://127.0.0.1:$port/?tab=2" "$OUT/$NAME-enclosures-$theme.png"
  [ -s "$OUT/$NAME-enclosures-$theme.png" ] && ok "$theme: Enclosures rendered" || bad "$theme: no screenshot"
  dom "http://127.0.0.1:$port/?tab=2" "$W/dom-$theme.html"
  D=$W/dom-$theme.html
  grep -q 'QNAP TL-D1600S 1' "$D" && grep -q 'QXP-1600eS-A1164 card at 0000:01:00.0' "$D" && ok "$theme: the unit and its card" || bad "$theme: unit header"
  [ "$(grep -o 'class="enc-bay ' "$D" | wc -l)" = 16 ] && ok "$theme: 16 bays in the front view" || bad "$theme: bays: $(grep -o 'class="enc-bay ' "$D" | wc -l)"
  grep -q 'aria-label="HDD11: sdl, Dev 9, 37 °C' "$D" && grep -q 'aria-label="HDD3: empty"' "$D" && ok "$theme: bay text: drive, slot, temperature; empty bay" || bad "$theme: bay labels"
  grep -q 'aria-label="HDD2: sdj, Dev 2, standby' "$D" && ok "$theme: a drive in standby says so" || bad "$theme: standby"
  grep -q 'UDMA CRC Error Count 14' "$D" && ok "$theme: SMART error from Unraid's report" || bad "$theme: SMART"
  grep -q 'Cabling not confirmed' "$D" && grep -q 'Confirm cabling' "$D" && ok "$theme: cabling not confirmed, the form offers it" || bad "$theme: cabling"
  [ "$(grep -o 'full speed</td>' "$D" | wc -l)" = 5 ] && ok "$theme: five links at full speed" || bad "$theme: links: $(grep -o 'full speed</td>' "$D" | wc -l)"
  grep -q 'What the server can see of this enclosure' "$D" && ok "$theme: host notes" || bad "$theme: notes"
  [ "$(grep -o 'class="enc-loc"' "$D" | wc -l)" = 13 ] && ok "$theme: a Locate button per drive" || bad "$theme: locate buttons"
  shot "http://127.0.0.1:$port/?tab=1" "$OUT/$NAME-status-$theme.png"
  dom "http://127.0.0.1:$port/?tab=1" "$W/status-$theme.html"
  S=$W/status-$theme.html
  grep -q 'The enclosure works; some checks need a look.' "$S" && ok "$theme: Status verdict (cabling not confirmed yet)" || bad "$theme: verdict"
  grep -q 'QXP-1600eS-A1164 at 0000:01:00.0: PCIe switch with 4 SATA controllers' "$S" && grep -q 'standard ahci driver' "$S" && grep -q 'All 5 links run at the speed' "$S" && grep -q 'No PCIe errors counted' "$S" && ok "$theme: Status checks: card, driver, links, errors" || bad "$theme: status checks"
  grep -q '13 drives in 16 bays' "$S" && grep -q 'no AHCI enclosure management' "$S" && ok "$theme: Status: drives, and what cannot be reached" || bad "$theme: status drives/notes"
  shot "http://127.0.0.1:$port/?tab=3" "$OUT/$NAME-about-$theme.png"
  dom "http://127.0.0.1:$port/?tab=3" "$W/about-$theme.html"
  grep -q "$VER" "$W/about-$theme.html" && grep -q 'QNAP Expansion is by the QNAP Expansion project' "$W/about-$theme.html" && ok "$theme: About: version and project" || bad "$theme: About"
  if grep -E "PHP (Warning|Fatal|Parse|Notice|Deprecated)" "$web.log" | head -5; then bad "$theme: PHP errors"; fi
done
# the endpoint: POST only, read-only answers, a cabling saved into the plugin's flash dir of the made-up server
port=$(cat "$W/port-black")
[ "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/plugins/$NAME/kit/enclosures.php?action=bays")" = 405 ] && ok "endpoint: GET refused" || bad "endpoint GET"
curl -s -d action=bays "http://127.0.0.1:$port/plugins/$NAME/kit/enclosures.php" | grep -q '"sdl":{"bay":"HDD11"' && ok "endpoint: bays for the Main page labels" || bad "endpoint bays"
curl -s -d action=cabling -d switch=0000:01:00.0 -d 'order[]=3' -d 'order[]=3' -d 'order[]=1' -d 'order[]=2' "http://127.0.0.1:$port/plugins/$NAME/kit/enclosures.php" | grep -q 'different group' && ok "endpoint: a bad cabling is refused" || bad "endpoint cabling refuse"
curl -s -d action=cabling -d switch=0000:01:00.0 -d 'order[]=3' -d 'order[]=4' -d 'order[]=1' -d 'order[]=2' "http://127.0.0.1:$port/plugins/$NAME/kit/enclosures.php" | grep -q '"ok":true' && [ -f "$K/boot/config/plugins/$NAME/enclosures.json" ] && ok "endpoint: cabling saved" || bad "endpoint cabling save"
dom "http://127.0.0.1:$port/?tab=2" "$W/dom-confirmed.html"
grep -q 'Cabling confirmed' "$W/dom-confirmed.html" && grep -q 'Save cabling' "$W/dom-confirmed.html" && ok "after saving: cabling confirmed" || bad "confirmed view"
shot "http://127.0.0.1:$port/?tab=2" "$OUT/$NAME-enclosures-confirmed-black.png"
curl -s -d action=locate -d dev=sdj "http://127.0.0.1:$port/plugins/$NAME/kit/enclosures.php" | grep -q '"confirm":true' && ok "endpoint: Locate asks before spinning up a drive in standby" || bad "endpoint locate standby"
# no enclosure: the empty state
E=$HERE/tests/.work/server-empty; rm -rf "$E"; mkdir -p "$E/sys/bus/pci/devices"
web=$W/web-empty; stage_web "$web" || exit 1; port=$(free_port)
DRIVEMAP_ROOT=$E start_harness "$web" "$port" black "Enclosures=QnapExpansionEnclosures,Status=QnapExpansionStatus"
dom "http://127.0.0.1:$port/?tab=1" "$W/dom-empty.html"
grep -q 'No enclosure found' "$W/dom-empty.html" && grep -q 'QNAP TL-D1600S on its QXP-1600eS-A1164 card' "$W/dom-empty.html" && ok "no enclosure: says so and names what is known" || bad "empty state"
dom "http://127.0.0.1:$port/?tab=2" "$W/status-empty.html"
grep -q 'No enclosure card found.' "$W/status-empty.html" && ok "no enclosure: Status verdict" || bad "empty status"

render_cleanup
[ $fail = 0 ] && echo "ALL PASSED" || echo FAILED
exit $fail
