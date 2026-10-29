<?php
/**
 * Screenshots only: creates the demo pages and images on the sending site. Run by seed.sh with `wp eval-file`.
 *
 * @package ContentRelay
 */

// phpcs:ignoreFile -- Test harness, not plugin code.

$content_relay_images = array(
	'spring-menu-hero' => array( array( 0x7a, 0x4a, 0x2e ), array( 0xe8, 0xb0, 0x6a ) ),
	'catering-spread'  => array( array( 0x23, 0x4e, 0x3c ), array( 0xd9, 0xc5, 0x8c ) ),
	'visit-us-map'     => array( array( 0x1f, 0x3b, 0x5c ), array( 0x9c, 0xc4, 0xe0 ) ),
);

/**
 * Draws a 1200×675 JPEG with a diagonal gradient and soft circles, and imports it.
 */
$content_relay_image = static function ( $name, $from, $to ) {
	$image = imagecreatetruecolor( 1200, 675 );
	for ( $x = 0; $x < 1200; $x += 4 ) {
		$t     = $x / 1200;
		$color = imagecolorallocate( $image, (int) ( $from[0] + ( $to[0] - $from[0] ) * $t ), (int) ( $from[1] + ( $to[1] - $from[1] ) * $t ), (int) ( $from[2] + ( $to[2] - $from[2] ) * $t ) );
		imagefilledrectangle( $image, $x, 0, $x + 3, 674, $color );
	}
	mt_srand( crc32( $name ) );
	for ( $i = 0; $i < 9; $i++ ) {
		$white = imagecolorallocatealpha( $image, 255, 255, 255, mt_rand( 96, 118 ) );
		$size  = mt_rand( 120, 420 );
		imagefilledellipse( $image, mt_rand( 0, 1200 ), mt_rand( 0, 675 ), $size, $size, $white );
	}
	$path = get_temp_dir() . $name . '.jpg';
	imagejpeg( $image, $path, 88 );

	$existing = get_posts( array( 'post_type' => 'attachment', 'name' => $name, 'numberposts' => 1 ) );
	foreach ( $existing as $old ) {
		wp_delete_attachment( $old->ID, true );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$file = array( 'name' => $name . '.jpg', 'tmp_name' => $path );
	return media_handle_sideload( $file, 0, ucwords( str_replace( '-', ' ', $name ) ) );
};

foreach ( array( 'relay-test-page' ) as $content_relay_leftover ) {
	$content_relay_post = get_page_by_path( $content_relay_leftover );
	if ( $content_relay_post ) {
		foreach ( get_attached_media( '', $content_relay_post->ID ) as $content_relay_media ) {
			wp_delete_attachment( $content_relay_media->ID, true );
		}
		wp_delete_post( $content_relay_post->ID, true );
	}
}
foreach ( get_posts( array( 'post_type' => 'attachment', 'title' => 'Relay test image', 'numberposts' => -1 ) ) as $content_relay_media ) {
	wp_delete_attachment( $content_relay_media->ID, true );
}

$content_relay_ids = array();
foreach ( $content_relay_images as $name => $colors ) {
	$content_relay_ids[ $name ] = $content_relay_image( $name, $colors[0], $colors[1] );
}

$content_relay_author = get_user_by( 'login', 'alex' );

$content_relay_block = static function ( $id, $alt ) {
	$url = wp_get_attachment_image_url( $id, 'large' );
	return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/></figure>' . "\n" . '<!-- /wp:image -->';
};

$content_relay_paragraph = static function ( $text ) {
	return '<!-- wp:paragraph -->' . "\n" . '<p>' . $text . '</p>' . "\n" . '<!-- /wp:paragraph -->';
};

$content_relay_pages = array(
	'spring-menu' => array(
		'Spring Menu',
		array(
			$content_relay_paragraph( 'Our spring menu is here: lighter roasts, cold brew on tap and seasonal pastries baked every morning.' ),
			$content_relay_block( $content_relay_ids['spring-menu-hero'], 'Spring drinks on the counter' ),
			'<!-- wp:heading -->' . "\n" . '<h2 class="wp-block-heading">Drinks</h2>' . "\n" . '<!-- /wp:heading -->',
			$content_relay_paragraph( 'Honey lavender latte, cold brew tonic and a single-origin pour-over from Ethiopia.' ),
		),
	),
	'catering'    => array(
		'Catering',
		array(
			$content_relay_paragraph( 'Coffee and pastries for meetings of ten to two hundred people, delivered across town.' ),
			$content_relay_block( $content_relay_ids['catering-spread'], 'A catering table' ),
		),
	),
	'visit-us'    => array(
		'Visit Us',
		array(
			$content_relay_paragraph( 'Open every day from 7 am to 6 pm at 14 Harbour Street.' ),
			$content_relay_block( $content_relay_ids['visit-us-map'], 'Map to the café' ),
		),
	),
	'about'       => array(
		'About Us',
		array( $content_relay_paragraph( 'A small roastery and café by the harbour since 2014.' ) ),
	),
);

foreach ( $content_relay_pages as $slug => $page ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) {
		wp_delete_post( $existing->ID, true );
	}
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_author'  => $content_relay_author ? $content_relay_author->ID : 0,
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $page[0],
			'post_content' => implode( "\n\n", $page[1] ),
		)
	);
}

echo 'seeded ' . count( $content_relay_pages ) . " pages\n";
