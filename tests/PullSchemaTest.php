<?php
use PHPUnit\Framework\TestCase;

/**
 * Minimal $wpdb double for the handful of statements IXES_Transfer's schema helpers issue
 * (SHOW TABLES LIKE, SHOW COLUMNS FROM, SHOW CREATE TABLE, CREATE TABLE, CREATE TABLE ... LIKE ..., ALTER TABLE
 * ... ADD COLUMN). Tables are held as column-name => definition-line, the same shape column_defs_from_create()
 * produces, so a fake CREATE TABLE can be rebuilt from them at any point.
 */
class FakeSchemaWpdb {
	public $prefix = 'wp_';
	public $last_error = '';
	public $fail_contains = null; // a query() containing this string fails once
	private $t = []; // name => [ column => def line ]

	public function seed( $name, array $columns ) { $this->t[ $name ] = $columns; }
	public function has( $name ) { return isset( $this->t[ $name ] ); }
	public function columns_of( $name ) { return $this->t[ $name ] ?? []; }

	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) $args = $args[0];
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function () use ( &$i, $args ) { return "'" . (string) $args[ $i++ ] . "'"; }, $query );
	}
	private function create_of( $name ) {
		$lines = array_values( $this->t[ $name ] );
		return "CREATE TABLE `{$name}` (\n  " . implode( ",\n  ", $lines ) . "\n) ENGINE=InnoDB";
	}
	public function get_col( $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $query, $m ) ) {
			$pat = str_replace( [ '\\_', '\\%' ], [ '_', '%' ], $m[1] );
			$prefix = rtrim( $pat, '%' );
			return array_values( array_filter( array_keys( $this->t ), function ( $n ) use ( $prefix ) { return strpos( $n, $prefix ) === 0; } ) );
		}
		if ( preg_match( '/SHOW COLUMNS FROM `([^`]+)`/', $query, $m ) ) return array_keys( $this->t[ $m[1] ] ?? [] );
		return [];
	}
	public function get_var( $query ) { $r = $this->get_col( $query ); return $r ? $r[0] : null; }
	public function get_row( $query, $type = null ) {
		if ( preg_match( '/SHOW CREATE TABLE `([^`]+)`/', $query, $m ) && isset( $this->t[ $m[1] ] ) ) return [ $m[1], $this->create_of( $m[1] ) ];
		return null;
	}
	public function query( $sql ) {
		if ( $this->fail_contains !== null && strpos( $sql, $this->fail_contains ) !== false ) { $this->fail_contains = null; $this->last_error = 'fake failure'; return false; }
		if ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`/', $sql, $m ) ) { unset( $this->t[ $m[1] ] ); return true; }
		if ( preg_match( '/^CREATE TABLE `([^`]+)` LIKE `([^`]+)`/', $sql, $m ) ) { $this->t[ $m[1] ] = $this->t[ $m[2] ] ?? []; return true; }
		if ( preg_match( '/^ALTER TABLE `([^`]+)` ADD COLUMN (.+)$/', $sql, $m ) ) {
			foreach ( IXES_Transfer::column_defs_from_create( $m[2] ) as $col => $def ) $this->t[ $m[1] ][ $col ] = $def;
			return true;
		}
		if ( preg_match( '/^CREATE TABLE `([^`]+)` \(/', $sql ) ) {
			$defs = IXES_Transfer::column_defs_from_create( $sql );
			$name = null; preg_match( '/^CREATE TABLE `([^`]+)`/', $sql, $mm ); $name = $mm[1];
			$this->t[ $name ] = $defs;
			return true;
		}
		return true;
	}
}

class FakeSchemaClient extends IXES_Client {
	public $schema_response;
	public function post( $route, $body, $opts = [] ) { return $route === '/schema' ? $this->schema_response : new WP_Error( 'unscripted', $route ); }
}

class PullSchemaTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new FakeSchemaWpdb();
		// IXES_Transfer caches columns per table name for the life of the process; a fresh fake per test needs a fresh cache too
		$prop = new ReflectionProperty( 'IXES_Transfer', 'local_columns' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}
	protected function tearDown(): void { unset( $GLOBALS['wpdb'] ); }

	private function client() { return new FakeSchemaClient( [ 'name' => 'prod', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] ); }

	public function test_column_defs_from_create_skips_keys_and_constraints() {
		$sql = "CREATE TABLE `wp_terms` (\n  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(200) NOT NULL DEFAULT '',\n  `term_order` bigint(20) NOT NULL DEFAULT 0,\n  PRIMARY KEY (`term_id`),\n  KEY `name` (`name`(191))\n) ENGINE=InnoDB";
		$defs = IXES_Transfer::column_defs_from_create( $sql );
		$this->assertSame( [ 'term_id', 'name', 'term_order' ], array_keys( $defs ) );
		$this->assertSame( '`term_order` bigint(20) NOT NULL DEFAULT 0', $defs['term_order'] );
	}

	public function test_plan_lists_a_new_table_and_a_new_column() {
		$GLOBALS['wpdb']->seed( 'wp_terms', [
			'term_id' => '`term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'name'    => "`name` varchar(200) NOT NULL DEFAULT ''",
		] );
		// wp_myplugin_log does not exist locally at all
		$tables_in_scope = [
			[ 'name' => 'wp_terms', 'pk' => 'term_id', 'rows' => 5, 'columns' => [ 'term_id', 'name', 'term_order' ] ],
			[ 'name' => 'wp_myplugin_log', 'pk' => 'id', 'rows' => 3, 'columns' => [ 'id', 'msg' ] ],
		];
		$c = $this->client();
		$c->schema_response = [ 'tables' => [
			'wp_terms'        => "CREATE TABLE `wp_terms` (\n  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `name` varchar(200) NOT NULL DEFAULT '',\n  `term_order` bigint(20) NOT NULL DEFAULT 0,\n  PRIMARY KEY (`term_id`)\n) ENGINE=InnoDB",
			'wp_myplugin_log' => "CREATE TABLE `wp_myplugin_log` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  `msg` text NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB",
		] ];
		list( $new_tables, $schema_changes, $warn ) = IXES_Pull::plan_schema( $c, [ 'caps' => [ 'schema' ] ], $tables_in_scope );
		$this->assertSame( [ 'wp_myplugin_log' ], array_keys( $new_tables ) );
		$this->assertStringContainsString( 'CREATE TABLE `wp_myplugin_log`', $new_tables['wp_myplugin_log'] );
		$this->assertSame( [ 'wp_terms' ], array_keys( $schema_changes ) );
		$this->assertSame( '`term_order` bigint(20) NOT NULL DEFAULT 0', $schema_changes['wp_terms']['term_order'] );
		$this->assertSame( [], $warn );
	}

	public function test_plan_warns_about_a_local_only_column_but_leaves_it() {
		$GLOBALS['wpdb']->seed( 'wp_terms', [
			'term_id' => '`term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'custom'  => '`custom` int NOT NULL DEFAULT 0',
		] );
		$tables_in_scope = [ [ 'name' => 'wp_terms', 'pk' => 'term_id', 'rows' => 1, 'columns' => [ 'term_id' ] ] ];
		list( $new_tables, $schema_changes, $warn ) = IXES_Pull::plan_schema( $this->client(), [ 'caps' => [ 'schema' ] ], $tables_in_scope );
		$this->assertSame( [], $new_tables ); $this->assertSame( [], $schema_changes );
		$this->assertStringContainsString( 'wp_terms: local column(s) custom not on the remote', $warn[0] );
	}

	public function test_old_remote_without_the_schema_cap_is_left_exactly_as_before() {
		// no 'schema' cap: a table this side lacks is neither created nor even looked up (no wpdb call at all)
		$tables_in_scope = [ [ 'name' => 'wp_myplugin_log', 'pk' => 'id', 'rows' => 3 ] ];
		$this->assertSame( [ [], [], [] ], IXES_Pull::plan_schema( $this->client(), [ 'caps' => [] ], $tables_in_scope ) );
	}

	public function test_create_missing_table_creates_and_refuses() {
		$sql = "CREATE TABLE `wp_myplugin_log` (\n  `id` bigint(20) NOT NULL AUTO_INCREMENT,\n  `msg` text NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
		$this->assertTrue( IXES_Transfer::create_missing_table( 'wp_myplugin_log', $sql ) );
		$this->assertTrue( $GLOBALS['wpdb']->has( 'wp_myplugin_log' ) );
		$this->assertSame( [ 'id', 'msg' ], array_keys( $GLOBALS['wpdb']->columns_of( 'wp_myplugin_log' ) ) );
		// already there: refused, not re-created
		$r = IXES_Transfer::create_missing_table( 'wp_myplugin_log', $sql );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'table already exists', $r->get_error_message() );
	}

	public function test_add_missing_column_alters_and_refuses_a_repeat() {
		$GLOBALS['wpdb']->seed( 'wp_terms', [ 'term_id' => '`term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT' ] );
		$this->assertTrue( IXES_Transfer::add_missing_column( 'wp_terms', 'term_order', '`term_order` bigint(20) NOT NULL DEFAULT 0' ) );
		$this->assertArrayHasKey( 'term_order', $GLOBALS['wpdb']->columns_of( 'wp_terms' ) );
		$r = IXES_Transfer::add_missing_column( 'wp_terms', 'term_order', '`term_order` bigint(20) NOT NULL DEFAULT 0' );
		$this->assertInstanceOf( 'WP_Error', $r );
		$this->assertSame( 'bad_column', $r->get_error_code() );
	}

	public function test_reconcile_tmp_brings_a_stale_resume_copy_up_to_date() {
		$GLOBALS['wpdb']->seed( 'wp_terms', [
			'term_id'    => '`term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'name'       => "`name` varchar(200) NOT NULL DEFAULT ''",
			'term_order' => '`term_order` bigint(20) NOT NULL DEFAULT 0', // added to the real table after the tmp copy was made
		] );
		$GLOBALS['wpdb']->seed( 'wp_ixes_tmp_terms', [
			'term_id' => '`term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'name'    => "`name` varchar(200) NOT NULL DEFAULT ''",
		] );
		$this->assertTrue( IXES_Transfer::reconcile_tmp( 'wp_terms' ) );
		$this->assertArrayHasKey( 'term_order', $GLOBALS['wpdb']->columns_of( 'wp_ixes_tmp_terms' ) );
		$this->assertSame( '`term_order` bigint(20) NOT NULL DEFAULT 0', $GLOBALS['wpdb']->columns_of( 'wp_ixes_tmp_terms' )['term_order'] );
	}

	public function test_reconcile_tmp_is_a_no_op_when_nothing_was_left_to_resume() {
		$GLOBALS['wpdb']->seed( 'wp_terms', [ 'term_id' => '`term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT' ] );
		$this->assertTrue( IXES_Transfer::reconcile_tmp( 'wp_terms' ), 'no wp_ixes_tmp_terms table: nothing to do' );
	}
}
