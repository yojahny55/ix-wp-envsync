<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/CursorProgressTest.php';
/** The hub's pk and the remote's schema can drift apart: a binary pk found only on the hub must not cut the local hashes short. */
class SchemaDriftTest extends TestCase {
	public function test_local_binary_pk_with_keyless_remote_still_sees_every_local_row() {
		$GLOBALS['ixes_test_storage'] = sys_get_temp_dir() . '/ixes-drift-' . uniqid(); mkdir( $GLOBALS['ixes_test_storage'], 0777, true );
		$hub = new BinCellsWpdb(); $remote = new BinCellsWpdb();
		$hub->table( 'wp_filemods', [ 'md5', 'path' ], [ 'md5' ] );   // local: binary pk
		$remote->table( 'wp_filemods', [ 'md5', 'path' ], [] );       // remote: no pk (schema drift)
		for ( $i = 0; $i < 6000; $i++ ) { $r = [ 'md5' => "\xff" . pack( 'N', $i ), 'path' => "f{$i}.php" ]; $hub->insert( 'wp_filemods', $r ); $remote->insert( 'wp_filemods', $r ); }
		$hub->insert( 'wp_filemods', [ 'md5' => "\xff\xff\xff\xff\xff", 'path' => 'new-local.php' ] ); // new local row, sorts last
		$GLOBALS['wpdb'] = $hub;
		$c = new CursorRemote( [ 'name' => 'p', 'url' => 'https://p.test', 'token' => str_repeat( 'a', 64 ) ] );
		$c->remote = $remote; $c->hub = $hub; $c->old = true;
		$c->tables = [ [ 'name' => 'wp_filemods', 'pk' => null, 'rows' => 6000 ] ];
		$plan = IXES_Planner::build( [ 'name' => 'p', 'url' => 'https://p.test', 'extra_replace' => [], 'excludes' => [] ], $c, IXES_Scope::from_array( [ 'only' => [ 'db' ] ], 'wp_' ) );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$this->assertCount( 1, $plan['tables']['wp_filemods']['set_insert'], 'the new local row must be pushed' );
	}
}
