#!/bin/zsh
# E2E: return the local test receiver to "Vietnam absent", then snapshot it as the baseline.
#
# Usage: PR_WORK=/some/dir tests/e2e/reset-clone.sh
# Deletes every post with id >= 200000 (only relays create those on the clone), the relay-test term, and
# uploads/content-relay; then writes $PR_WORK/clone-baseline.sql and $PR_WORK/clone-uploads-before.txt.
# Refuses to run unless wp-cli in the clone's docroot is connected to wtcwinnipeg_rebuild_test.
set -u
CLONE=/Users/mahesh/Code/wtcwinnipeg-rebuild-test
: "${PR_WORK:?Set PR_WORK to a scratch directory}"
mkdir -p "$PR_WORK"
cd "$CLONE" || exit 1
[ "$(wp db query 'SELECT DATABASE()' --skip-column-names 2>/dev/null)" = "wtcwinnipeg_rebuild_test" ] || { echo "ABORT: not the test database"; exit 1; }

ids=$(wp db query "SELECT ID FROM wp_posts WHERE ID >= 200000 ORDER BY post_type = 'attachment' DESC, ID" --skip-column-names 2>/dev/null | tr '\n' ' ')
[ -n "${ids// /}" ] && wp post delete ${=ids} --force 2>/dev/null | grep -c Success | sed 's/^/deleted posts: /'
wp term delete market-profile vietnam-relay-test --by=slug 2>/dev/null | grep -v Deprecated
rm -rf wp-content/uploads/content-relay
# Files relayed under a new name (-2.jpg etc.) that WordPress did not remove with their attachment.
ls wp-content/uploads/2026/09 | grep -E '^(vietnam-ho-chi-minh-city-aerial|who-can-help-vietnam-advisors|vietnam-market-map)' | head -100 | sed 's/^/  vietnam file still present: /'

wp db export "$PR_WORK/clone-baseline.sql" --single-transaction 2>/dev/null | tail -1
ls wp-content/uploads/2026/09 | sort > "$PR_WORK/clone-uploads-before.txt"
echo "baseline written to $PR_WORK"
