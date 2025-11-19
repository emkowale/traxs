#!/usr/bin/env bash
# Universal WordPress Plugin Release Script
# Auto-bump, changelog, zip, tag, and publish to GitHub — local is king.
# Also writes `.lucy.json` memory anchor for persistent context with Lucy.

set -Eeuo pipefail

# ===== EDIT THESE PER PLUGIN ==================================================
OWNER="emkowale"         # GitHub username/org
REPO="traxs"             # GitHub repository name
PLUGIN_SLUG="traxs"      # folder name of the plugin
MAIN_FILE="traxs.php"    # main plugin file (in root or inside PLUGIN_SLUG/)
# ==============================================================================

REMOTE_URL="git@github.com:${OWNER}/${REPO}.git"

C0=$'\033[0m'; C1=$'\033[1;36m'; C2=$'\033[1;32m'; C3=$'\033[1;33m'; C4=$'\033[1;31m'
step(){ printf "${C1}🔷 %s${C0}\n" "$*"; }
ok(){   printf "${C2}✅ %s${C0}\n" "$*"; }
warn(){ printf "${C3}⚠ %s${C0}\n" "$*"; }
die(){  printf "${C4}❌ %s${C0}\n" "$*"; exit 1; }
trap 'printf "${C4}❌ Failed at line %s${C0}\n" "$LINENO"' ERR

BUMP="${1:-patch}"
[[ "$BUMP" =~ ^(major|minor|patch)$ ]] || die "Usage: ./release.sh {major|minor|patch}"

for cmd in git php zip rsync curl sed awk; do
  command -v "$cmd" >/dev/null || die "$cmd not found"
done

# --- Detect / init repo root --------------------------------------------------
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if git -C "$HERE" rev-parse --show-toplevel >/dev/null 2>&1; then
  ROOT="$(git -C "$HERE" rev-parse --show-toplevel)"
  step "Using existing git repo at $ROOT"
  cd "$ROOT"
else
  # Not a repo yet – initialize in THIS directory
  ROOT="$HERE"
  cd "$ROOT"
  step "Initializing new git repo at $ROOT"
  git init -b main >/dev/null 2>&1
fi

# --- Ensure remote exists -----------------------------------------------------
if git remote | grep -q '^origin$'; then
  git remote set-url origin "$REMOTE_URL" >/dev/null 2>&1 || true
else
  step "Setting up git remote"
  git remote add origin "$REMOTE_URL" >/dev/null 2>&1 || true
  ok "Remote 'origin' set to $REMOTE_URL"
fi

git fetch origin main --tags >/dev/null 2>&1 || true
git switch -C main >/dev/null 2>&1 || true

# --- Locate main file ---------------------------------------------------------
if [[ -f "${PLUGIN_SLUG}/${MAIN_FILE}" ]]; then
  SRC_DIR="${PLUGIN_SLUG}"
  MAIN_PATH="${PLUGIN_SLUG}/${MAIN_FILE}"
elif [[ -f "${MAIN_FILE}" ]]; then
  SRC_DIR="."
  MAIN_PATH="${MAIN_FILE}"
else
  die "Cannot find ${MAIN_FILE}"
fi

# --- Read current version -----------------------------------------------------
step "Reading current version"
BASE=$(php -r '
$path=$argv[1];
if(!file_exists($path)){echo "0.0.0";exit;}
preg_match("/Version:\s*([0-9.]+)/i",file_get_contents($path),$m);
echo $m[1]??"0.0.0";
' "$MAIN_PATH")

LATEST=$(git tag --list 'v*' | sed -n 's/^v//p' | sort -V | tail -1)
if [[ -n "$LATEST" ]]; then
  # Take the greater of BASE vs latest tag
  if [[ "$(printf '%s\n%s\n' "$LATEST" "$BASE" | sort -V | tail -1)" == "$LATEST" ]]; then
    BASE="$LATEST"
  fi
fi

IFS=. read -r MA MI PA <<<"${BASE:-0.0.0}"
MA=${MA:-0}; MI=${MI:-0}; PA=${PA:-0}
case "$BUMP" in
  major) ((MA++)); MI=0; PA=0;;
  minor) ((MI++)); PA=0;;
  patch) ((PA++));;
esac
NEXT="${MA}.${MI}.${PA}"
ok "Version bump: $BASE → $NEXT"

# --- Bump version in main file ------------------------------------------------
step "Updating version in $MAIN_PATH"
php -r '
$f=$argv[1];$v=$argv[2];
$t=file_get_contents($f);
$t=preg_replace("/(Version:\s*)([0-9.]+)/i","\${1}$v",$t,1);
file_put_contents($f,$t);
' "$MAIN_PATH" "$NEXT"

git add -A
git commit -m "chore(release): v${NEXT}" >/dev/null 2>&1 || true

# --- Update changelog safely --------------------------------------------------
step "Updating changelog"
TODAY=$(date +%Y-%m-%d)

COMMITS=$(git rev-list --count HEAD 2>/dev/null || echo 0)
if (( COMMITS > 1 )); then
  # Diff against previous commit
  CHANGES=$(git diff --name-only HEAD~1 HEAD || true)
else
  # First commit or repo just initialized – list tracked files instead
  CHANGES=$(git ls-files || true)
fi

{
  echo "v${NEXT} — ${TODAY}"
  echo ""
  if [[ -n "$CHANGES" ]]; then
    echo "Changed files:"
    echo "$CHANGES" | sed 's/^/- /'
  else
    echo "- No file changes recorded"
  fi
  echo ""
} | cat - CHANGELOG.md 2>/dev/null > CHANGELOG.tmp
mv CHANGELOG.tmp CHANGELOG.md

git add CHANGELOG.md
git commit -m "docs: changelog v${NEXT}" >/dev/null 2>&1 || true

# --- Tag and push -------------------------------------------------------------
git tag -f "v${NEXT}" >/dev/null 2>&1 || true
step "Pushing to GitHub (local wins)"
if ! git push -f origin main; then
  warn "Push to origin main failed (check SSH key / permissions)"
fi
if ! git push -f origin "v${NEXT}"; then
  warn "Tag push failed (check SSH key / permissions)"
fi
ok "Repository synced"

# --- Build zip ----------------------------------------------------------------
step "Building ZIP package"
mkdir -p artifacts package
rm -rf "package/${PLUGIN_SLUG}"
rsync -a --exclude ".git" --exclude "artifacts" --exclude "package" --exclude ".github" --exclude ".DS_Store" "${SRC_DIR}/" "package/${PLUGIN_SLUG}/"
(
  cd package
  zip -qr "../artifacts/${PLUGIN_SLUG}-v${NEXT}.zip" "${PLUGIN_SLUG}"
)
ok "Created artifacts/${PLUGIN_SLUG}-v${NEXT}.zip"

ZIP_PATH="artifacts/${PLUGIN_SLUG}-v${NEXT}.zip"

# --- Write Lucy memory anchor -------------------------------------------------
step "Writing .lucy.json memory anchor"
cat > .lucy.json <<JSON
{
  "plugin_name": "${PLUGIN_SLUG^}",
  "owner": "${OWNER}",
  "repo": "${REPO}",
  "slug": "${PLUGIN_SLUG}",
  "main_file": "${MAIN_FILE}",
  "branch": "main",
  "version": "${NEXT}",
  "memory_anchor": true
}
JSON
git add .lucy.json
git commit -m "chore: update .lucy.json memory anchor v${NEXT}" >/dev/null 2>&1 || true
git push -f origin main >/dev/null 2>&1 || true
ok ".lucy.json memory anchor updated"

# --- Publish GitHub release ---------------------------------------------------
API_ROOT="https://api.github.com/repos/${OWNER}/${REPO}"
TAG="v${NEXT}"
RELEASE_NAME="v${NEXT}"
BODY="Release v${NEXT}\n\nSee CHANGELOG.md for details."

publish_with_gh() {
  step "Publishing release to GitHub via gh"
  if gh release view "$TAG" -R "${OWNER}/${REPO}" >/dev/null 2>&1; then
    gh release upload "$TAG" "$ZIP_PATH" --clobber -R "${OWNER}/${REPO}"
  else
    gh release create "$TAG" "$ZIP_PATH" -t "$RELEASE_NAME" -n "$BODY" -R "${OWNER}/${REPO}"
  fi
  ok "GitHub release published via gh"
}

publish_with_curl() {
  [[ -n "${GITHUB_TOKEN:-}" ]] || { warn "GITHUB_TOKEN not set; cannot publish release via curl"; return 0; }

  step "Publishing release to GitHub via curl (GITHUB_TOKEN)"

  # Does a release for this tag already exist?
  set +e
  REL_JSON=$(curl -s -H "Authorization: Bearer ${GITHUB_TOKEN}" -H "Accept: application/vnd.github+json" \
    "${API_ROOT}/releases/tags/${TAG}")
  CURL_STATUS=$?
  set -e

  RELEASE_ID=""
  if [[ $CURL_STATUS -eq 0 && "$REL_JSON" == *'"id":'* ]]; then
    RELEASE_ID=$(printf '%s\n' "$REL_JSON" | awk -F'id":' 'NR==1{print $2}' | awk -F',' '{print $1}' | tr -dc '0-9')
  fi

  if [[ -z "$RELEASE_ID" ]]; then
    # Create release
    set +e
    CREATE_JSON=$(curl -s -X POST \
      -H "Authorization: Bearer ${GITHUB_TOKEN}" \
      -H "Accept: application/vnd.github+json" \
      "${API_ROOT}/releases" \
      -d @- <<EOF
{"tag_name":"${TAG}","name":"${RELEASE_NAME}","body":"${BODY}","draft":false,"prerelease":false}
EOF
    )
    CURL_STATUS=$?
    set -e
    if [[ $CURL_STATUS -ne 0 || "$CREATE_JSON" != *'"id":'* ]]; then
      warn "Failed to create GitHub release via curl"
      return 0
    fi
    RELEASE_ID=$(printf '%s\n' "$CREATE_JSON" | awk -F'id":' 'NR==1{print $2}' | awk -F',' '{print $1}' | tr -dc '0-9')
  fi

  # Upload asset
  ASSET_NAME="$(basename "$ZIP_PATH")"
  UPLOAD_URL="https://uploads.github.com/repos/${OWNER}/${REPO}/releases/${RELEASE_ID}/assets?name=${ASSET_NAME}"

  set +e
  curl -s -X POST \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "Content-Type: application/zip" \
    --data-binary @"${ZIP_PATH}" \
    "${UPLOAD_URL}" >/dev/null
  CURL_STATUS=$?
  set -e

  if [[ $CURL_STATUS -ne 0 ]]; then
    warn "Failed to upload asset to GitHub release via curl"
  else
    ok "GitHub release + asset published via curl"
  fi
}

if command -v gh >/dev/null 2>&1; then
  if gh auth status -h github.com >/dev/null 2>&1; then
    publish_with_gh
  elif [[ -n "${GITHUB_TOKEN:-}" ]]; then
    warn "gh not authenticated; falling back to curl + GITHUB_TOKEN"
    publish_with_curl
  else
    warn "gh not authenticated and GITHUB_TOKEN not set; GitHub release upload skipped"
  fi
elif [[ -n "${GITHUB_TOKEN:-}" ]]; then
  publish_with_curl
else
  warn "GitHub CLI not available and GITHUB_TOKEN not set; GitHub release upload skipped"
fi

printf "${C2}🎉 Done — v${NEXT} released, zipped, and synced${C0}\n"
