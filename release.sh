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

# --- Detect repo root ---------------------------------------------------------
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"
[[ -d .git ]] || { [[ -d ../.git ]] && cd .. || die "Not in a git repo"; }

# --- Ensure remote exists -----------------------------------------------------
if ! git remote | grep -q '^origin$'; then
  step "Setting up git remote"
  git init -b main >/dev/null 2>&1 || true
  git remote add origin "$REMOTE_URL"
  ok "Remote 'origin' added: $REMOTE_URL"
fi

git remote set-url origin "$REMOTE_URL" >/dev/null 2>&1 || true
git fetch origin main --tags >/dev/null 2>&1 || true
git switch -C main >/dev/null 2>&1 || true

# --- Locate main file ---------------------------------------------------------
if [[ -f "${PLUGIN_SLUG}/${MAIN_FILE}" ]]; then
  SRC_DIR="${PLUGIN_SLUG}"; MAIN_PATH="${PLUGIN_SLUG}/${MAIN_FILE}"
elif [[ -f "${MAIN_FILE}" ]]; then
  SRC_DIR="."; MAIN_PATH="${MAIN_FILE}"
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
[[ -n "$LATEST" && "$(printf '%s\n%s\n' "$LATEST" "$BASE" | sort -V | tail -1)" == "$LATEST" ]] && BASE="$LATEST"

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

# --- Update changelog ---------------------------------------------------------
step "Updating changelog"
TODAY=$(date +%Y-%m-%d)
CHANGES=$(git diff --name-only HEAD~1 HEAD || true)
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
} | cat - CHANGELOG.md > CHANGELOG.tmp && mv CHANGELOG.tmp CHANGELOG.md
git add CHANGELOG.md
git commit -m "docs: changelog v${NEXT}" >/dev/null 2>&1 || true

# --- Tag and push -------------------------------------------------------------
git tag -f "v${NEXT}"
step "Pushing to GitHub (local wins)"
git push -f origin main || warn "Push to origin main failed (check auth)"
git push -f origin "v${NEXT}" || warn "Tag push failed (check auth)"
ok "Repository synced"

# --- Build zip ----------------------------------------------------------------
step "Building ZIP package"
mkdir -p artifacts package
rm -rf package/${PLUGIN_SLUG}
rsync -a --exclude ".git" --exclude "artifacts" --exclude "package" --exclude ".github" --exclude ".DS_Store" "${SRC_DIR}/" "package/${PLUGIN_SLUG}/"
(cd package && zip -qr "../artifacts/${PLUGIN_SLUG}-v${NEXT}.zip" "${PLUGIN_SLUG}")
ok "Created artifacts/${PLUGIN_SLUG}-v${NEXT}.zip"

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
ok "Memory anchor updated"

# --- Publish GitHub release ---------------------------------------------------
if command -v gh >/dev/null && gh auth status -h github.com >/dev/null 2>&1; then
  step "Publishing release to GitHub via gh"
  BODY="Release v${NEXT}\n\nSee CHANGELOG.md for details."
  if gh release view "v${NEXT}" -R "${OWNER}/${REPO}" >/dev/null 2>&1; then
    gh release upload "v${NEXT}" "artifacts/${PLUGIN_SLUG}-v${NEXT}.zip" --clobber -R "${OWNER}/${REPO}"
  else
    gh release create "v${NEXT}" "artifacts/${PLUGIN_SLUG}-v${NEXT}.zip" -t "v${NEXT}" -n "$BODY" -R "${OWNER}/${REPO}"
  fi
  ok "GitHub release published"
else
  warn "GitHub CLI not authenticated; release upload skipped"
fi

printf "${C2}🎉 Done — v${NEXT} released and synced${C0}\n"
