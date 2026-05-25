<?php
class Test_Bootstrap extends WP_UnitTestCase {
    public function test_plugin_constants_defined() {
        $this->assertTrue( defined( 'AI4D_TOPICS_VERSION' ) );
        $plugin_data = get_file_data( AI4D_TOPICS_PLUGIN_FILE, array( 'Version' => 'Version' ) );
        $this->assertEquals( $plugin_data['Version'], AI4D_TOPICS_VERSION, 'AI4D_TOPICS_VERSION must match the Version: header in the plugin file.' );
        $this->assertTrue( defined( 'AI4D_TOPICS_PLUGIN_FILE' ) );
        $this->assertTrue( defined( 'AI4D_TOPICS_PLUGIN_DIR' ) );
    }
}
