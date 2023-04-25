#!/bin/zsh
# E2E scenario 10: reverse direction, clone (sender) -> source (receiver), with a throwaway EN+FR draft page.
#
# Writes to the SOURCE database (wtcwinnipeg_rebuild): creates two draft pages and two attachments, updates one, then
# rolls both sessions back from the clone and removes every artefact it made (the temporary receive mu-plugin, the
# source key, the clone connection, the fixture, uploads/content-relay on the source if this run created it).
#
# Usage: PR_WORK=/some/dir tests/e2e/run-reverse.sh
set -u
: "${PR_WORK:?Set PR_WORK to a scratch directory}"
mkdir -p "$PR_WORK"
S=/Users/mahesh/Code/wtcwinnipeg-rebuild
C=/Users/mahesh/Code/wtcwinnipeg-rebuild-test
E2E="$(cd "$(dirname "$0")" && pwd)"
REQ=$E2E/local-sender.php
USER_LOGIN=claudeagent
clean() { grep -vE '^(Deprecated|PHP Deprecated)'; }
sdb() { (cd $S && wp db query "$1" --skip-column-names 2>/dev/null); }
cdb() { (cd $C && wp db query "$1" --skip-column-names 2>/dev/null); }
check() { if [ "$2" = "$3" ]; then echo "PASS $1 ($2)"; else echo "FAIL $1 (expected $3, got $2)"; fi; }
[ "$(sdb 'SELECT DATABASE()')" = "wtcwinnipeg_rebuild" ] || { echo "ABORT: source DB"; exit 1; }
[ "$(cdb 'SELECT DATABASE()')" = "wtcwinnipeg_rebuild_test" ] || { echo "ABORT: clone DB"; exit 1; }

HAD_PR_DIR=$([ -d $S/wp-content/uploads/content-relay ] && echo yes || echo no)
KEYS_BEFORE=$(sdb "SELECT COUNT(*) FROM wp_options WHERE option_name='content_relay_receiver_keys'")
page_sql="FROM wp_posts p JOIN wp_icl_translations t ON t.element_id=p.ID AND t.element_type='post_page' WHERE p.post_name='content-relay-reverse-test' AND p.post_type='page'"
srcstate() {
  sdb "SELECT CONCAT_WS(' ', ' page', p.ID, p.post_status, t.language_code, CONCAT('trid=',t.trid), CONCAT('src=',IFNULL(t.source_language_code,'-')), CONCAT('img=',SUBSTRING_INDEX(SUBSTRING(p.post_content, LOCATE('wp-image-', p.post_content)+9), '\"', 1)), CONCAT('v2=', p.post_content LIKE '%version 2%'), CONCAT('clonehost=', p.post_content LIKE '%wtcwinnipeg-test.test%'), MD5(p.post_content)) $page_sql ORDER BY p.ID"
  sdb "SELECT CONCAT_WS(' ', ' att', p.ID, t.language_code, CONCAT('trid=',t.trid), m.meta_value) FROM wp_posts p JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wp_attached_file' LEFT JOIN wp_icl_translations t ON t.element_id=p.ID AND t.element_type='post_attachment' WHERE m.meta_value LIKE '%content-relay-reverse-test-%'"
  echo "  source files: $(ls $S/wp-content/uploads/2026/09 | grep -c content-relay-reverse-test) (clone has $(ls $C/wp-content/uploads/2026/09 | grep -c content-relay-reverse-test))"
}
trids() { sdb "SELECT COUNT(DISTINCT t.trid) $page_sql"; }
lang_md5() { sdb "SELECT MD5(p.post_content) $page_sql AND t.language_code='$1'"; }

cp $E2E/receive-mu-plugin.php $S/wp-content/mu-plugins/content-relay-e2e-receive.php
(cd $S && wp content-relay keys generate --label=wtcwinnipeg-test.test --user=$USER_LOGIN 2>&1 | clean) > $PR_WORK/source-key.txt
SKID=$(sed -nE 's/^Key id: ([a-f0-9]+)/\1/p' $PR_WORK/source-key.txt)
(cd $C && wp --require=$REQ content-relay connections add "$(grep '^contentrelay1\.' $PR_WORK/source-key.txt)" --label=source --user=$USER_LOGIN 2>&1 | clean)
rm -f $PR_WORK/source-key.txt
EN=$(cd $C && wp eval-file $E2E/reverse-fixture.php create --user=$USER_LOGIN 2>&1 | clean | tee /dev/stderr | sed -nE 's/^EN=([0-9]+)/\1/p')

echo "=== S10.1 create: clone -> source"
(cd $C && wp --require=$REQ content-relay push $EN --to=source --yes 2>&1 | clean) > $PR_WORK/push-s10-1.txt
grep -E '^(create|update|adopt|conflict|missing)\s|^(Files uploaded|Success: Relay|Error|Blocked)' $PR_WORK/push-s10-1.txt
SID1=$(sed -nE 's/^Relay session ([0-9a-f-]+) .*/\1/p' $PR_WORK/push-s10-1.txt); srcstate
check "EN and FR share one translation group after create" "$(trids)" 1
TRID=$(sdb "SELECT t.trid $page_sql AND t.language_code='en'")

echo "=== S10.2 re-push unchanged"
(cd $C && wp --require=$REQ content-relay push $EN --to=source --yes 2>&1 | clean) | grep -E '^(Files to|Success: Nothing|Error|Blocked)'

echo "=== S10.3 EN heading edited on clone -> update"
FR_BEFORE=$(lang_md5 fr)
(cd $C && wp eval-file $E2E/reverse-fixture.php edit 2>&1 | clean)
(cd $C && wp --require=$REQ content-relay push $EN --to=source --yes 2>&1 | clean) > $PR_WORK/push-s10-3.txt
grep -E '^(update|unchanged|create|adopt|conflict)\s.*Page|^(Success: Relay|Error|Blocked)' $PR_WORK/push-s10-3.txt
SID3=$(sed -nE 's/^Relay session ([0-9a-f-]+) .*/\1/p' $PR_WORK/push-s10-3.txt); srcstate
check "EN carries version 2" "$(sdb "SELECT p.post_content LIKE '%version 2%' $page_sql AND t.language_code='en'")" 1
check "FR byte-identical after EN update" "$(lang_md5 fr)" "$FR_BEFORE"
check "EN kept its translation group" "$(sdb "SELECT t.trid $page_sql AND t.language_code='en'")" "$TRID"
check "EN and FR still share one group" "$(trids)" 1

echo "=== rollback S10.3 then S10.1, from the clone"
(cd $C && wp --require=$REQ content-relay rollback "$SID3" --to=source --yes 2>&1 | clean); srcstate
check "EN back to version 1" "$(sdb "SELECT p.post_content LIKE '%version 2%' $page_sql AND t.language_code='en'")" 0
(cd $C && wp --require=$REQ content-relay rollback "$SID1" --to=source --yes 2>&1 | clean); srcstate

echo "=== cleanup"
(cd $C && wp eval-file $E2E/reverse-fixture.php delete 2>&1 | clean; wp --require=$REQ content-relay connections remove source 2>&1 | clean)
(cd $S && wp content-relay keys revoke $SKID 2>&1 | clean)
[ "$KEYS_BEFORE" = "0" ] && (cd $S && wp option delete content_relay_receiver_keys 2>&1 | clean)
rm -f $S/wp-content/mu-plugins/content-relay-e2e-receive.php
LEFT=$(sdb "SELECT COUNT(*) FROM wp_posts p LEFT JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wp_attached_file' WHERE p.post_name='content-relay-reverse-test' OR m.meta_value LIKE '%content-relay-reverse-test-%'")
if [ "$HAD_PR_DIR" = "no" ] && [ "$LEFT" = "0" ]; then rm -rf $S/wp-content/uploads/content-relay; fi
check "source rows left" "$LEFT" 0
check "source files left" "$(ls $S/wp-content/uploads/2026/09 | grep -c content-relay-reverse-test)" 0
check "source receive mu-plugin removed" "$(ls $S/wp-content/mu-plugins | grep -c content-relay)" 0
check "clone fixture rows left" "$(cdb "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_pr_e2e_fixture'")" 0
