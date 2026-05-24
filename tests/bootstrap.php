<?php
/**
 * PHPUnit bootstrap for the ai4d-topics plugin.
 *
 * In the Docker setup, WP_TESTS_DIR is set by docker-compose.yml to
 * /tmp/wordpress-tests-lib (inside the container).
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find $_tests_dir/includes/functions.php — run 'make install-wp-tests' first.\n";
    exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
    require dirname( __DIR__ ) . '/ai4d-topics.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
