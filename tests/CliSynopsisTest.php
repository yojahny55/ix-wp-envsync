<?php
use PHPUnit\Framework\TestCase;

class CliSynopsisTest extends TestCase {
	/** WP-CLI drops an option whose placeholder it cannot parse ("invalid synopsis part") and then rejects it as unknown. */
	public function test_option_placeholders_are_plain_words() {
		preg_match_all( '/^\s*\*\s*\[--[a-z-]+=<([^>]*)>\]/m', file_get_contents( __DIR__ . '/../includes/class-ixes-cli.php' ), $m );
		$this->assertNotEmpty( $m[1] );
		foreach ( $m[1] as $placeholder ) $this->assertMatchesRegularExpression( '/^[a-z0-9_-]+$/', $placeholder );
	}

	/** Without a blank line between two options WP-CLI reads them as one, so --parallel's "default: 4" became --timeout's: every request timed out after 4 s. */
	public function test_options_are_separated_by_a_blank_line() {
		preg_match_all( '/^\s*\*\s*:[^\n]*\n\s*\*\s*\[--/m', file_get_contents( __DIR__ . '/../includes/class-ixes-cli.php' ), $m );
		$this->assertSame( [], $m[0] );
	}
}
