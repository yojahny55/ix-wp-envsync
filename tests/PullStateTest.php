<?php
use PHPUnit\Framework\TestCase;

class PullStateTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-ps-' . getmypid() . '-' . uniqid();
		mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
	}
	public function test_no_state_loads_null() { $this->assertNull( IXES_PullState::load( 'prod' ) ); }

	public function test_start_then_progress_round_trips() {
		$s = IXES_PullState::start( 'prod', '/plans/p.json', '0.3.0', [] );
		$s->cursor( 'wp_posts', null );
		$s->cursor( 'wp_posts', '500' );
		$s->table_done( 'wp_posts' );
		$s->cursor( 'wp_postmeta', '184233' );
		$s->files_done( 512 );
		$r = IXES_PullState::load( 'prod' );
		$this->assertSame( [ 'wp_posts' ], $r->get( 'tables_done' ) );
		$this->assertSame( 'wp_postmeta', $r->get( 'table' ) );
		$this->assertSame( '184233', $r->get( 'cursor' ) );
		$this->assertSame( 512, $r->get( 'files_done' ) );
		$this->assertSame( '/plans/p.json', $r->get( 'plan' ) );
	}
	public function test_table_done_clears_cursor() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->cursor( 'wp_posts', '9' ); $s->table_done( 'wp_posts' );
		$this->assertNull( $s->get( 'table' ) ); $this->assertNull( $s->get( 'cursor' ) );
	}
	public function test_describe_mentions_table_row_and_files() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->cursor( 'wp_postmeta', '184233' ); $s->files_done( 512 );
		$d = $s->describe( 2300 );
		$this->assertStringContainsString( 'prod', $d );
		$this->assertStringContainsString( 'wp_postmeta', $d );
		$this->assertStringContainsString( '184233', $d );
		$this->assertStringContainsString( '512/2300', $d );
	}
	public function test_refusal_reasons() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->cursor( 'wp_posts', '1' );
		$this->assertSame( '', $s->refusal( '0.3.0', [ 'a/' ], [ 'a/' ], [], [], true ) );
		$this->assertStringContainsString( 'version', $s->refusal( '0.3.1', [ 'a/' ], [ 'a/' ], [], [], true ) );
		$this->assertStringContainsString( 'excludes', $s->refusal( '0.3.0', [ 'a/' ], [ 'b/' ], [], [], true ) );
		$this->assertStringContainsString( 'replace', $s->refusal( '0.3.0', [], [], [ [ 'x', 'y' ] ], [], true ) );
		$this->assertStringContainsString( 'wp_posts', $s->refusal( '0.3.0', [], [], [], [], false ) );
	}
	public function test_clear_removes_file() {
		$s = IXES_PullState::start( 'prod', 'p', '0.3.0', [] );
		$s->clear();
		$this->assertNull( IXES_PullState::load( 'prod' ) );
	}
}
