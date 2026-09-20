#!/usr/bin/env bash
# Vendor the JCB sources the metamodel extractor reflects over.
#
# The extractor never parses PHP text - it loads these classes and reads them
# by reflection. Pin the commit so a regenerated metamodel is reproducible.
#
# Usage: bin/fetch-jcb-sources.sh [commit-ish]

set -euo pipefail

PIN="${1:-bca4a1520484f3e2c2fbd12964a5995b0d058de1}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DST="$ROOT/vendor-jcb"
REPO="joomengine/Joomla-Component-Builder"
SRC="https://raw.githubusercontent.com/$REPO/$PIN/libraries/vendor_jcb/VDM.Joomla/src"

echo "Vendoring JCB sources @ $PIN"
mkdir -p "$DST/configs" "$DST/deps"

fetch () { # <remote-path> <local-path>
  local code
  code=$(curl -sSL -w '%{http_code}' -o "$2" "$SRC/$1")
  if [ "$code" != "200" ]; then
    echo "  FAIL $code  $1" >&2
    return 1
  fi
}

# Core metamodel sources
fetch "Componentbuilder/Table.php"            "$DST/Table.php"
fetch "Componentbuilder/Factory.php"          "$DST/Factory.php"
fetch "Abstraction/Remote/Config.php"         "$DST/AbstractConfig.php"

# Class dependencies needed to load the above
fetch "Abstraction/BaseTable.php"                          "$DST/deps/Abstraction_BaseTable.php"
fetch "Interfaces/TableInterface.php"                      "$DST/deps/Interfaces_TableInterface.php"
fetch "Interfaces/Remote/ConfigInterface.php"              "$DST/deps/Interfaces_Remote_ConfigInterface.php"
fetch "Componentbuilder/Power/Interfaces/TableInterface.php" \
      "$DST/deps/Componentbuilder_Power_Interfaces_TableInterface.php"

# Per-entity transport configs. Application entities live under Package/;
# the reusable/distribution entities have their own top-level namespaces.
echo "Fetching per-entity Remote\\Config classes..."

curl -sSL "https://api.github.com/repos/$REPO/git/trees/$PIN?recursive=1" \
  | grep -oE '"path": "libraries/vendor_jcb/VDM\.Joomla/src/Componentbuilder/Package/[A-Za-z]+/Remote/Config\.php"' \
  | sed 's/"path": "//;s/"//' \
  | while read -r p; do
      area="${p##*/Package/}"; area="${area%%/Remote/Config.php}"
      fetch "${p#libraries/vendor_jcb/VDM.Joomla/src/}" "$DST/configs/$area.php" || true
    done

for area in Power JoomlaPower Fieldtype Snippet Repository; do
  fetch "Componentbuilder/$area/Remote/Config.php" "$DST/configs/$area.php" || true
done

echo "$PIN" > "$DST/PINNED_COMMIT"
echo "Done: $(find "$DST/configs" -name '*.php' | wc -l) entity configs vendored."
