<?php
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase {
	private function s( array $assoc ) { return IXES_Scope::from_assoc( $assoc, 'wp_' ); }

	public function test_no_flags_is_full() {
		$s = $this->s( [] );
		$this->assertTrue( $s->is_full() ); $this->assertTrue( $s->db_wanted() ); $this->assertTrue( $s->files_wanted() );
		$this->assertTrue( $s->table_in( 'wp_anything' ) ); $this->assertTrue( $s->path_in( 'uploads/x.jpg' ) );
		$this->assertSame( 'everything', $s->label() );
	}
	public function test_only_db_disables_files() {
		$s = $this->s( [ 'only' => 'db' ] );
		$this->assertTrue( $s->db_wanted() ); $this->assertFalse( $s->files_wanted() );
		$this->assertFalse( $s->path_in( 'themes/a/style.css' ) );
	}
	public function test_only_themes_and_uploads() {
		$s = $this->s( [ 'only' => 'themes,uploads' ] );
		$this->assertFalse( $s->db_wanted() );
		$this->assertTrue( $s->path_in( 'themes/a/style.css' ) );
		$this->assertTrue( $s->path_in( 'uploads/2026/a.jpg' ) );
		$this->assertFalse( $s->path_in( 'plugins/x/x.php' ) );
		$this->assertFalse( $s->path_in( 'index.php' ) );
	}
	public function test_only_files_means_all_of_wp_content() {
		$s = $this->s( [ 'only' => 'files' ] );
		$this->assertTrue( $s->path_in( 'index.php' ) ); $this->assertTrue( $s->path_in( 'plugins/x/x.php' ) );
	}
	public function test_tables_implies_only_db_and_matches_with_or_without_prefix() {
		$s = $this->s( [ 'tables' => 'posts,wp_postmeta,wc_*' ] );
		$this->assertFalse( $s->files_wanted() );
		$this->assertTrue( $s->table_in( 'wp_posts' ) ); $this->assertTrue( $s->table_in( 'wp_postmeta' ) );
		$this->assertTrue( $s->table_in( 'wp_wc_orders' ) ); $this->assertFalse( $s->table_in( 'wp_options' ) );
	}
	public function test_paths_implies_only_files_prefix_and_glob() {
		$s = $this->s( [ 'paths' => 'themes/mk/,uploads/2026/*' ] );
		$this->assertFalse( $s->db_wanted() );
		$this->assertTrue( $s->path_in( 'themes/mk/style.css' ) ); $this->assertTrue( $s->path_in( 'themes/mk/inc/a.php' ) );
		$this->assertFalse( $s->path_in( 'themes/mkx/style.css' ) );
		$this->assertTrue( $s->path_in( 'uploads/2026/09/a.jpg' ) ); $this->assertFalse( $s->path_in( 'uploads/2025/a.jpg' ) );
	}
	public function test_only_narrows_paths_and_tables() {
		$s = $this->s( [ 'only' => 'themes', 'paths' => 'uploads/*' ] );
		$this->assertFalse( $s->path_in( 'uploads/a.jpg' ), 'paths cannot widen beyond --only' );
		$this->assertFalse( $s->path_in( 'themes/a/x' ), 'and themes are narrowed by --paths' );
	}
	public function test_unknown_only_throws() {
		$this->expectException( InvalidArgumentException::class );
		$this->s( [ 'only' => 'media' ] );
	}
	public function test_label_and_round_trip() {
		$s = $this->s( [ 'only' => 'themes', 'paths' => 'themes/mk/' ] );
		$this->assertSame( 'themes, paths themes/mk/', $s->label() );
		$r = IXES_Scope::from_array( $s->to_array(), 'wp_' );
		$this->assertSame( $s->to_array(), $r->to_array() );
		$this->assertSame( 'tables wp_posts', $this->s( [ 'tables' => 'wp_posts' ] )->label() );
	}
	public function test_family_warning() {
		$s = $this->s( [ 'tables' => 'posts' ] );
		$w = $s->family_warnings( [ 'wp_posts' ] );
		$this->assertCount( 1, $w );
		$this->assertStringContainsString( 'wp_posts selected without wp_postmeta', $w[0] );
		$this->assertSame( [], $this->s( [ 'tables' => 'posts,postmeta' ] )->family_warnings( [ 'wp_posts', 'wp_postmeta' ] ) );
		$this->assertSame( [], $this->s( [] )->family_warnings( [ 'wp_posts' ] ), 'no warning without --tables' );
	}
}
