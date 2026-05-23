#!/usr/bin/env bash
# init-version.sh — Install and initialise a new MediaWiki version for the farm.
#
# Usage:
#   ./init-version.sh <version>
#
# Example:
#   ./init-version.sh 1.46
#
# What this script does:
#   1. Creates /srv/mediawiki/versions/<version>/
#   2. Clones MediaWiki core from Wikimedia Gerrit at the release branch
#   3. Creates the shared symlinks (LocalSettings.php, config/)
#   4. Creates a per-version localisation cache directory
#   5. Runs composer install
#   6. Fetches and installs extensions/skins defined in
#      scripts/extensions/repos-<version>.yaml (via fetch-repos.py)
#   7. Applies any patches listed in scripts/extensions/patches-<version>.yaml
#   8. Runs maintenance/update.php for all wikis assigned to this version
#      (you must answer the prompts yourself or pass --quick)
#
# After this script completes, edit config/wikiVersions.php to assign wikis
# to the new version, then run:
#   php /srv/mediawiki/scripts/mwscript.php maintenance/update.php --wiki=<db>

set -euo pipefail

FARM_ROOT="/srv/mediawiki"
SCRIPTS_DIR="$FARM_ROOT/scripts"
VERSIONS_DIR="$FARM_ROOT/versions"
CONFIG_DIR="$FARM_ROOT/config"

VERSION="${1:-}"
if [[ -z "$VERSION" ]]; then
    echo "Usage: $0 <version>"
    echo "  e.g. $0 1.46"
    exit 1
fi

# Derive REL branch: 1.46 → REL1_46
MAJOR=$(echo "$VERSION" | cut -d. -f1)
MINOR=$(echo "$VERSION" | cut -d. -f2)
REL_BRANCH="REL${MAJOR}_${MINOR}"

VERSION_DIR="$VERSIONS_DIR/$VERSION"

if [[ -d "$VERSION_DIR" ]]; then
    echo "Version directory already exists: $VERSION_DIR"
    echo "Remove it first if you want a fresh install."
    exit 1
fi

echo "==> Initialising MediaWiki $VERSION (branch: $REL_BRANCH)"

# ── 1. Clone MediaWiki core ────────────────────────────────────────────────────
echo "--> Cloning MediaWiki core..."
git clone \
    --depth=1 \
    --branch "$REL_BRANCH" \
    https://gerrit.wikimedia.org/r/mediawiki/core.git \
    "$VERSION_DIR"

# ── 2. Shared farm symlinks ────────────────────────────────────────────────────
echo "--> Creating farm symlinks..."
ln -s "$CONFIG_DIR/LocalSettings.php" "$VERSION_DIR/LocalSettings.php"
ln -s "$CONFIG_DIR"                   "$VERSION_DIR/config"

# ── 3. Per-version localisation cache ─────────────────────────────────────────
echo "--> Creating localisation cache directory..."
mkdir -p "$FARM_ROOT/cache/$VERSION"
chown www-data:www-data "$FARM_ROOT/cache/$VERSION"

# ── 4. Composer ───────────────────────────────────────────────────────────────
echo "--> Running composer install..."
(cd "$VERSION_DIR" && composer install --no-dev --prefer-dist)

# ── 5. Extensions & skins ─────────────────────────────────────────────────────
REPOS_FILE="$SCRIPTS_DIR/extensions/repos-${VERSION}.yaml"
if [[ -f "$REPOS_FILE" ]]; then
    echo "--> Fetching extensions and skins from $REPOS_FILE..."
    python3 "$SCRIPTS_DIR/extensions/fetch-repos.py" \
        --repos "$REPOS_FILE" \
        --target "$VERSION_DIR"
else
    echo "    (no repos file found at $REPOS_FILE — skipping extension install)"
fi

# ── 6. Patches ────────────────────────────────────────────────────────────────
PATCHES_FILE="$SCRIPTS_DIR/extensions/patches-${VERSION}.yaml"
if [[ -f "$PATCHES_FILE" ]]; then
    echo "--> Applying patches from $PATCHES_FILE..."
    python3 - <<'PYEOF'
import yaml, subprocess, sys, os

patches_file = os.environ.get('PATCHES_FILE', '')
version_dir  = os.environ.get('VERSION_DIR', '')

with open(patches_file) as f:
    data = yaml.safe_load(f) or {}

for entry in data.get('patches', []):
    target = os.path.join(version_dir, entry['path'])
    patch  = os.path.join(
        '/srv/mediawiki/scripts/extensions/patches',
        entry['patch']
    )
    print(f"  Applying {entry['patch']} to {entry['path']}")
    result = subprocess.run(
        ['git', 'apply', '--check', patch],
        cwd=target, capture_output=True
    )
    if result.returncode == 0:
        subprocess.run(['git', 'apply', patch], cwd=target, check=True)
    else:
        print(f"  WARNING: patch already applied or failed check — skipping.")
PYEOF
else
    echo "    (no patches file found at $PATCHES_FILE — skipping)"
fi

# Set ownership
chown -R www-data:www-data "$VERSION_DIR"

echo ""
echo "==> MediaWiki $VERSION installed at $VERSION_DIR"
echo ""
echo "Next steps:"
echo "  1. Edit $CONFIG_DIR/wikiVersions.php to assign wikis to version $VERSION"
echo "  2. Run update.php for each assigned wiki:"
echo "     php $SCRIPTS_DIR/mwscript.php maintenance/update.php --wiki=<db> --quick"
