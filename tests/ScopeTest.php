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

	public function test_env_default_applies_only_when_no_scope_flag_is_given() {
		$env = [ 'name' => 'staging', 'default_only' => 'db,uploads' ];
		$r = IXES_Scope::with_default( [], $env );
		$this->assertSame( 'db,uploads', $r['assoc']['only'] );
		$this->assertStringContainsString( 'default for staging', $r['note'] );
		$this->assertStringContainsString( '--only=all', $r['note'] );
		$this->assertSame( [ 'db', 'uploads' ], IXES_Scope::from_assoc( $r['assoc'], 'wp_' )->to_array()['only'] );

		foreach ( [ [ 'only' => 'themes' ], [ 'tables' => 'posts' ], [ 'paths' => 'themes/x/' ] ] as $explicit ) {
			$r = IXES_Scope::with_default( $explicit, $env );
			$this->assertSame( $explicit, $r['assoc'], 'an explicit flag wins' );
			$this->assertNull( $r['note'] );
		}
	}
	public function test_only_all_syncs_everything_even_with_a_default() {
		$r = IXES_Scope::with_default( [ 'only' => 'all' ], [ 'name' => 'staging', 'default_only' => 'db,uploads' ] );
		$this->assertArrayNotHasKey( 'only', $r['assoc'] );
		$this->assertTrue( IXES_Scope::from_assoc( $r['assoc'], 'wp_' )->is_full() );
		$r = IXES_Scope::with_default( [ 'only' => 'all', 'tables' => 'posts' ], [ 'name' => 'p' ] );
		$this->assertSame( [ 'tables' => 'posts' ], $r['assoc'] );
	}
	public function test_no_default_changes_nothing() {
		$this->assertSame( [ 'assoc' => [], 'note' => null ], IXES_Scope::with_default( [], [ 'name' => 'prod' ] ) );
		$this->assertSame( [ 'assoc' => [], 'note' => null ], IXES_Scope::with_default( [], [ 'name' => 'prod', 'default_only' => '' ] ) );
	}
	public function test_default_only_is_validated() {
		$this->assertSame( 'db,uploads', IXES_Scope::default_only( ' db, uploads ' ) );
		$this->assertSame( '', IXES_Scope::default_only( '' ) );
		$this->assertSame( '', IXES_Scope::default_only( 'all' ), 'all means no default' );
		$this->expectException( InvalidArgumentException::class );
		IXES_Scope::default_only( 'db,media' );
	}
	public function test_is_env_default_matches_the_default_only_in_any_order() {
		$env = [ 'default_only' => 'db,uploads' ];
		$this->assertTrue( IXES_Scope::from_assoc( [ 'only' => 'uploads,db' ], 'wp_' )->is_env_default( $env ) );
		$this->assertFalse( IXES_Scope::from_assoc( [ 'only' => 'db' ], 'wp_' )->is_env_default( $env ) );
		$this->assertFalse( IXES_Scope::from_assoc( [ 'only' => 'db,uploads', 'tables' => 'posts' ], 'wp_' )->is_env_default( $env ) );
		$this->assertFalse( IXES_Scope::from_assoc( [], 'wp_' )->is_env_default( $env ) );
		$this->assertFalse( IXES_Scope::from_assoc( [ 'only' => 'db' ], 'wp_' )->is_env_default( [] ) );
	}
	public function test_covers() {
		$base = IXES_Scope::from_array( [ 'only' => [ 'db', 'uploads' ] ], 'wp_' );
		$this->assertTrue( $base->covers( IXES_Scope::from_assoc( [ 'only' => 'db,uploads' ], 'wp_' ) ) );
		$this->assertTrue( $base->covers( IXES_Scope::from_assoc( [ 'tables' => 'posts' ], 'wp_' ) ) );
		$this->assertFalse( $base->covers( IXES_Scope::from_assoc( [ 'only' => 'themes' ], 'wp_' ) ) );
		$this->assertFalse( $base->covers( IXES_Scope::from_assoc( [ 'paths' => 'uploads/2026/' ], 'wp_' ) ) ); // inferred 'files' reaches beyond uploads
		$this->assertFalse( $base->covers( IXES_Scope::from_assoc( [], 'wp_' ) ) );
		$this->assertTrue( IXES_Scope::from_array( [ 'only' => [ 'files' ] ], 'wp_' )->covers( IXES_Scope::from_assoc( [ 'only' => 'plugins' ], 'wp_' ) ) );
		$this->assertTrue( IXES_Scope::from_array( [], 'wp_' )->covers( IXES_Scope::from_assoc( [], 'wp_' ) ) );
	}

	public function test_lists_plugins_only_when_the_whole_plugins_folder_is_in_scope() {
		$this->assertTrue( IXES_Scope::from_assoc( [], 'wp_' )->lists_plugins() );
		$this->assertTrue( IXES_Scope::from_assoc( [ 'only' => 'plugins' ], 'wp_' )->lists_plugins() );
		$this->assertTrue( IXES_Scope::from_assoc( [ 'only' => 'db,files' ], 'wp_' )->lists_plugins() );
		$this->assertFalse( IXES_Scope::from_assoc( [ 'only' => 'uploads' ], 'wp_' )->lists_plugins() );
		$this->assertFalse( IXES_Scope::from_assoc( [ 'only' => 'db' ], 'wp_' )->lists_plugins() );
		$this->assertFalse( IXES_Scope::from_assoc( [ 'paths' => 'plugins/akismet/' ], 'wp_' )->lists_plugins() );
	}
	public function test_exclude_tables_drops_matches_but_keeps_the_scope_full() {
		$s = $this->s( [ 'exclude-tables' => 'wp_wsal_occurrences, *_debug_events' ] );
		$this->assertFalse( $s->table_in( 'wp_wsal_occurrences' ) );
		$this->assertFalse( $s->table_in( 'wp_smtp_debug_events' ) );
		$this->assertTrue( $s->table_in( 'wp_posts' ) );
		$this->assertTrue( $s->is_full(), 'an exclude is not a narrower scope: a pull still records a full baseline' );
		$this->assertTrue( $s->path_in( 'uploads/x.jpg' ) );
		$this->assertSame( 'everything, not tables wp_wsal_occurrences,*_debug_events', $s->label() );
	}
	public function test_exclude_tables_matches_bare_names_and_narrows_tables() {
		$s = $this->s( [ 'tables' => 'posts,postmeta,wsal_*', 'exclude-tables' => 'wsal_*' ] );
		$this->assertTrue( $s->table_in( 'wp_posts' ) );
		$this->assertFalse( $s->table_in( 'wp_wsal_metadata' ) );
	}
	public function test_logs_preset_expands() {
		$s = $this->s( [ 'exclude-tables' => '@logs' ] );
		foreach ( [ 'wp_wsal_occurrences', 'wp_fluentsmtp_debug_events', 'wp_actionscheduler_logs', 'wp_redirection_404_logs', 'wp_x_audit_log', 'wp_mailpoet_log', 'wp_automation_run_logs' ] as $t ) {
			$this->assertFalse( $s->table_in( $t ), $t );
		}
		$this->assertTrue( $s->table_in( 'wp_actionscheduler_actions' ) );
		$this->assertStringContainsString( 'not tables @logs', $s->label() );
	}
	public function test_unknown_preset_is_refused() {
		$this->expectException( InvalidArgumentException::class );
		$this->s( [ 'exclude-tables' => '@nope' ] );
	}
	public function test_exclude_tables_round_trips_through_the_plan() {
		$a = $this->s( [ 'only' => 'db', 'exclude-tables' => '@logs' ] )->to_array();
		$this->assertFalse( IXES_Scope::from_array( $a, 'wp_' )->table_in( 'wp_mailpoet_log' ) );
		$this->assertSame( [ 'only' => [ 'db' ], 'tables' => [], 'paths' => [] ], $this->s( [ 'only' => 'db' ] )->to_array(), 'no key when nothing is excluded' );
	}
	public function test_family_warning_when_an_exclude_splits_a_family() {
		$s = $this->s( [ 'exclude-tables' => 'postmeta' ] );
		$this->assertNotEmpty( $s->family_warnings( [ 'wp_posts' ] ) );
	}
	public function test_env_table_excludes_always_apply_and_add_to_the_flag() {
		$env = [ 'name' => 'prod', 'exclude_tables' => [ '@logs' ] ];
		$this->assertSame( '@logs', IXES_Scope::with_default( [], $env )['assoc']['exclude-tables'] );
		$this->assertSame( '@logs,wp_x', IXES_Scope::with_default( [ 'exclude-tables' => 'wp_x', 'only' => 'all' ], $env )['assoc']['exclude-tables'] );
		$this->assertSame( '@logs', IXES_Scope::with_default( [ 'tables' => 'posts' ], $env )['assoc']['exclude-tables'] );
	}
	public function test_env_default_tables_apply_when_no_scope_flag_is_given() {
		$env = [ 'name' => 'prod', 'default_tables' => 'posts,postmeta' ];
		$r = IXES_Scope::with_default( [], $env );
		$this->assertSame( 'posts,postmeta', $r['assoc']['tables'] );
		$this->assertStringContainsString( 'scope: tables posts,postmeta (default for prod', $r['note'] );
		$this->assertArrayNotHasKey( 'tables', IXES_Scope::with_default( [ 'only' => 'all' ], $env )['assoc'] );
		$this->assertSame( [ 'only' => 'files' ], IXES_Scope::with_default( [ 'only' => 'files' ], $env )['assoc'] );
		$r = IXES_Scope::with_default( [], $env + [ 'default_only' => 'db,uploads' ] );
		$this->assertSame( [ 'db', 'uploads' ], IXES_Scope::from_assoc( $r['assoc'], 'wp_' )->to_array()['only'] );
		$this->assertStringContainsString( 'scope: db,uploads, tables posts,postmeta (default', $r['note'] );
		$this->assertFalse( IXES_Scope::from_assoc( $r['assoc'], 'wp_' )->is_env_default( $env + [ 'default_only' => 'db,uploads' ] ), 'a table default never records a baseline' );
	}
	public function test_default_tables_and_table_excludes_are_validated() {
		$this->assertSame( 'posts,postmeta', IXES_Scope::default_tables( ' posts, postmeta ' ) );
		$this->assertSame( [ '@logs', 'wp_x' ], IXES_Scope::table_excludes( '@logs, wp_x' ) );
		$this->expectException( InvalidArgumentException::class );
		IXES_Scope::table_excludes( '@nope' );
	}
	public function test_resume_accepts_no_flags_and_the_stored_scope_in_any_order() {
		$stored = $this->s( [ 'tables' => 'posts,postmeta' ] )->to_array();
		$this->assertNull( IXES_Scope::resume_refusal( [], $stored, 'wp_' ) );
		$this->assertNull( IXES_Scope::resume_refusal( [ 'tables' => 'postmeta, posts' ], $stored, 'wp_' ) );
		$this->assertNull( IXES_Scope::resume_refusal( [ 'only' => 'db', 'tables' => 'posts,postmeta' ], $stored, 'wp_' ) );
	}
	public function test_resume_refuses_a_different_scope_and_names_both() {
		$stored = $this->s( [ 'tables' => 'posts' ] )->to_array();
		$why = IXES_Scope::resume_refusal( [ 'only' => 'db,uploads' ], $stored, 'wp_' );
		$this->assertStringContainsString( 'tables posts', $why );
		$this->assertStringContainsString( 'db,uploads', $why );
	}
	public function test_resume_treats_only_all_as_everything() {
		$this->assertNull( IXES_Scope::resume_refusal( [ 'only' => 'all' ], [], 'wp_' ) );
		$this->assertNotNull( IXES_Scope::resume_refusal( [ 'only' => 'all' ], $this->s( [ 'only' => 'db' ] )->to_array(), 'wp_' ) );
	}
	public function test_flags_rebuild_the_command_line_for_a_scope() {
		$this->assertSame( '', $this->s( [] )->flags() );
		$this->assertSame( ' --tables=posts,postmeta', $this->s( [ 'tables' => 'posts,postmeta' ] )->flags() );
		$this->assertSame( ' --only=db,uploads', $this->s( [ 'only' => 'db,uploads' ] )->flags() );
		$this->assertSame( ' --only=themes --paths=themes/mk/', $this->s( [ 'only' => 'themes', 'paths' => 'themes/mk/' ] )->flags() );
	}
}
