#!/bin/zsh
# Screenshots only: sets up two local sites for capture.mjs.
#
#   Staging (sending)   http://localhost:8900  the repository's own wp-env site
#   Live    (receiving) http://localhost:8910  tests/screenshots/receiver
#
# Seeds a small café site on Staging, relays "Spring Menu" once and edits it again so the next plan shows an update
# with a diff. Writes the login used by capture.mjs to tests/screenshots/.auth (ignored by git).
# Usage: tests/screenshots/seed.sh   (then: node tests/screenshots/capture.mjs)
set -eu
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$ROOT/tests/screenshots"
staging() { (cd "$ROOT" && npx wp-env run cli "$@" 2>&1 | grep -vE '^(ℹ|✔)' || true); }
live() { (cd "$HERE/receiver" && npx --prefix "$ROOT" wp-env run cli "$@" 2>&1 | grep -vE '^(ℹ|✔)' || true); }
container() { docker ps --format '{{.Names}}' | grep -E "^wp-env-$1-[0-9a-f]+-cli-1$" | head -1; }

(cd "$ROOT" && npx wp-env start >/dev/null)
(cd "$HERE/receiver" && npx --prefix "$ROOT" wp-env start >/dev/null)

docker cp "$HERE/reach-receiver.php" "$(container content-relay):/var/www/html/wp-content/mu-plugins/content-relay-screens.php"

# A fresh login for capture.mjs on both sites.
PASS=$(openssl rand -hex 12)
for site in staging live; do
	$site wp user delete screens alex --yes >/dev/null
	$site wp user create alex alex@example.test --role=administrator --display_name=Alex --user_pass="$PASS" >/dev/null
done
printf 'alex\n%s\n' "$PASS" > "$HERE/.auth"

staging wp option update blogname 'Harbour Café · Staging'
live wp option update blogname 'Harbour Café'

# Start the relay history and keys over.
for site in staging live; do
	$site wp db query 'DELETE FROM wp_content_relay_log' >/dev/null
	$site wp eval 'foreach ( array( "content-relay/staging", "content-relay/outgoing", "content-relay/backups", "content-relay/sessions" ) as $d ) { $p = wp_get_upload_dir()["basedir"] . "/" . $d; if ( is_dir( $p ) ) { exec( "rm -rf " . escapeshellarg( $p ) ); } }'
done
live wp eval 'ContentRelay\Config::set( "receive", true ); delete_option( "content_relay_receiver_keys" );'
staging wp eval 'ContentRelay\Config::set( "send", true ); delete_option( "content_relay_connections" );'

# Pages from earlier runs on Live are removed so the first relay creates them again.
live wp eval 'foreach ( array( "spring-menu", "catering", "visit-us", "about" ) as $s ) { $p = get_page_by_path( $s ); if ( $p ) { wp_delete_post( $p->ID, true ); } } foreach ( get_posts( array( "post_type" => "attachment", "numberposts" => -1 ) ) as $a ) { wp_delete_attachment( $a->ID, true ); }'

KEY=$(live wp content-relay keys generate --label='Staging site' --user=alex | grep -oE 'contentrelay1\.[A-Za-z0-9_-]+' | head -1)
staging wp content-relay connections add "$KEY" --label=live --user=alex

staging wp eval-file /var/www/html/wp-content/plugins/content-relay/tests/screenshots/seed.php

# One relay already happened, then the page changed on Staging.
SPRING=$(staging wp post list --post_type=page --name=spring-menu --field=ID | tr -d '[:space:]')
CATERING=$(staging wp post list --post_type=page --name=catering --field=ID | tr -d '[:space:]')
staging wp content-relay push "$SPRING" --to=live --yes --user=alex | tail -1
staging wp content-relay push "$CATERING" --to=live --yes --user=alex | tail -1
staging wp eval "\$p = get_post( $SPRING ); wp_update_post( array( 'ID' => $SPRING, 'post_title' => 'Spring Menu 2027', 'post_content' => str_replace( array( 'cold brew on tap', 'from Ethiopia.' ), array( 'cold brew and nitro on tap', 'from Ethiopia, and a new oat-milk flat white.' ), \$p->post_content ) ) );"
echo "seeded: Spring Menu is page $SPRING on Staging"
