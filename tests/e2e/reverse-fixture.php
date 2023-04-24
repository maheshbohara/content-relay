<?php
/**
 * E2E scenario 10 fixture: a throwaway EN+FR draft page with its own freshly generated image, on the site that SENDS
 * in the reverse direction (the clone). Nothing in it can match existing content on the other site.
 *
 * Usage (on the clone): wp eval-file …/tests/e2e/reverse-fixture.php create|edit|delete --user=<admin>
 *   create — generates uploads/…/content-relay-reverse-test-<random>.png, an attachment (WPML duplicates it into FR),
 *            an EN draft page using it and a linked FR draft page using the FR attachment. Prints EN=<id>.
 *   edit   — changes the EN heading with a direct row update (so WPML does not sync anything onto FR).
 *   delete — removes every post marked _pr_e2e_fixture and their files.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

global $wpdb;

$marker = '_pr_e2e_fixture';
$action = $args[0] ?? '';

$fixture_ids = static function () use ( $wpdb, $marker ): array {
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $marker ) ) );
};

$content = static function ( int $attachment_id, string $heading ): string {
	$url = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
	return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">{$heading}</h2>\n<!-- /wp:heading -->\n\n"
		. "<!-- wp:image {\"id\":{$attachment_id},\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->\n"
		. "<figure class=\"wp-block-image size-full\"><img src=\"{$url}\" alt=\"\" class=\"wp-image-{$attachment_id}\"/></figure>\n<!-- /wp:image -->";
};

if ( 'create' === $action ) {
	if ( array() !== $fixture_ids() ) {
		WP_CLI::error( 'The fixture already exists; delete it first.' );
	}
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		WP_CLI::error( 'GD is not available.' );
	}

	$image = imagecreatetruecolor( 640, 400 );
	for ( $i = 0; $i < 40; $i++ ) {
		$color = imagecolorallocate( $image, random_int( 0, 255 ), random_int( 0, 255 ), random_int( 0, 255 ) );
		imagefilledrectangle( $image, random_int( 0, 600 ), random_int( 0, 360 ), random_int( 0, 640 ), random_int( 0, 400 ), $color );
	}
	ob_start();
	imagepng( $image );
	$bytes = (string) ob_get_clean();

	$upload = wp_upload_bits( 'content-relay-reverse-test-' . bin2hex( random_bytes( 3 ) ) . '.png', null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		WP_CLI::error( $upload['error'] );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$attachment_id = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Content Relay reverse test image',
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
	update_post_meta( $attachment_id, $marker, '1' );

	$fr_attachment = (int) apply_filters( 'wpml_object_id', $attachment_id, 'attachment', false, 'fr' );

	$en_id = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Content Relay reverse test',
			'post_name'    => 'content-relay-reverse-test',
			'post_content' => $content( $attachment_id, 'Reverse direction, version 1' ),
		),
		true
	);
	update_post_meta( $en_id, $marker, '1' );

	$trid  = (int) apply_filters( 'wpml_element_trid', null, $en_id, 'post_page' );
	$fr_id = (int) wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Content Relay test inverse',
			'post_name'    => 'content-relay-reverse-test',
			'post_content' => $content( $fr_attachment > 0 ? $fr_attachment : $attachment_id, 'Sens inverse, version 1' ),
		),
		true
	);
	do_action( 'wpml_set_element_language_details', array( 'element_id' => $fr_id, 'element_type' => 'post_page', 'trid' => $trid, 'language_code' => 'fr', 'source_language_code' => 'en' ) );
	update_post_meta( $fr_id, $marker, '1' );
	if ( $fr_attachment > 0 && $fr_attachment !== $attachment_id ) {
		update_post_meta( $fr_attachment, $marker, '1' );
	}

	WP_CLI::log( sprintf( 'attachment EN=%1$d FR=%2$d file=%3$s', $attachment_id, $fr_attachment, basename( $upload['file'] ) ) );
	WP_CLI::log( sprintf( 'FR=%d', $fr_id ) );
	WP_CLI::log( sprintf( 'EN=%d', $en_id ) );
} elseif ( 'edit' === $action ) {
	$en_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = 'post_page' WHERE t.language_code = 'en'", $marker ) );
	$body  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $en_id ) );
	$wpdb->update( $wpdb->posts, array( 'post_content' => str_replace( 'version 1', 'version 2', $body ) ), array( 'ID' => $en_id ) );
	clean_post_cache( $en_id );
	WP_CLI::log( sprintf( 'EN %d heading now: %s', $en_id, false !== strpos( (string) get_post_field( 'post_content', $en_id, 'raw' ), 'version 2' ) ? 'version 2' : 'UNCHANGED' ) );
} elseif ( 'delete' === $action ) {
	$ids = $fixture_ids();
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	WP_CLI::log( sprintf( 'Deleted fixture posts: %s', implode( ', ', $ids ) ) );
} else {
	WP_CLI::error( 'Usage: create|edit|delete' );
}
