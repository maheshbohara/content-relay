<?php
/**
 * Plugin Name:       Content Relay
 * Description:       Move a page or post, with translations and media, between dev, staging and production sites on the same theme version. Plan review and rollback.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Mahesh Bohara
 * Author URI:        http://www.maheshbohara.com.np/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       content-relay
 * Domain Path:       /languages
 *
 * @package ContentRelay
 */

defined( 'ABSPATH' ) || exit;

define( 'CONTENT_RELAY_VERSION', '1.0.0' );
define( 'CONTENT_RELAY_SCHEMA', 1 );
define( 'CONTENT_RELAY_FILE', __FILE__ );
define( 'CONTENT_RELAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONTENT_RELAY_URL', plugin_dir_url( __FILE__ ) );

require_once CONTENT_RELAY_DIR . 'includes/class-autoloader.php';
\ContentRelay\Autoloader::register();

register_activation_hook( __FILE__, array( \ContentRelay\Plugin::class, 'activate' ) );
add_action( 'plugins_loaded', array( \ContentRelay\Plugin::class, 'boot' ) );
