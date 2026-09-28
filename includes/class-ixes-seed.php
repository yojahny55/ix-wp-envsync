<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Seeds a pull's file transfer from wordpress.org instead of the (often much slower) remote.
 * A plugin or theme whose files are queued for transfer, at a version the remote reports, is
 * fetched as a zip from downloads.wordpress.org, extracted to a temp dir, and each of ITS files
 * is re-hashed and compared against the remote's own hash. Only an exact match is used; anything
 * else keeps coming from the remote, so the result is byte-identical either way. wordpress.org is
 * only ever a faster source, never a second source of truth.
 */
class IXES_Seed {
	const DOWNLOADS   = 'https://downloads.wordpress.org';
	const PLUGIN_API  = 'https://api.wordpress.org/plugins/info/1.0';
	const THEME_API   = 'https://api.wordpress.org/themes/info/1.0';

	public static function enabled( array $opts ) {
		if ( ! empty( $opts['no_seed'] ) ) return false;
		if ( defined( 'ENVSYNC_NO_SEED' ) && ENVSYNC_NO_SEED ) return false;
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Plugins/themes with files queued for transfer, at a version the remote reports.
	 * Single-file plugins (no slash after 'plugins/') are skipped: wordpress.org packages them
	 * differently and there is no folder of files worth seeding.
	 * @return array key (type/slug) => [ type, slug, version, paths => [ rel, ... ] ]
	 */
	public static function candidates( array $transfer, array $inventory ) {
		$out = [];
		foreach ( $transfer as $rel ) {
			$p = explode( '/', $rel, 3 );
			if ( count( $p ) < 3 || ( $p[0] !== 'plugins' && $p[0] !== 'themes' ) ) continue;
			$type = $p[0]; $slug = $p[1];
			$ver = $inventory[ $type ][ $slug ] ?? null;
			if ( ! $ver || $ver === '?' ) continue;
			$key = "{$type}/{$slug}";
			if ( ! isset( $out[ $key ] ) ) $out[ $key ] = [ 'type' => $type, 'slug' => $slug, 'version' => $ver, 'paths' => [] ];
			$out[ $key ]['paths'][] = $rel;
		}
		return $out;
	}

	/**
	 * Downloads, extracts and verifies every candidate, moving matching files into place only when
	 * $write is true. IXES_Pull::plan() always calls this with $write = false (verify only, so a plan
	 * -- including a dry run, or one the user declines -- never touches disk); IXES_Pull::apply_seed()
	 * calls it again with $write = true once the pull is confirmed. $fetch( $url ) returns a
	 * wp_remote_get()-shaped array or WP_Error.
	 * @return array [ matched => [ rel => true ], bytes => int ]
	 */
	public static function run( array $candidates, array $remote_hashes, $algo, callable $fetch, $write ) {
		$matched = []; $bytes = 0;
		foreach ( $candidates as $cand ) {
			$extracted = null;
			try {
				$extracted = self::fetch_and_extract( $cand['type'], $cand['slug'], $cand['version'], $fetch );
				if ( $extracted === null ) continue;
				$prefix = strlen( $cand['type'] ) + 1 + strlen( $cand['slug'] ) + 1;
				foreach ( $cand['paths'] as $rel ) {
					$src = $extracted['root'] . '/' . substr( $rel, $prefix );
					if ( ! is_file( $src ) ) continue;
					if ( ! isset( $remote_hashes[ $rel ] ) || IXES_Hasher::hash_file( $src, $algo ) !== $remote_hashes[ $rel ] ) continue;
					if ( $write && ! IXES_Transfer::seed_file( $rel, $src ) ) continue;
					$matched[ $rel ] = true;
					$bytes += (int) @filesize( $src );
				}
			} catch ( \Throwable $e ) {
				// corrupt zip, disk full, etc: never fail the pull over a shortcut
			} finally {
				// the whole temp tree, not just the detected root: a zip with extra top-level entries
				// alongside the plugin/theme folder must never leave those behind under sys_get_temp_dir()
				if ( $extracted !== null ) self::rrmdir( $extracted['tmp'] );
			}
		}
		return [ 'matched' => $matched, 'bytes' => $bytes ];
	}

	/** [ tmp (the whole extraction dir, always removed by the caller), root (the verified plugin/theme folder within it) ], or null to fall back to the remote. */
	private static function fetch_and_extract( $type, $slug, $version, callable $fetch ) {
		// both come from the remote's own report; belt-and-braces before they reach a URL and a filesystem path
		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) || ! preg_match( '/^[A-Za-z0-9.\-+]+$/', $version ) ) return null;
		$kind = $type === 'themes' ? 'theme' : 'plugin';
		$body = self::download( sprintf( '%s/%s/%s.%s.zip', self::DOWNLOADS, $kind, $slug, $version ), $fetch );
		if ( $body === null ) {
			// the versioned archive is missing (some plugins keep only the latest on wordpress.org);
			// the unversioned zip is always latest, so it only helps when that happens to be this version
			$latest = self::latest_version( $type, $slug, $fetch );
			if ( $latest === null || $latest !== $version ) return null;
			$body = self::download( sprintf( '%s/%s/%s.zip', self::DOWNLOADS, $kind, $slug ), $fetch );
			if ( $body === null ) return null;
		}
		$tmp_zip = tempnam( sys_get_temp_dir(), 'ixes-seed-' );
		if ( $tmp_zip === false || file_put_contents( $tmp_zip, $body ) === false ) return null;
		$dir = sys_get_temp_dir() . '/ixes-seed-' . $slug . '-' . uniqid( '', true );
		$za = new ZipArchive();
		if ( $za->open( $tmp_zip ) !== true ) { @unlink( $tmp_zip ); return null; }
		$ok = $za->extractTo( $dir );
		$za->close();
		@unlink( $tmp_zip );
		if ( ! $ok ) { self::rrmdir( $dir ); return null; }
		// wordpress.org packages extract to one top-level folder, normally named after the slug
		$root = is_dir( $dir . '/' . $slug ) ? $dir . '/' . $slug : self::sole_subdir( $dir );
		if ( $root === null ) { self::rrmdir( $dir ); return null; }
		return [ 'tmp' => $dir, 'root' => $root ];
	}

	private static function sole_subdir( $dir ) {
		$entries = array_values( array_diff( (array) @scandir( $dir ), [ '.', '..' ] ) );
		return count( $entries ) === 1 && is_dir( $dir . '/' . $entries[0] ) ? $dir . '/' . $entries[0] : null;
	}

	/** The zip's bytes, or null (404, network error, anything but 200): the caller falls back to the remote. */
	private static function download( $url, callable $fetch ) {
		$r = $fetch( $url );
		if ( is_wp_error( $r ) ) return null;
		if ( (int) ( $r['response']['code'] ?? 0 ) !== 200 ) return null;
		$body = $r['body'] ?? '';
		return $body === '' ? null : (string) $body;
	}

	private static function latest_version( $type, $slug, callable $fetch ) {
		$url = ( $type === 'themes' ? self::THEME_API : self::PLUGIN_API ) . "/{$slug}.json";
		$r = $fetch( $url );
		if ( is_wp_error( $r ) || (int) ( $r['response']['code'] ?? 0 ) !== 200 ) return null;
		$d = json_decode( (string) ( $r['body'] ?? '' ), true );
		return is_array( $d ) && isset( $d['version'] ) ? (string) $d['version'] : null;
	}

	private static function rrmdir( $dir ) {
		if ( ! $dir || ! is_dir( $dir ) ) return;
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
		@rmdir( $dir );
	}
}
