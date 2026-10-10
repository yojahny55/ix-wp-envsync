<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Page caches write whole pages, with the URLs and asset versions of the moment, to folders under wp-content.
 * Those folders never sync (IXES_Env::default_excludes()), and after a pull the local copies hold pages of the
 * site as it was before, so they are emptied: the cache plugin rebuilds them on the next page view.
 */
class IXES_Caches {
	/** wp-content folders that hold only generated pages and assets. */
	const DIRS = [ 'cache/', 'wp-cloudflare-super-page-cache/', 'et-cache/', 'litespeed/' ];

	/** @return array{purged: string[], left: int} the folders emptied, and how many entries could not be deleted */
	public static function purge( $root = null ) {
		$root = untrailingslashit( $root === null ? WP_CONTENT_DIR : $root );
		$out = [ 'purged' => [], 'left' => 0 ];
		foreach ( self::DIRS as $d ) {
			$p = $root . '/' . rtrim( $d, '/' );
			// a symlinked cache folder may point anywhere: leave it alone
			if ( ! is_dir( $p ) || is_link( $p ) ) continue;
			$n = self::empty_dir( $p );
			if ( $n['deleted'] ) $out['purged'][] = $d;
			$out['left'] += $n['left'];
		}
		return $out;
	}

	/** Deletes everything under $dir, keeping $dir. @return array{deleted: int, left: int} entries removed and kept */
	private static function empty_dir( $dir ) {
		$n = [ 'deleted' => 0, 'left' => 0 ];
		// unreadable (another user's 0700 folder): count it, never walk it as if it were empty
		$names = @scandir( $dir );
		if ( $names === false ) { $n['left']++; return $n; }
		foreach ( $names as $name ) {
			if ( $name === '.' || $name === '..' ) continue;
			$p = $dir . '/' . $name;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				$in = self::empty_dir( $p );
				$n['deleted'] += $in['deleted']; $n['left'] += $in['left'];
				if ( ! $in['left'] && @rmdir( $p ) ) $n['deleted']++; else $n['left']++;
			} elseif ( @unlink( $p ) ) {
				$n['deleted']++;
			} else {
				$n['left']++;
			}
		}
		return $n;
	}

	/** Lines for the CLI output; none when there was nothing to empty. */
	public static function lines( array $r ) {
		$out = [];
		if ( $r['purged'] ) $out[] = 'page cache emptied: ' . implode( ', ', $r['purged'] );
		if ( $r['left'] ) $out[] = "warning: {$r['left']} cached file(s) or folder(s) could not be deleted (check ownership under wp-content)";
		return $out;
	}
}
