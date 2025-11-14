#!/usr/bin/env bash
# Universal WP plugin release script: bump, changelog (changed files), push (local wins), zip, tag, GH release
set -Eeuo pipefail

# ====== EDIT THESE FOR EACH PLUGIN ============================================
OWNER="emkowale"
REPO="traxs"
PLUGIN_SLUG="traxs"      # folder name inside the zip
MAIN_FILE="traxs.php"    # main plugin file (repo root or under PLUGIN_SLUG/)
# ==============================================================================

REMOTE_URL="git@github.com:${OWNER}/${REPO}.git"

C0=$'\033[0m'; C1=$'\033[1;36m'; C2=$'\033[1;32m'; C3=$'\033[1;33m'; C4=$'\033[1;31m'
step(){ printf "${C1}🔷 %s${C0}\n" "$*"; }
ok(){   printf "${C2}✅ %s${C0}\n" "$*"; }
warn(){ printf "${C3}⚠ %s${C0}\n" "$*"; }
die(){  printf "${C4}❌ %s${C0}\n" "$*"; exit 1; }
trap 'printf "${C4}❌ Failed at line %s${C0}\n" "$LINENO"' ERR

BUMP="${1:-patch}"; [[ "$BUMP" =~ ^(major|minor|patch)$ ]] || die "Usage: ./release.sh {major|minor|patch}"
command -v git >/dev/null || die "git not found"
command -v php >/dev/null || die "php not found"
command -v zip >/dev/null || die "zip not found"
command -v rsync >/dev/null || die "rsync not found"
command -v curl >/dev/null || die "curl not found"
command -v sed >/dev/null || die "sed not found"
command -v awk >/dev/null || die "awk not found"
command -v jq >/dev/null || warn "jq not found (fallbacks to grep/sed for API paths)"

# --- Locate repo root (allow running from one level under) --------------------
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; cd "$ROOT"
[[ -d .git ]] || { [[ -d ../.git ]] && cd .. || true; }

# --- Init repo if needed ------------------------------------------------------
if [[ ! -d .git ]]; then
  step "Init git"
  git init -b main >/dev/null 2>&1 || { git init; git branch -M main; }
  git add -A; git commit -m "chore: initial import" >/dev/null 2>&1 || true
  git remote add origin "$REMOTE_URL" >/dev/null 2>&1 || git remote set-url origin "$REMOTE_URL"
fi

# --- Resolve source dir and main file -----------------------------------------
if [[ -f "${PLUGIN_SLUG}/${MAIN_FILE}" ]]; then
  SRC_DIR="${PLUGIN_SLUG}"; MAIN_PATH="${PLUGIN_SLUG}/${MAIN_FILE}"
elif [[ -f "${MAIN_FILE}" ]]; then
  SRC_DIR="."; MAIN_PATH="${MAIN_FILE}"
else
  die "Cannot find ${MAIN_FILE} (root or ${PLUGIN_SLUG}/)"
fi

# --- Make sure we’re on main and fetch (but local remains king) ---------------
step "Prepare git"
git switch -C main >/dev/null
git remote set-url origin "$REMOTE_URL" >/dev/null 2>&1 || true
git fetch origin main --tags >/dev/null 2>&1 || true
ok "Git ready"

# --- Read current version from header/constant --------------------------------
step "Read version"
readver_php=$(cat <<'PHP'
$path=$argv[1];
$src=@file_get_contents($path);
if($src===false){ echo "0.0.0"; exit(0); }
$vers=[];
if(preg_match_all('/(?mi)^\s*(?:\*\s*)?Version\s*:\s*([0-9]+\.[0-9]+\.[0-9]+)/',$src,$m)) $vers=array_merge($vers,$m[1]);
if(preg_match_all("/define\\(\\s*'([A-Z0-9_]+_VERSION)'\\s*,\\s*'([0-9]+\\.[0-9]+\\.[0-9]+)'\\s*\\)\\s*;/",$src,$m))
  foreach($m[2] as $v) $vers[]=$v;
if(!$vers){ echo "0.0.0"; exit(0); }
usort($vers,'version_compare');
echo end($vers);
PHP
)
BASE="$(php -r "$readver_php" "$MAIN_PATH" 2>/dev/null || echo "0.0.0")"
[[ -n "${BASE:-}" ]] || BASE="0.0.0"

latest="$(git tag --list 'v*' | sed -n 's/^v\([0-9]\+\.[0-9]\+\.[0-9]\+\)$/\1/p' | sort -V | tail -n1 || true)"
ver_ge(){ local hi; hi="$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)"; [[ "$hi" == "$1" ]]; }
[[ -n "${latest:-}" ]] && ver_ge "$latest" "$BASE" && BASE="$latest"

# --- Parse MA.MI.PA robustly --------------------------------------------------
set +u
IFS=. read -r MA MI PA <<<"${BASE:-0.0.0}"
MA=${MA:-0}; MI=${MI:-0}; PA=${PA:-0}
set -u

case "$BUMP" in
  major) MA=$((MA+1)); MI=0; PA=0;;
  minor) MI=$((MI+1)); PA=0;;
  patch) PA=$((PA+1));;
esac

NEXT="${MA}.${MI}.${PA}"
while git rev-parse -q --verify "refs/tags/v$NEXT" >/dev/null 2>&1; do
  PA=$((PA+1)); NEXT="${MA}.${MI}.${PA}"
done
ok "Next: v${NEXT}"

# --- Bump Version in main file ------------------------------------------------
step "Bump ${MAIN_PATH}"
fix_php=$(cat <<'PHP'
$path=$argv[1]; $ver=$argv[2]; $slug=$argv[3];
$src=@file_get_contents($path);
if($src===false){ $src=''; }
$src=preg_replace("/\r\n?/", "\n", $src);
$lines=explode("\n",$src); $s=-1;$e=-1;
for($i=0;$i<min(400,count($lines));$i++){ if(preg_match("/^\s*\/\*/",$lines[$i])){$s=$i;break;} }
if($s>=0){ for($j=$s;$j<min($s+120,count($lines));$j++){ if(preg_match("/\*\//",$lines[$j])){$e=$j;break;} } }
if($s<0||$e<0){
  array_splice($lines,0,0,["/*"," * Version: $ver"," */"]);
}else{
  for($k=$s;$k<=$e;$k++){ if(preg_match("/^\s*(?:\*\s*)?Version\s*:/i",$lines[$k])) $lines[$k]=null; }
  $t=[]; foreach($lines as $ln){ if($ln!==null)$t[]=$ln; } $lines=$t;
  array_splice($lines,$s+1,0," * Version: $ver");
}
$src=implode("\n",$lines);
if(preg_match("/^\\s*define\\(\\s*'([A-Z0-9_]+_VERSION)'\\s*,\\s*'[^']*'\\s*\\)\\s*;/m",$src,$m)){
  $const=$m[1];
  $src=preg_replace("/^\\s*define\\(\\s*'".$const."'\\s*,\\s*'[^']*'\\s*\\)\\s*;/m","define('".$const."','$ver');",$src,1);
}else{
  $const=strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',$slug))."_VERSION";
  if(preg_match("/defined\\(\\s*'ABSPATH'\\s*\\)/",$src))
    $src=preg_replace("/(defined\\(\\s*'ABSPATH'\\s*\\).*?exit;\\s*)/s","$1\n\ndefine('".$const."','$ver');\n",$src,1);
  else $src="<?php\ndefine('".$const."','$ver');\n?>\n".$src;
}
@file_put_contents($path,$src);
PHP
)
[[ -f "$MAIN_PATH" ]] || printf "/*\n * Version: %s\n */\n<?php\n" "$BASE" > "$MAIN_PATH"
php -r "$fix_php" "$MAIN_PATH" "$NEXT" "$PLUGIN_SLUG" 2>/dev/null || true

# --- Stage EVERYTHING and create the bump commit ------------------------------
git add -A
git commit -m "chore(release): v${NEXT} (bump)" >/dev/null 2>&1 || true

# --- Compute changed files SINCE LAST TAG (for release notes) -----------------
LAST_TAG="$(git describe --tags --abbrev=0 2>/dev/null || echo '')"
RANGE="${LAST_TAG:+$LAST_TAG..}HEAD"
CHANGED="$(git diff --name-only ${RANGE} | grep -v '^CHANGELOG\.md$' || true)"
if [[ -z "$CHANGED" && -z "$LAST_TAG" ]]; then
  CHANGED="$(git ls-files | grep -v '^CHANGELOG\.md$' || true)"
fi

TODAY="$(date +%Y-%m-%d)"
NEW_TOP=$(
  {
    printf "v%s — %s\n\n" "$NEXT" "$TODAY"
    echo "Changed files:"
    if [[ -n "$CHANGED" ]]; then
      echo "$CHANGED" | sed 's/^/- /'
      echo
    else
      echo "- (none)"
      echo
    fi
  }
)

# --- Write CHANGELOG (prepend) ------------------------------------------------
step "Update CHANGELOG.md"
{ printf "%s" "$NEW_TOP"; [[ -f CHANGELOG.md ]] && cat CHANGELOG.md; } > .CHANGELOG.new
mv .CHANGELOG.new CHANGELOG.md
git add CHANGELOG.md
git commit -m "chore(release): v${NEXT} (changelog)" >/dev/null 2>&1 || true

# --- Tag after changelog is committed ----------------------------------------
git tag -f "v${NEXT}"

# --- Push (local is king) -----------------------------------------------------
step "Push (local is king)"
git push -f origin main
git push -f origin "v${NEXT}"
ok "Git pushed"

# --- Build zip ----------------------------------------------------------------
step "Build zip"
ART="artifacts"; PKG="package/${PLUGIN_SLUG}"; ZIP="${PLUGIN_SLUG}-v${NEXT}.zip"
rm -rf "$ART" "$PKG"; mkdir -p "$ART" "$PKG"
EXC=(--exclude ".git/" --exclude "artifacts/" --exclude "package/" --exclude ".github/" --exclude ".DS_Store")
if [[ "$SRC_DIR" == "." ]]; then
  rsync -a "${EXC[@]}" ./ "$PKG/"
else
  rsync -a "${EXC[@]}" "${SRC_DIR}/" "$PKG/"
fi
( cd package && zip -qr "../${ART}/${ZIP}" "${PLUGIN_SLUG}" )
ok "Built ${ART}/${ZIP}"

# ======================== GitHub Release (gh or API) ==========================
publish_with_gh() {
  step "GitHub release v${NEXT} (gh)"
  BODY_FILE=".gh_release_body.md"
  printf "%s" "$NEW_TOP" > "$BODY_FILE"
  if gh release view "v${NEXT}" -R "${OWNER}/${REPO}" >/dev/null 2>&1; then
    gh release edit "v${NEXT}" -F "$BODY_FILE" -R "${OWNER}/${REPO}" >/dev/null
    gh release upload "v${NEXT}" "${ART}/${ZIP}" --clobber -R "${OWNER}/${REPO}" >/dev/null
  else
    gh release create "v${NEXT}" "${ART}/${ZIP}" -t "v${NEXT}" -F "$BODY_FILE" -R "${OWNER}/${REPO}" >/dev/null
  fi
  rm -f "$BODY_FILE"
  ok "Published"
}

publish_with_api() {
  [[ -n "${GITHUB_TOKEN:-}" ]] || die "GITHUB_TOKEN not set. Run: export GITHUB_TOKEN=<token-with-repo-scope>"

  API="https://api.github.com"
  UPAPI="https://uploads.github.com"
  HDR=(-H "Authorization: token $GITHUB_TOKEN" -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28")

  step "GitHub release v${NEXT} (API)"
  # 1) Try get release by tag
  get_json="$(curl -sS "${HDR[@]}" "$API/repos/${OWNER}/${REPO}/releases/tags/v${NEXT}" || true)"
  if echo "$get_json" | grep -q '"id":'; then
    rel_id="$(echo "$get_json" | (command -v jq >/dev/null && jq -r '.id') || echo "$get_json" | sed -n 's/.*"id":[ ]*\([0-9]\+\).*/\1/p')"
    # Update body
    curl -sS -X PATCH "${HDR[@]}" "$API/repos/${OWNER}/${REPO}/releases/$rel_id" \
      -d "$(printf '{"name":"v%s","body":%s}' "$NEXT" "$(printf '%s' "$NEW_TOP" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')")" >/dev/null
  else
    # Create release
    create_json="$(curl -sS -X POST "${HDR[@]}" "$API/repos/${OWNER}/${REPO}/releases" \
      -d "$(printf '{"tag_name":"v%s","name":"v%s","body":%s,"draft":false,"prerelease":false}' "$NEXT" "$NEXT" "$(printf '%s' "$NEW_TOP" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')")")"
    if ! echo "$create_json" | grep -q '"id":'; then
      die "Failed to create release via API. Response: $create_json"
    fi
    rel_id="$(echo "$create_json" | (command -v jq >/dev/null && jq -r '.id') || echo "$create_json" | sed -n 's/.*"id":[ ]*\([0-9]\+\).*/\1/p')"
  fi

  # 2) Delete existing asset with same name (if any)
  assets_json="$(curl -sS "${HDR[@]}" "$API/repos/${OWNER}/${REPO}/releases/$rel_id/assets")"
  if echo "$assets_json" | grep -q '"name":'; then
    asset_id="$(echo "$assets_json" | (command -v jq >/dev/null && jq -r --arg n "$ZIP" '.[] | select(.name==$n) | .id') || echo "$assets_json" | grep -oE '{"id":[0-9]+,"name":"[^"]+"}' | grep "\"name\":\"$ZIP\"" | sed -n 's/{"id":\([0-9]\+\).*/\1/p')"
    if [[ -n "${asset_id:-}" ]]; then
      curl -sS -X DELETE "${HDR[@]}" "$API/repos/${OWNER}/${REPO}/releases/assets/$asset_id" >/dev/null || true
    fi
  fi

  # 3) Upload asset
  curl -sS -X POST -H "Authorization: token $GITHUB_TOKEN" -H "Content-Type: application/zip" \
    "$UPAPI/repos/${OWNER}/${REPO}/releases/$rel_id/assets?name=$(printf '%s' "$ZIP" | sed 's/ /%20/g')" \
    --data-binary @"${ART}/${ZIP}" >/dev/null

  ok "Published"
}

# Decide path: gh (if authed) else API (if token) else fail with instruction
if command -v gh >/dev/null 2>&1 && gh auth status -h github.com >/dev/null 2>&1; then
  publish_with_gh
elif [[ -n "${GITHUB_TOKEN:-}" ]]; then
  publish_with_api
else
  die "No GitHub auth detected. Either login gh (gh auth login -h github.com -s repo) or export GITHUB_TOKEN=<token-with-repo-scope> and rerun."
fi

printf "${C2}🎉 Done: artifacts/${ZIP}${C0}\n"
