#!/bin/bash
# serve.sh [theme] [port] - the built package on the kit's made-up TL-D1600S server, served by the render harness until stopped
# (Ctrl+C), for looking at the pages in a browser and for the axe check. Workstation only.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd); NAME=qnap-expansion
# shellcheck disable=SC1091
. "$HERE/kit/render/render-lib.sh"
PLUGIN_ROOT=$HERE "$HERE/kit/render/fetch-webgui.sh" >/dev/null
theme=${1:-black}; port=${2:-$(free_port)}
K=$HERE/tests/.work/server-serve-$theme; rm -rf "$K"; mkdir -p "$K"
php "$HERE/kit/render/enclosure-fixture.php" "$K" && php "$HERE/kit/render/enclosure-state-fixture.php" "$K" || exit 1
export DRIVEMAP_ROOT=$K
web=$HERE/tests/.work/serve-$theme; stage_web "$web" || exit 1
start_harness "$web" "$port" "$theme" "Status=QnapExpansionStatus,Enclosures=QnapExpansionEnclosures,About=QnapExpansionAbout"
echo "http://127.0.0.1:$port/?tab=1"
wait
