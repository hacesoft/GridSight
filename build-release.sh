#!/bin/sh
set -eu
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SRC="$ROOT/src"

command -v python3 >/dev/null 2>&1 || { echo "ERROR: Python 3 is required for the release language audit." >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "ERROR: Node.js is required for the release language audit." >&2; exit 1; }
python3 "$ROOT/scripts/check-languages.py"
node "$ROOT/scripts/tests/history-3d.cjs"
INFO="$SRC/appinfo/info.xml"
[ -f "$INFO" ] || { echo "ERROR: Missing $INFO" >&2; exit 1; }
command -v zip >/dev/null 2>&1 || { echo 'ERROR: zip is required.' >&2; exit 1; }
APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$INFO" | head -n 1)"
VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n 1)"
[ -n "$APP_ID" ] && [ -n "$VERSION" ] || { echo 'ERROR: App ID or version is missing.' >&2; exit 1; }
sh "$ROOT/scripts/check-release.sh"

BUILD="$ROOT/.release-build"
SOURCE="$BUILD/$APP_ID-$VERSION"
RELEASE="$ROOT/release"
ARCHIVE="$RELEASE/$APP_ID-$VERSION-source.zip"
rm -rf "$BUILD"
mkdir -p "$SOURCE" "$RELEASE"
trap 'rm -rf "$BUILD"' EXIT HUP INT TERM
(cd "$ROOT" && tar -cf - .gitignore LICENSE CHANGELOG.md CHANGELOG.cs.md README.md README_CZ.md install.sh uninstall.sh build-release.sh scripts docs assets src) | (cd "$SOURCE" && tar -xf -)

for file in CHANGELOG.md README.md README_CZ.md install.sh uninstall.sh build-release.sh scripts/install-transaction.sh scripts/custom-apps-safety.sh scripts/ensure-schema.php docs/cz/05_ANALYZA_ELEKTRINY.md docs/en/05_ELECTRICITY_ANALYSIS.md src/appinfo/info.xml; do
  [ -f "$SOURCE/$file" ] || { echo "ERROR: The source package omits $file" >&2; exit 1; }
done
rm -f "$ARCHIVE"
(cd "$BUILD" && zip -qr "$ARCHIVE" "$APP_ID-$VERSION")
# Separate App Store/NAS runtime archive with exactly one hc_gridsight/ root.
RUNTIME="$BUILD/$APP_ID"
mkdir -p "$RUNTIME"
for item in appinfo bin css img js l10n lib templates; do cp -R "$SRC/$item" "$RUNTIME/$item"; done
cp "$ROOT/LICENSE" "$RUNTIME/LICENSE"
cp "$ROOT/CHANGELOG.md" "$RUNTIME/CHANGELOG.md"
(cd "$BUILD" && zip -qr "$RELEASE/$APP_ID-$VERSION.zip" "$APP_ID")
unzip -tq "$RELEASE/$APP_ID-$VERSION.zip" >/dev/null
unzip -tq "$ARCHIVE" >/dev/null
for file in install.sh uninstall.sh docs/cz/05_ANALYZA_ELEKTRINY.md src/appinfo/info.xml; do
  unzip -Z1 "$ARCHIVE" | grep -Fx "$APP_ID-$VERSION/$file" >/dev/null || { echo "ERROR: ZIP omits $file" >&2; exit 1; }
done
if unzip -Z1 "$ARCHIVE" | grep -Eiq '\.(zip|7z|rar|xlsx|gdz|pdf)$'; then
  echo 'ERROR: The full-source ZIP contains a nested archive or unrelated source attachment.' >&2
  exit 1
fi
echo "Built release/$APP_ID-$VERSION-source.zip (complete installer source only)"

echo "Built release/$APP_ID-$VERSION.zip (installable runtime)"
