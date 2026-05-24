<?php
class Test_Bootstrap extends WP_UnitTestCase {
    public function test_plugin_constants_defined() {
        $this->assertTrue( defined( 'AI4D_TOPICS_VERSION' ) );
        $this->assertEquals( '0.1.0', AI4D_TOPICS_VERSION );
        $this->assertTrue( defined( 'AI4D_TOPICS_PLUGIN_FILE' ) );
        $this->assertTrue( defined( 'AI4D_TOPICS_PLUGIN_DIR' ) );
    }
}
