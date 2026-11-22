#!/bin/sh
# Interní sestavení Hacesoft Publisher: oddělený vstup, veřejný GitHub a runtime.
set -eu
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SRC="$ROOT/src"
INFO="$SRC/appinfo/info.xml"

for tool in python3 node zip tar unzip; do
 command -v "$tool" >/dev/null 2>&1 || { echo "ERROR: $tool is required for release build." >&2; exit 1; }
done
[ -f "$INFO" ] || { echo "ERROR: Missing $INFO" >&2; exit 1; }
APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$INFO" | head -n 1)"
VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n 1)"
[ -n "$APP_ID" ] && [ -n "$VERSION" ] || { echo 'ERROR: App ID or version is missing.' >&2; exit 1; }
case "$APP_ID" in *[!a-z0-9_]*|'') echo 'ERROR: Invalid App ID' >&2; exit 1;; esac
case "$VERSION" in *[!0-9a-zA-Z._-]*|'') echo 'ERROR: Invalid version' >&2; exit 1;; esac

python3 "$ROOT/scripts/check-languages.py"
node "$ROOT/scripts/tests/history-3d.cjs"
node "$ROOT/scripts/tests/linea-outage.cjs"
sh "$ROOT/scripts/check-release.sh"

BUILD="$ROOT/.release-build"
RELEASE="$ROOT/release"
NAME="$APP_ID-$VERSION"
INTERNAL="$BUILD/internal/$NAME"
GITHUB="$BUILD/github/$NAME"
RUNTIME="$BUILD/runtime/$APP_ID"
mkdir -p "$RELEASE"
rm -rf "$BUILD"
mkdir -p "$INTERNAL" "$GITHUB/scripts" "$RUNTIME"
trap 'rm -rf "$BUILD"' EXIT HUP INT TERM

# Publisher input: one COMPLETE development project, including the test runners.
(cd "$ROOT" && tar -cf - .gitignore LICENSE CHANGELOG.md CHANGELOG.cs.md README.md README_CZ.md install.sh uninstall.sh build-release.sh scripts docs src) | (cd "$INTERNAL" && tar -xf -)
# Public GitHub selection: complete usable app source, install/uninstall helpers,
# Czech/English guides. No Publisher/internal test runner or audit evidence.
(cd "$ROOT" && tar -cf - .gitignore LICENSE CHANGELOG.md CHANGELOG.cs.md README.md README_CZ.md install.sh uninstall.sh docs src) | (cd "$GITHUB" && tar -xf -)
for helper in collector.sh install-transaction.sh custom-apps-safety.sh ensure-schema.php; do
 cp "$ROOT/scripts/$helper" "$GITHUB/scripts/$helper"
done

# Nextcloud runtime: one root named exactly as the App ID.
for item in appinfo bin css img js l10n lib templates; do cp -R "$SRC/$item" "$RUNTIME/$item"; done
cp "$ROOT/LICENSE" "$RUNTIME/LICENSE"
cp "$ROOT/CHANGELOG.md" "$RUNTIME/CHANGELOG.md"

INTERNAL_ZIP="$RELEASE/$NAME-full-source.zip"
GITHUB_ZIP="$RELEASE/$NAME-GITHUB-SOURCE.zip"
RUNTIME_ZIP="$RELEASE/$NAME.zip"
RUNTIME_TAR="$RELEASE/$NAME.tar.gz"
rm -f "$INTERNAL_ZIP" "$GITHUB_ZIP" "$RUNTIME_ZIP" "$RUNTIME_TAR"
(cd "$BUILD/internal" && zip -qr "$INTERNAL_ZIP" "$NAME")
(cd "$BUILD/github" && zip -qr "$GITHUB_ZIP" "$NAME")
(cd "$BUILD/runtime" && zip -qr "$RUNTIME_ZIP" "$APP_ID" && tar -czf "$RUNTIME_TAR" "$APP_ID")
for archive in "$INTERNAL_ZIP" "$GITHUB_ZIP" "$RUNTIME_ZIP"; do unzip -tq "$archive" >/dev/null; done
for file in install.sh uninstall.sh scripts/install-transaction.sh scripts/custom-apps-safety.sh scripts/ensure-schema.php src/appinfo/info.xml; do
 unzip -Z1 "$INTERNAL_ZIP" | grep -Fx "$NAME/$file" >/dev/null || { echo "ERROR: Publisher archive omits $file" >&2; exit 1; }
 unzip -Z1 "$GITHUB_ZIP" | grep -Fx "$NAME/$file" >/dev/null || { echo "ERROR: GitHub archive omits $file" >&2; exit 1; }
done
unzip -Z1 "$RUNTIME_ZIP" | grep -Fx "$APP_ID/appinfo/info.xml" >/dev/null || { echo 'ERROR: Runtime has no info.xml' >&2; exit 1; }
if unzip -Z1 "$GITHUB_ZIP" | grep -Eq "(^|/)(docs/hds|tests|\.release-build)(/|$)|(^|/)(build-release\.sh|check-release\.sh|package\.json|package-lock\.json)$|/scripts/tests/"; then
 echo 'ERROR: Internal build/test assets leaked to the public GitHub source.' >&2
 exit 1
fi
printf 'Created Publisher input: %s\nCreated public GitHub source: %s\nCreated unsigned runtime: %s and %s\n' "$INTERNAL_ZIP" "$GITHUB_ZIP" "$RUNTIME_ZIP" "$RUNTIME_TAR"
