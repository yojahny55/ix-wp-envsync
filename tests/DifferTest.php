<?php
use PHPUnit\Framework\TestCase;

class DifferTest extends TestCase {
	private function d( $b, $l, $r ) { return IXES_Differ::diff( $b, $l, $r ); }

	public function test_all_equal_is_untouched() {
		$out = $this->d( [ 1 => 'a' ], [ 1 => 'a' ], [ 1 => 'a' ] );
		$this->assertSame( [], $out['push'] ); $this->assertSame( [], $out['kept'] );
	}
	public function test_local_changed_remote_same_is_push() {
		$out = $this->d( [ 1 => 'a' ], [ 1 => 'b' ], [ 1 => 'a' ] );
		$this->assertSame( [ 1 ], $out['push'] );
	}
	public function test_remote_changed_local_same_is_kept() {
		$out = $this->d( [ 1 => 'a' ], [ 1 => 'a' ], [ 1 => 'c' ] );
		$this->assertSame( [ 1 ], $out['kept'] ); $this->assertSame( [], $out['push'] ); $this->assertSame( [], $out['conflict'] );
	}
	public function test_both_changed_differently_is_conflict_prod_wins() {
		$out = $this->d( [ 1 => 'a' ], [ 1 => 'b' ], [ 1 => 'c' ] );
		$this->assertSame( [ 1 ], $out['conflict'] ); $this->assertSame( [ 1 ], $out['kept'] ); $this->assertSame( [], $out['push'] );
	}
	public function test_both_changed_same_is_untouched() {
		$out = $this->d( [ 1 => 'a' ], [ 1 => 'b' ], [ 1 => 'b' ] );
		$this->assertSame( [], $out['push'] ); $this->assertSame( [], $out['conflict'] );
	}
	public function test_local_new_is_insert() {
		$out = $this->d( [], [ 5 => 'x' ], [] );
		$this->assertSame( [ 5 ], $out['insert'] );
	}
	public function test_remote_new_is_kept() {
		$out = $this->d( [], [], [ 9 => 'x' ] );
		$this->assertSame( [ 9 ], $out['kept'] ); $this->assertSame( [], $out['delete'] );
	}
	public function test_local_deleted_remote_same_is_delete() {
		$out = $this->d( [ 1 => 'a' ], [], [ 1 => 'a' ] );
		$this->assertSame( [ 1 ], $out['delete'] );
	}
	public function test_local_deleted_remote_changed_is_kept_conflict() {
		$out = $this->d( [ 1 => 'a' ], [], [ 1 => 'z' ] );
		$this->assertSame( [], $out['delete'] ); $this->assertSame( [ 1 ], $out['conflict'] );
	}
	public function test_local_new_collides_with_remote_new_is_conflict() {
		$out = $this->d( [], [ 7 => 'l' ], [ 7 => 'r' ] );
		$this->assertSame( [ 7 ], $out['conflict'] ); $this->assertSame( [], $out['insert'] );
	}
	public function test_remote_deleted_local_changed_is_kept() {
		// prod deleted it; prod wins, do not resurrect
		$out = $this->d( [ 1 => 'a' ], [ 1 => 'b' ], [] );
		$this->assertSame( [], $out['insert'] ); $this->assertSame( [ 1 ], $out['conflict'] );
	}
	public function test_set_diff_inserts_only_new() {
		$out = IXES_Differ::diff_set( [ 'h1', 'h2', 'h3' ], [ 'h1' ] );
		$this->assertSame( [ 'h2', 'h3' ], $out['insert'] );
	}
	public function test_active_plugins_merge() {
		$base   = [ 'a/a.php', 'b/b.php' ];
		$local  = [ 'a/a.php', 'c/c.php' ];          // deactivated b, activated c
		$remote = [ 'a/a.php', 'b/b.php', 'd/d.php' ]; // prod activated d meanwhile
		$this->assertSame( [ 'a/a.php', 'd/d.php', 'c/c.php' ], IXES_Differ::merge_active_plugins( $base, $local, $remote ) );
	}
	public function test_active_plugins_no_baseline_keeps_remote_plus_local() {
		$this->assertSame( [ 'x/x.php', 'y/y.php' ], IXES_Differ::merge_active_plugins( [], [ 'y/y.php' ], [ 'x/x.php' ] ) );
	}
	public function test_mirror_deletes_what_only_the_remote_has() {
		$d = IXES_Differ::mirror( $this->d( [], [ 1 => 'a', 2 => 'b' ], [ 2 => 'x', 9 => 'z' ] ), [ 1 => 'a', 2 => 'b' ], [ 2 => 'x', 9 => 'z' ] );
		$this->assertSame( [ 9 ], $d['delete'] ); $this->assertSame( [ 2 ], $d['kept'] ); $this->assertSame( [ 1 ], $d['insert'] ); $this->assertSame( [ 2 ], $d['conflict'] );
	}
	public function test_mirror_without_remote_only_items_changes_nothing() {
		$in = $this->d( [], [ 1 => 'a' ], [ 1 => 'b' ] );
		$this->assertSame( $in, IXES_Differ::mirror( $in, [ 1 => 'a' ], [ 1 => 'b' ] ) );
	}
}
