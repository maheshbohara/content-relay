#!/bin/zsh
# E2E: scenarios 1, 2, 7, 4, 8, 12 against the local test receiver (spec §17, results in §20).
#
# Prerequisites:
#   1. PR_WORK=/some/dir tests/e2e/reset-clone.sh                      (clone without Vietnam + baseline)
#   2. (on wtcwinnipeg.test) cp -R "$(wp content-relay bundle 4356 --porcelain)" $PR_WORK/bundle-v2
#   3. tests/e2e/make-variants.py $PR_WORK
# Usage: PR_WORK=/some/dir tests/e2e/run-scenarios.sh
set -u
: "${PR_WORK:?Set PR_WORK to the directory used by reset-clone.sh}"
S="$PR_WORK"
E2E="$(cd "$(dirname "$0")" && pwd)"
C=/Users/mahesh/Code/wtcwinnipeg-rebuild-test
clean() { grep -vE '^(Deprecated|PHP Deprecated)' ; }
cd "$C" || exit 1
[ "$(wp db query 'SELECT DATABASE()' --skip-column-names 2>/dev/null)" = "wtcwinnipeg_rebuild_test" ] || { echo ABORT; exit 1; }

state() { wp eval-file "$E2E/verify-state.php" 2>&1 | clean; }
summary() { wp content-relay plan "$1" ${2:-} --format=json 2>/dev/null | python3 -c "import json,sys,collections; r=sys.stdin.read(); p=json.loads(r[r.index('{'):]); print('  plan:', dict(collections.Counter((i['kind'],i['action']) for i in p['items'] if i['role']=='owned' or (i['kind']=='term' and i['action']!='reuse'))), '| blockers:', p['blockers'])"; }
apply() { OUT=$(wp content-relay apply "$@" --yes 2>&1 | clean); echo "$OUT" | grep -E "^(Success: Relay|Success: Nothing|Error|Warning: (Stored|Translation))"; SID=$(echo "$OUT" | sed -nE 's/^Session ([0-9a-f-]+)\..*/\1/p'); echo "  session: $SID"; }
uploads_new() { ls wp-content/uploads/2026/09 | sort | comm -13 "$S/clone-uploads-before.txt" - | wc -l | tr -d ' '; }

echo "=== 0 clean-state check (expect 0 posts, 0 files)"; state; echo "  relay files: $(uploads_new)"
echo "=== S1 create";                 apply "$S/bundle-v2"; state; echo "  relay files: $(uploads_new) (expect 57)"
echo "=== S2 re-plan (all unchanged)"; summary "$S/bundle-v2"
echo "=== S6 missing parent (blocker)"; summary "$S/v2-s6"
echo "=== S7 new term";                summary "$S/v2-s7"; apply "$S/v2-s7"; S7=$SID; state
echo "=== S7 rollback";                wp content-relay rollback "$S7" --yes 2>&1 | clean | tail -1; state; summary "$S/bundle-v2"
EN_PAGE=$(wp db query "SELECT p.ID FROM wp_posts p JOIN wp_icl_translations t ON t.element_id = p.ID AND t.element_type = 'post_page' WHERE p.ID >= 200000 AND t.language_code = 'en' ORDER BY p.ID LIMIT 1" --skip-column-names 2>/dev/null)
echo "=== S4 receiver edit on EN page $EN_PAGE -> conflict"; wp db query "UPDATE wp_posts SET post_excerpt='edited on receiver' WHERE ID=$EN_PAGE" 2>/dev/null; summary "$S/v2-s3"
echo "=== S4 overwrite";               summary "$S/v2-s3" --overwrite; apply "$S/v2-s3" --overwrite; S4=$SID; state
echo "=== S4 rollback (edit returns)";  wp content-relay rollback "$S4" --yes 2>&1 | clean | tail -1; state
echo "=== S8 file changed on sender";   wp db query "UPDATE wp_posts SET post_excerpt='' WHERE ID>=200000" 2>/dev/null; summary "$S/v2-s8"; apply "$S/v2-s8"; S8=$SID; state; echo "  relay files: $(uploads_new) (expect 76)"
echo "=== S8 re-plan (all unchanged)";   summary "$S/v2-s8"
echo "=== S8 rollback";                wp content-relay rollback "$S8" --yes 2>&1 | clean | tail -1; state; echo "  relay files: $(uploads_new) (expect 57)"
echo "=== S12 injected failure";        BEFORE=$(uploads_new); wp eval-file "$E2E/inject-failure.php" "$S/v2-s8" 2>&1 | clean; state; echo "  relay files: before=$BEFORE after=$(uploads_new)"
