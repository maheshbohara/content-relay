<?php
/**
 * E2E helper: prints the receiver's relayed Vietnam state (run on the clone with `wp eval-file`).
 *
 * Shows every post with id >= 200000 (the clone's offset range): attachments with language, file, UUID and
 * superseded marker; pages with excerpt, hero image id, first heading and the events term. Read-only.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

global $wpdb;
$scan  = new \ContentRelay\Content\Block_Scanner();
$codec = new \ContentRelay\Content\Attributes_Codec();
$ids   = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID >= 200000 ORDER BY ID" ) );

foreach ( $ids as $id ) {
	$p = get_post( $id );
	if ( 'attachment' === $p->post_type ) {
		echo "  att $id lang=" . apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $id, 'element_type' => 'post_attachment' ) )
			. ' file=' . get_post_meta( $id, '_wp_attached_file', true )
			. ' uuid=' . substr( (string) get_post_meta( $id, '_content_relay_uuid', true ), 0, 8 )
			. ' superseded_by=' . get_post_meta( $id, '_content_relay_superseded_by', true ) . "\n";
		continue;
	}
	$vals = array();
	foreach ( $scan->scan( $p->post_content ) as $t ) {
		$a = $t->has_attrs() ? $codec->decode( $scan->attrs_json( $p->post_content, $t ) ) : null;
		foreach ( array( 'heading', 'hero_image', 'events_market_profile' ) as $k ) {
			if ( $a && isset( $a->data->$k ) && ! isset( $vals[ $k ] ) ) {
				$vals[ $k ] = $a->data->$k;
			}
		}
	}
	$term = get_term( (int) ( $vals['events_market_profile'] ?? 0 ) );
	echo "  page $id {$p->post_name} excerpt=[{$p->post_excerpt}] heading=[" . ( $vals['heading'] ?? '' ) . '] hero=' . ( $vals['hero_image'] ?? '' )
		. ' term=' . json_encode( $vals['events_market_profile'] ?? null ) . '(' . ( $term instanceof WP_Term ? $term->slug : '-' ) . ")\n";
}

echo '  posts>=200000: ' . count( $ids )
	. ' | pending: ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_content_relay_pending'" )
	. ' | relay-test term: ' . ( term_exists( 'vietnam-relay-test', 'market-profile' ) ? 'yes' : 'no' ) . "\n";
