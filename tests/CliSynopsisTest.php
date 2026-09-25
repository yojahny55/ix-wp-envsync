<?php
use PHPUnit\Framework\TestCase;

class CliSynopsisTest extends TestCase {
	/** WP-CLI drops an option whose placeholder it cannot parse ("invalid synopsis part") and then rejects it as unknown. */
	public function test_option_placeholders_are_plain_words() {
		preg_match_all( '/^\s*\*\s*\[--[a-z-]+=<([^>]*)>\]/m', file_get_contents( __DIR__ . '/../includes/class-ixes-cli.php' ), $m );
		$this->assertNotEmpty( $m[1] );
		foreach ( $m[1] as $placeholder ) $this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $placeholder );
	}
}
