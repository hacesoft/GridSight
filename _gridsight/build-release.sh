#!/bin/sh
set -eu
ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
SRC="$ROOT/src"
INFO="$SRC/appinfo/info.xml"
AUDIT="$ROOT/scripts/check-release.sh"
[ -f "$INFO" ] || { echo "ERROR: $INFO not found" >&2; exit 1; }
APP_ID="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$INFO" | head -n 1)"
VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$INFO" | head -n 1)"
[ -n "$APP_ID" ] || { echo "ERROR: app id not found" >&2; exit 1; }
[ -n "$VERSION" ] || { echo "ERROR: version not found" >&2; exit 1; }
for item in appinfo css img js l10n lib templates bin; do
  [ -e "$SRC/$item" ] || { echo "ERROR: missing src/$item" >&2; exit 1; }
done
[ -x "$AUDIT" ] || chmod 755 "$AUDIT"
"$AUDIT"

BUILD="$ROOT/.release-build"
APP="$BUILD/$APP_ID"
SOURCE="$BUILD/$APP_ID-$VERSION"
RELEASE="$ROOT/release"
rm -rf "$BUILD"
mkdir -p "$APP" "$SOURCE" "$RELEASE"
for item in appinfo css img js l10n lib templates bin; do cp -R "$SRC/$item" "$APP/$item"; done
cp "$ROOT/LICENSE" "$APP/LICENSE"
cp "$ROOT/README.md" "$ROOT/README_CZ.md" "$APP/"
# The NAS installs this full-source archive via install.sh. Keep all helper
# scripts beside it and keep staging outside Nextcloud's custom_apps.
(cd "$ROOT" && tar -cf - LICENSE README.md README_CZ.md install.sh uninstall.sh build-release.sh scripts docs src) | (cd "$SOURCE" && tar -xf -)
INSTALL_FILES="README.md README_CZ.md install.sh scripts/install-transaction.sh scripts/custom-apps-safety.sh scripts/ensure-schema.php src/appinfo/info.xml"
for file in $INSTALL_FILES; do [ -f "$SOURCE/$file" ] || { echo "ERROR: source archive omits $file" >&2; exit 1; }; done

# Release runtime must never contain legacy Guard artifacts.
if find "$APP" -type f -iname '*core-guard*' | grep -q .; then
  echo "ERROR: legacy Guard artifact entered release tree" >&2
  exit 1
fi
if grep -RIlE '/apps/hc_shared_app_core/api/v1/status|data-core-status-url|data-core-script-url|data-core-style-url|data-app-script-url' "$APP" 2>/dev/null | grep -q .; then
  echo "ERROR: legacy startup/status plumbing entered release tree" >&2
  exit 1
fi

rm -f "$RELEASE/$APP_ID-$VERSION.tar.gz" "$RELEASE/$APP_ID-$VERSION.zip" "$RELEASE/$APP_ID-$VERSION-source.zip"
(
  cd "$BUILD"
  tar -czf "$RELEASE/$APP_ID-$VERSION.tar.gz" "$APP_ID"
  if command -v zip >/dev/null 2>&1; then zip -qr "$RELEASE/$APP_ID-$VERSION.zip" "$APP_ID"; fi
  if command -v zip >/dev/null 2>&1; then zip -qr "$RELEASE/$APP_ID-$VERSION-source.zip" "$APP_ID-$VERSION"; fi
)

# Verify created archive contents without installing or changing Nextcloud.
tar -tzf "$RELEASE/$APP_ID-$VERSION.tar.gz" | grep -Fx "$APP_ID/appinfo/info.xml" >/dev/null
if [ -f "$RELEASE/$APP_ID-$VERSION.zip" ]; then
  unzip -tq "$RELEASE/$APP_ID-$VERSION.zip" >/dev/null
  unzip -l "$RELEASE/$APP_ID-$VERSION.zip" | grep -F "$APP_ID/appinfo/info.xml" >/dev/null
  if unzip -l "$RELEASE/$APP_ID-$VERSION.zip" | grep -qi 'core-guard'; then
    echo "ERROR: Guard artifact found in release ZIP" >&2
    exit 1
  fi
fi
if [ -f "$RELEASE/$APP_ID-$VERSION-source.zip" ]; then
  unzip -tq "$RELEASE/$APP_ID-$VERSION-source.zip" >/dev/null
  for file in $INSTALL_FILES; do
    unzip -Z1 "$RELEASE/$APP_ID-$VERSION-source.zip" | grep -Fx "$APP_ID-$VERSION/$file" >/dev/null || { echo "ERROR: source ZIP omits $file" >&2; exit 1; }
  done
fi
rm -rf "$BUILD"
echo "Built release/$APP_ID-$VERSION.tar.gz"
[ -f "$RELEASE/$APP_ID-$VERSION.zip" ] && echo "Built release/$APP_ID-$VERSION.zip"
[ -f "$RELEASE/$APP_ID-$VERSION-source.zip" ] && echo "Built release/$APP_ID-$VERSION-source.zip"
