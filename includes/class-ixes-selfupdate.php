<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * `wp envsync self-update`: the hub ships a zip of this plugin, the remote checks it and installs it with
 * Plugin_Upgrader, the hub then checks the site still answers and has the remote put the old folder back
 * (through rescue.php) when it does not. A sync never touches this plugin's own folder (IXES_Transfer::excluded_path()),
 * so this is the only way its code travels.
 *
 * Must load on its own: rescue.php runs restore() with only this class and IXES_Auth, because what it
 * recovers from is this plugin's new code failing.
 */
class IXES_Selfupdate {
	const NAME      = 'IX WP EnvSync';          // Plugin Name header a zip must carry
	const MAIN      = 'ix-wp-envsync.php';
	const SLUG      = 'ix-wp-envsync';          // folder name when the remote does not say
	const MAX_ZIP   = 33554432;                 // 32 MB: a release zip is well under 1 MB
	const MAX_FILES = 5000;
	const MAX_UNZIP = 134217728;                // inflated total: a zip bomb stops here
	const CHUNK_MAX = 8388608;                  // one inflated upload chunk (IXES_Chunker tops out at 4 MB)
	const OPTION    = 'ixes_self_update_off';   // set from Tools → EnvSync; ixes_* options never sync
	// what a hub's own folder carries that a release zip does not (.gitattributes export-ignore, plus dev leftovers)
	const BUILD_SKIP = [ 'composer.json', 'composer.lock', 'docs', 'phpcs.xml.dist', 'phpunit.xml', 'tests', 'vendor', 'node_modules' ];

	/** Tests only: the plugin folder, and a stand-in for Plugin_Upgrader ( $zip ) => true|WP_Error. */
	public static $folder = null;
	public static $installer = null;

	// ---------- shared ----------

	/** Off when wp-config.php defines ENVSYNC_DISABLE_SELF_UPDATE, or the Tools → EnvSync switch says so. */
	public static function enabled() {
		return self::allowed( defined( 'ENVSYNC_DISABLE_SELF_UPDATE' ) ? ENVSYNC_DISABLE_SELF_UPDATE : null, get_option( self::OPTION ) );
	}
	public static function allowed( $constant, $option ) { return ! $constant && ! $option; }
	public static function by_constant() { return defined( 'ENVSYNC_DISABLE_SELF_UPDATE' ) && ENVSYNC_DISABLE_SELF_UPDATE; }

	/** What /info advertises: a hub tells "cannot" (older remote) from "may not" (turned off) by these. */
	public static function caps() { return [ self::enabled() ? 'self_update' : 'self_update_off' ]; }

	public static function own_folder() {
		if ( self::$folder !== null ) return self::$folder;
		return defined( 'IXES_FILE' ) ? dirname( IXES_FILE ) : dirname( __DIR__ );
	}

	/** Plugin Name and Version from a main plugin file's text, read like get_file_data() does (first 8 KB). */
	public static function header_of( $php ) {
		$php = substr( (string) $php, 0, 8192 );
		$get = function ( $h ) use ( $php ) { return preg_match( '/^[ \t\/*#@]*' . preg_quote( $h, '/' ) . ':(.*)$/mi', $php, $m ) ? trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) ) : ''; };
		$const = preg_match( "/define\(\s*'IXES_VERSION',\s*'([^']*)'\s*\)/", $php, $m ) ? $m[1] : null;
		return [ 'name' => $get( 'Plugin Name' ), 'version' => $get( 'Version' ), 'constant' => $const ];
	}

	/**
	 * Everything a zip must pass before it goes anywhere near the plugins folder: one top folder ($top when given),
	 * no absolute, backslashed or ../ paths, no symlinks, our main file with our Plugin Name and a version, and
	 * every PHP file parsing on this PHP (a syntax error would take the site down on the next request).
	 * @return array{top:string,name:string,version:string,files:int,bytes:int}|WP_Error
	 */
	public static function inspect( $path, $top = null ) {
		$bad = function ( $msg ) { return new WP_Error( 'bad_zip', $msg, [ 'status' => 422 ] ); };
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'no_zip', 'PHP has no zip extension (ZipArchive) here', [ 'status' => 501 ] );
		if ( ! is_file( $path ) || filesize( $path ) > self::MAX_ZIP ) return $bad( 'missing, or larger than ' . ( self::MAX_ZIP >> 20 ) . ' MB' );
		$z = new ZipArchive();
		if ( $z->open( $path, ZipArchive::CHECKCONS ) !== true ) return $bad( 'not a readable zip' );
		$n = $z->numFiles;
		if ( $n < 1 || $n > self::MAX_FILES ) { $z->close(); return $bad( "{$n} entries" ); }
		$seen = null; $bytes = 0; $php = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$st = $z->statIndex( $i );
			$name = (string) ( $st['name'] ?? '' );
			$err = null;
			if ( $name === '' || strpos( $name, "\0" ) !== false || strpos( $name, '\\' ) !== false ) $err = 'an entry with a NUL byte or a backslash';
			elseif ( $name[0] === '/' || preg_match( '#^[A-Za-z]:#', $name ) ) $err = "absolute path {$name}";
			elseif ( in_array( '..', explode( '/', $name ), true ) ) $err = "path traversal in {$name}";
			elseif ( $z->getExternalAttributesIndex( $i, $os, $attr ) && $os === ZipArchive::OPSYS_UNIX && ( ( $attr >> 16 ) & 0170000 ) === 0120000 ) $err = "symlink {$name}";
			else {
				$parts = explode( '/', $name );
				if ( count( $parts ) < 2 ) $err = "{$name} sits outside a top folder";
				elseif ( $seen !== null && $parts[0] !== $seen ) $err = "more than one top folder ({$seen}/, {$parts[0]}/)";
				else $seen = $parts[0];
			}
			if ( $err ) { $z->close(); return $bad( $err ); }
			$bytes += (int) ( $st['size'] ?? 0 );
			if ( $bytes > self::MAX_UNZIP ) { $z->close(); return $bad( 'unpacks to more than ' . ( self::MAX_UNZIP >> 20 ) . ' MB' ); }
			if ( substr( $name, -4 ) === '.php' ) $php[ $name ] = $i;
		}
		if ( $top !== null && $seen !== $top ) { $z->close(); return $bad( "its top folder is {$seen}/, but the plugin lives in {$top}/ there" ); }
		$main = $z->getFromName( $seen . '/' . self::MAIN );
		$h = $main === false ? null : self::header_of( $main );
		$err = null;
		if ( $h === null ) $err = "no {$seen}/" . self::MAIN;
		elseif ( $h['name'] !== self::NAME ) $err = 'Plugin Name is "' . $h['name'] . '", not "' . self::NAME . '"';
		elseif ( ! preg_match( '/^\d+(\.\d+){1,3}([-+][0-9A-Za-z.-]+)?$/', $h['version'] ) ) $err = 'no valid Version header';
		elseif ( $h['constant'] !== null && $h['constant'] !== $h['version'] ) $err = "Version header says {$h['version']} but IXES_VERSION says {$h['constant']}";
		if ( ! $err ) {
			foreach ( $php as $name => $i ) {
				try { token_get_all( (string) $z->getFromIndex( $i ), TOKEN_PARSE ); }
				catch ( ParseError $e ) { $err = "syntax error in {$name} line {$e->getLine()} on PHP " . PHP_VERSION . ': ' . $e->getMessage(); break; }
			}
		}
		$z->close();
		if ( $err ) return $bad( $err );
		return [ 'top' => $seen, 'name' => $h['name'], 'version' => $h['version'], 'files' => $n, 'bytes' => $bytes ];
	}

	/** Why a zip holding $new must not replace $current, or null. */
	public static function version_refusal( $current, $new, $force ) {
		if ( $force || version_compare( (string) $new, (string) $current, '>' ) ) return null;
		return "the zip holds {$new} and the remote already runs {$current}";
	}

	// ---------- remote side ----------

	private static function off() {
		return new WP_Error( 'self_update_off', 'self-update is turned off on this site (' . ( self::by_constant() ? 'ENVSYNC_DISABLE_SELF_UPDATE' : 'Tools → EnvSync' ) . ')', [ 'status' => 403 ] );
	}
	private static function id( $id ) { return is_string( $id ) && preg_match( '/^[a-f0-9]{12,32}$/', $id ) ? $id : null; }
	private static function clean( $s ) { return substr( preg_replace( '/[^\x20-\x7E]/', '', (string) $s ), 0, 200 ); }

	/** <storage>/self-update, also when rescue.php did not load the main plugin file (and so ixes_storage_dir()). */
	public static function store() {
		if ( function_exists( 'ixes_storage_dir' ) ) $base = ixes_storage_dir();
		else {
			$s = get_option( 'ixes_storage_suffix' );
			if ( ! is_string( $s ) || ! preg_match( '/^[0-9a-f]{16}$/', $s ) ) return null;
			$base = WP_CONTENT_DIR . '/envsync-' . $s;
		}
		$d = $base . '/self-update';
		return wp_mkdir_p( $d ) ? $d : null;
	}

	/**
	 * One chunk of the upload, from /self-update/chunk: step fields (id, offset, final, sha256, enc) plus the raw body in 'bin'.
	 * A retried chunk rewrites from its offset, so a lost answer costs nothing. The last one checks the whole file's sha256.
	 */
	public static function receive( array $p ) {
		if ( ! self::enabled() ) return self::off();
		$id = self::id( $p['id'] ?? '' ); $store = self::store();
		if ( ! $id || ! $store ) return new WP_Error( 'bad_upload', 'bad upload id', [ 'status' => 400 ] );
		$data = (string) ( $p['bin'] ?? '' );
		if ( ( $p['enc'] ?? '' ) === 'deflate' ) {
			$data = function_exists( 'gzinflate' ) ? @gzinflate( $data, self::CHUNK_MAX ) : false;
			if ( $data === false ) return new WP_Error( 'bad_upload', 'chunk does not inflate', [ 'status' => 400 ] );
		}
		$off = (int) ( $p['offset'] ?? -1 );
		// 400, not 413: IXES_Chunker would halve and retry a 413 that no chunk size can fix
		if ( $off < 0 || $off + strlen( $data ) > self::MAX_ZIP ) return new WP_Error( 'bad_upload', 'offset out of range, or the zip exceeds ' . ( self::MAX_ZIP >> 20 ) . ' MB', [ 'status' => 400 ] );
		$f = "{$store}/incoming-{$id}.zip";
		if ( $off === 0 ) foreach ( (array) glob( "{$store}/incoming-*.zip" ) as $old ) @unlink( $old );
		clearstatcache( true, $f );
		$have = is_file( $f ) ? (int) filesize( $f ) : 0;
		if ( $off > $have ) return new WP_Error( 'bad_offset', "expected offset {$have} or lower", [ 'status' => 409 ] );
		$h = @fopen( $f, 'c+b' );
		if ( ! $h ) return new WP_Error( 'io', "cannot write {$f}", [ 'status' => 500 ] );
		$ok = ftruncate( $h, $off ) && fseek( $h, $off ) === 0 && fwrite( $h, $data ) === strlen( $data );
		fclose( $h );
		if ( ! $ok ) return new WP_Error( 'io', "cannot write {$f}", [ 'status' => 500 ] );
		if ( ! empty( $p['final'] ) && ! hash_equals( hash_file( 'sha256', $f ), strtolower( (string) ( $p['sha256'] ?? '' ) ) ) ) {
			@unlink( $f );
			return new WP_Error( 'bad_sha', 'the upload does not match its sha256', [ 'status' => 422 ] );
		}
		return [ 'ok' => true, 'size' => $off + strlen( $data ) ];
	}

	/** Why this site cannot install over its own folder right now, or null. */
	private static function refusal( $dir ) {
		$r = self::place_refusal( $dir );
		if ( $r || self::$installer ) return $r; // a test's $installer stands in for Plugin_Upgrader and its filesystem
		if ( ! function_exists( 'get_filesystem_method' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
		$m = get_filesystem_method( [], WP_PLUGIN_DIR );
		if ( $m !== 'direct' ) return new WP_Error( 'fs_method', "WordPress would write plugins here through '{$m}', which needs credentials a REST request cannot give. Let PHP write wp-content/plugins directly (FS_METHOD 'direct'), or upload the zip by hand", [ 'status' => 409 ] );
		return null;
	}

	/** Why nothing may be written over this folder at all (install and restore alike), or null. */
	private static function place_refusal( $dir ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) return new WP_Error( 'file_mods', 'DISALLOW_FILE_MODS is set on this site; change the plugin by hand', [ 'status' => 403 ] );
		// rescue.php boots through wp-settings.php, which defines it; the fallback is for a bootstrap that did not get that far
		$root = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/plugins' : '' );
		$plugins = $root !== '' ? realpath( $root ) : false;
		if ( ! $plugins || is_link( $dir ) || realpath( dirname( $dir ) ) !== $plugins ) {
			return new WP_Error( 'not_in_plugins', "EnvSync runs from {$dir}, not straight from the plugins folder (a symlinked checkout?); change it there by hand", [ 'status' => 409 ] );
		}
		return null;
	}

	/**
	 * /self-update/install: checks the uploaded zip again, keeps a copy of the current folder, installs over it and
	 * answers only once the new files are in place. The hub's health check decides whether it stays.
	 */
	public static function install( array $p ) {
		if ( ! self::enabled() ) return self::off();
		$id = self::id( $p['id'] ?? '' ); $store = self::store();
		if ( ! $id || ! $store ) return new WP_Error( 'bad_upload', 'bad upload id', [ 'status' => 400 ] );
		$zip = "{$store}/incoming-{$id}.zip";
		if ( ! is_file( $zip ) ) return new WP_Error( 'no_upload', 'no such upload; send it again', [ 'status' => 404 ] );
		$sha = hash_file( 'sha256', $zip );
		if ( ! hash_equals( $sha, strtolower( (string) ( $p['sha256'] ?? '' ) ) ) ) { @unlink( $zip ); return new WP_Error( 'bad_sha', 'the upload does not match its sha256', [ 'status' => 422 ] ); }
		// a push mid-way would finish on other code than it started with, and its maintenance 503s would skew the health check
		$l = IXES_Applier::lock_info();
		if ( $l ) return new WP_Error( 'locked', "a push (job {$l['job']}) holds the lock; let it finish, or run wp envsync unlock first", [ 'status' => 409 ] );
		$dir = self::own_folder();
		$meta = self::refusal( $dir ) ?: self::inspect( $zip, basename( $dir ) );
		if ( is_wp_error( $meta ) ) { @unlink( $zip ); return $meta; }
		$why = self::version_refusal( IXES_VERSION, $meta['version'], ! empty( $p['force'] ) );
		if ( $why ) { @unlink( $zip ); return new WP_Error( 'not_newer', $why, [ 'status' => 409 ] ); }
		$who = [ 'hub' => self::clean( $p['hub'] ?? '' ), 'by' => self::clean( $p['by'] ?? '' ), 'ip' => self::clean( $_SERVER['REMOTE_ADDR'] ?? '' ) ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- clean() keeps printable ASCII only; logged, never executed
		$b = self::backup( $dir, IXES_VERSION );
		if ( is_wp_error( $b ) ) { @unlink( $zip ); return $b; }
		$active = get_option( 'active_plugins', [] );
		$r = self::upgrade( $zip );
		@unlink( $zip );
		if ( is_wp_error( $r ) || ! is_file( $dir . '/' . self::MAIN ) ) {
			$back = self::restore( IXES_VERSION, $who, true );
			$msg = ( is_wp_error( $r ) ? $r->get_error_message() : 'the main plugin file is missing afterwards' ) . ( is_wp_error( $back ) ? '; putting the previous version back failed too: ' . $back->get_error_message() : '; the previous version is back' );
			self::log( [ 'event' => 'install_failed', 'from' => IXES_VERSION, 'to' => $meta['version'], 'sha256' => $sha, 'error' => $msg ] + $who );
			return new WP_Error( 'install_failed', 'install failed: ' . $msg, [ 'status' => 500 ] );
		}
		// install() never deactivates, but a plugin hooked on upgrader_process_complete might. activate_plugin() is no
		// way back: it would include the new main file into this process and fatal on its already declared functions.
		if ( get_option( 'active_plugins', [] ) !== $active ) update_option( 'active_plugins', $active );
		self::invalidate( $dir );
		self::log( [ 'event' => 'install', 'from' => IXES_VERSION, 'to' => $meta['version'], 'sha256' => $sha ] + $who );
		return [ 'ok' => true, 'from' => IXES_VERSION, 'to' => $meta['version'], 'sha256' => $sha ];
	}

	/** Core's Plugin_Upgrader over the existing folder: the upload-and-replace path of Plugins → Add New. */
	private static function upgrade( $zip ) {
		if ( self::$installer ) return call_user_func( self::$installer, $zip );
		foreach ( [ 'file', 'misc', 'plugin', 'class-wp-upgrader' ] as $f ) require_once ABSPATH . "wp-admin/includes/{$f}.php";
		$skin = new Automatic_Upgrader_Skin();
		$u = new Plugin_Upgrader( $skin );
		ob_start(); // a skin that echoes would corrupt the JSON answer
		$r = $u->install( $zip, [ 'overwrite_package' => true ] );
		ob_end_clean();
		if ( is_wp_error( $r ) ) return $r;
		if ( $r ) return true;
		$e = $skin->get_errors();
		if ( is_wp_error( $e ) && $e->has_errors() ) return $e;
		return new WP_Error( 'install_failed', implode( '; ', array_filter( array_map( 'strval', (array) $skin->get_upgrade_messages() ) ) ) ?: 'Plugin_Upgrader gave up' );
	}

	/** A copy of the plugin folder at <storage>/self-update/backup/<folder>/, replacing the last one only once complete. */
	private static function backup( $dir, $version ) {
		$store = self::store();
		$new = "{$store}/backup-new"; $bak = "{$store}/backup";
		if ( is_dir( $new ) ) self::rrmdir( $new );
		if ( ! self::copy_tree( $dir, $new . '/' . basename( $dir ) ) ) { if ( is_dir( $new ) ) self::rrmdir( $new ); return new WP_Error( 'backup_failed', "cannot copy {$dir} into {$store}; nothing was installed", [ 'status' => 500 ] ); }
		if ( is_dir( $bak ) ) self::rrmdir( $bak );
		if ( ! @rename( $new, $bak ) || ! file_put_contents( "{$store}/backup.json", wp_json_encode( [ 'version' => (string) $version, 'dir' => basename( $dir ), 'at' => time() ] ) ) ) {
			return new WP_Error( 'backup_failed', "cannot keep the backup in {$store}; nothing was installed", [ 'status' => 500 ] );
		}
		return true;
	}

	/** Version the kept backup holds, or null. */
	public static function backup_version() {
		$store = self::store();
		$m = $store ? json_decode( (string) @file_get_contents( "{$store}/backup.json" ), true ) : null;
		return is_array( $m ) && isset( $m['version'] ) ? (string) $m['version'] : null;
	}

	/**
	 * Put the kept folder back (rescue.php 'restore_self', or install() when core's installer failed: $internal).
	 * From rescue it obeys the same gates as an install, and $expect, the version the hub replaced, is required:
	 * a backup holding anything else is not the one this rollback is about. The copy is complete before the swap,
	 * so a failure halfway never leaves the site without rescue.php.
	 */
	public static function restore( $expect = null, array $who = [], $internal = false ) {
		$store = self::store();
		$dir = self::own_folder();
		if ( ! $internal ) {
			if ( ! self::enabled() ) return self::off();
			$r = self::place_refusal( $dir );
			if ( $r ) return $r;
			if ( (string) $expect === '' ) return new WP_Error( 'from_required', 'name the version to put back (from)', [ 'status' => 400 ] );
		}
		$src = "{$store}/backup/" . basename( $dir );
		$meta = $store ? json_decode( (string) @file_get_contents( "{$store}/backup.json" ), true ) : null;
		if ( ! is_array( $meta ) || ! is_file( $src . '/' . self::MAIN ) ) return new WP_Error( 'no_backup', 'no backup of the plugin folder here', [ 'status' => 404 ] );
		if ( (string) $expect !== '' && (string) $meta['version'] !== (string) $expect ) return new WP_Error( 'backup_mismatch', "the backup holds {$meta['version']}, not {$expect}; copy {$src} back by hand if you mean it", [ 'status' => 409 ] );
		$tmp = dirname( $dir ) . '/.ixes-restore'; // a dot folder: get_plugins() skips it while it exists
		if ( is_dir( $tmp ) ) self::rrmdir( $tmp );
		if ( ! self::copy_tree( $src, $tmp ) ) { if ( is_dir( $tmp ) ) self::rrmdir( $tmp ); return new WP_Error( 'restore_failed', "cannot copy {$src} next to {$dir}", [ 'status' => 500 ] ); }
		$aside = "{$store}/failed";
		if ( is_dir( $aside ) ) self::rrmdir( $aside );
		if ( is_dir( $dir ) && ! @rename( $dir, $aside ) ) self::rrmdir( $dir ); // another filesystem: no rename, so delete
		if ( ! @rename( $tmp, $dir ) ) return new WP_Error( 'restore_failed', "the previous version is in {$tmp}; rename it to {$dir} by hand", [ 'status' => 500 ] );
		self::invalidate( $dir );
		$base = basename( $dir ) . '/' . self::MAIN;
		$active = (array) get_option( 'active_plugins', [] );
		if ( ! in_array( $base, $active, true ) ) { $active[] = $base; update_option( 'active_plugins', $active ); }
		self::log( [ 'event' => 'restore', 'to' => (string) $meta['version'] ] + $who );
		return [ 'ok' => true, 'restored' => (string) $meta['version'] ];
	}

	/**
	 * /self-update/commit, once the hub found the new version healthy: the backup goes, so no later restore_self can
	 * bring back a version nobody is rolling back from. $p['to'] must be the version running now.
	 */
	public static function commit( array $p ) {
		if ( (string) ( $p['to'] ?? '' ) !== IXES_VERSION ) return new WP_Error( 'not_running', 'this site runs ' . IXES_VERSION, [ 'status' => 409 ] );
		$store = self::store();
		if ( ! $store ) return new WP_Error( 'io', 'no storage folder', [ 'status' => 500 ] );
		foreach ( [ 'backup', 'backup-new', 'failed' ] as $d ) if ( is_dir( "{$store}/{$d}" ) ) self::rrmdir( "{$store}/{$d}" );
		@unlink( "{$store}/backup.json" );
		self::log( [ 'event' => 'commit', 'to' => IXES_VERSION ] );
		return [ 'ok' => true ];
	}

	/** A host with opcache.validate_timestamps=0 would go on running the replaced files. */
	private static function invalidate( $dir ) {
		if ( ! function_exists( 'opcache_invalidate' ) || ! is_dir( $dir ) ) return;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( substr( $f->getFilename(), -4 ) === '.php' ) @opcache_invalidate( $f->getPathname(), true );
		}
	}

	/** Every self-update and restore, one JSON line each, newest last. */
	public static function log( array $entry ) {
		$store = self::store();
		if ( ! $store ) return;
		$f = "{$store}/log.jsonl";
		$lines = is_file( $f ) ? (array) file( $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : [];
		$lines[] = wp_json_encode( [ 'at' => time() ] + $entry );
		file_put_contents( $f, implode( "\n", array_slice( $lines, -100 ) ) . "\n" );
	}
	public static function log_tail( $n = 5 ) {
		$store = self::store();
		$f = $store ? "{$store}/log.jsonl" : '';
		if ( ! $f || ! is_file( $f ) ) return [];
		return array_values( array_filter( array_map( function ( $l ) { return json_decode( $l, true ); }, array_slice( (array) file( $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ), -$n ) ), 'is_array' ) );
	}

	/** Recursive copy that never follows a symlink. */
	public static function copy_tree( $src, $dst ) {
		if ( ! is_dir( $src ) || ! wp_mkdir_p( $dst ) ) return false;
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isLink() ) continue;
			$to = $dst . '/' . substr( $f->getPathname(), strlen( $src ) + 1 );
			if ( $f->isDir() ) { if ( ! is_dir( $to ) && ! @mkdir( $to, 0755 ) ) return false; }
			elseif ( ! @copy( $f->getPathname(), $to ) ) return false;
		}
		return true;
	}

	public static function rrmdir( $d ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			( $f->isDir() && ! $f->isLink() ) ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $d );
	}

	// ---------- hub side ----------

	/** A release-shaped zip of $src with top folder $top, written to $out: no dev files, no symlinks. */
	public static function build_zip( $src, $top, $out ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'no_zip', 'PHP has no zip extension (ZipArchive) on this hub; pass --zip=<release zip>' );
		$z = new ZipArchive();
		if ( $z->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) return new WP_Error( 'io', "cannot write {$out}" );
		$src = rtrim( str_replace( '\\', '/', $src ), '/' );
		$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			function ( $f ) use ( $src ) {
				if ( $f->isLink() ) return false;
				$rel = substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $src ) + 1 );
				$first = explode( '/', $rel )[0];
				if ( $first[0] === '.' || in_array( $first, self::BUILD_SKIP, true ) ) return false;
				return ! in_array( $f->getFilename(), [ '.git', 'node_modules' ], true );
			}
		) );
		$z->addEmptyDir( $top );
		foreach ( $it as $f ) {
			if ( $f->isFile() ) $z->addFile( $f->getPathname(), $top . '/' . substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $src ) + 1 ) );
		}
		if ( ! $z->close() ) return new WP_Error( 'io', "cannot write {$out}" );
		return $out;
	}

	/**
	 * What self-update would do: remote version → zip version, size and sha256, with the zip already checked here.
	 * $zip null builds one from this hub's own plugin folder (removed on shutdown).
	 * @return array|WP_Error
	 */
	public static function plan( IXES_Client $c, $zip = null, $force = false ) {
		$info = $c->info();
		if ( is_wp_error( $info ) ) return $info;
		$from = (string) ( $info['plugin'] ?? '' );
		$caps = (array) ( $info['caps'] ?? [] );
		if ( in_array( 'self_update_off', $caps, true ) ) return new WP_Error( 'self_update_off', 'self-update is turned off on the remote (ENVSYNC_DISABLE_SELF_UPDATE, or Tools → EnvSync there); upload the zip through Plugins → Add New instead' );
		if ( ! in_array( 'self_update', $caps, true ) ) return new WP_Error( 'old_remote', "the remote runs {$from}, which cannot update itself (0.9.4 or newer can); upload the release zip through Plugins → Add New this once" );
		if ( ! empty( $info['lock']['job'] ) ) return new WP_Error( 'locked', "a push (job {$info['lock']['job']}) holds the lock on the remote; let it finish, or run wp envsync unlock first" );
		$top = (string) ( $info['self_dir'] ?? self::SLUG );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $top ) || $top[0] === '.' ) return new WP_Error( 'bad_remote', "the remote reports an odd plugin folder name: {$top}" );
		$built = $zip === null;
		if ( $built ) {
			$zip = tempnam( sys_get_temp_dir(), 'ixes-self-' );
			register_shutdown_function( function () use ( $zip ) { if ( is_file( $zip ) ) @unlink( $zip ); } );
			$b = self::build_zip( self::own_folder(), $top, $zip );
			if ( is_wp_error( $b ) ) return $b;
		}
		$meta = self::inspect( $zip, $top );
		if ( is_wp_error( $meta ) ) return new WP_Error( $meta->get_error_code(), 'zip refused: ' . $meta->get_error_message() );
		$why = self::version_refusal( $from, $meta['version'], $force );
		if ( $why ) return new WP_Error( 'not_newer', "{$why}; pass --force to install it anyway" );
		return [ 'from' => $from, 'to' => $meta['version'], 'zip' => $zip, 'built' => $built, 'size' => (int) filesize( $zip ), 'sha256' => hash_file( 'sha256', $zip ),
			'top' => $top, 'url' => (string) ( $info['url'] ?? '' ), 'force' => (bool) $force ];
	}

	/**
	 * Upload, install, then check the site with fresh requests; an unhealthy site gets its old folder back through rescue.php.
	 * $fresh() returns a new IXES_Client for the env (nothing cached from before the install).
	 * @return array{from:string,to:string,sha256:string,note:string}|WP_Error
	 */
	public static function apply( array $env, IXES_Client $c, array $plan, callable $log, callable $fresh ) {
		$name = $env['name'];
		$before = IXES_Droptable::smoke( IXES_Droptable::smoke_urls( $plan['url'], bin2hex( random_bytes( 6 ) ) ), [ $c, 'probe' ] );
		$id = bin2hex( random_bytes( 8 ) );
		$log( 'uploading ' . IXES_Report::size( $plan['size'] ) );
		$up = $c->send_package( $id, $plan['zip'] );
		if ( is_wp_error( $up ) ) return new WP_Error( $up->get_error_code(), 'upload failed, nothing was installed: ' . $up->get_error_message() );
		$log( "installing {$plan['to']} on {$name}" );
		$by = getenv( 'USER' ) ?: ( function_exists( 'get_current_user' ) ? get_current_user() : '' );
		$r = $c->post( '/self-update/install', [ 'id' => $id, 'sha256' => $plan['sha256'], 'force' => ! empty( $plan['force'] ), 'hub' => home_url(), 'by' => (string) $by ], [ 'timeout' => max( IXES_Client::BATCH_TIMEOUT, $c->effective_timeout() ) ] );
		$status = is_wp_error( $r ) && is_array( $r->get_error_data() ) ? (int) ( $r->get_error_data()['status'] ?? 0 ) : 0;
		// a 4xx is a refusal before anything was touched; a 5xx or a lost answer may have half-installed, so check
		if ( $status >= 400 && $status < 500 ) return new WP_Error( 'refused', "{$name} refused the update, nothing changed: " . $r->get_error_message() );
		$note = is_wp_error( $r ) ? $r->get_error_message() : '';
		if ( $note !== '' ) $log( "the install answer was an error ({$note}); checking what {$name} runs now" );
		$log( 'checking /info and the site with fresh requests' );
		$h = self::health( $fresh(), $plan['url'], $before );
		if ( $h['why'] === null && $h['version'] === $plan['to'] ) {
			$k = $fresh()->post( '/self-update/commit', [ 'to' => $plan['to'] ] );
			if ( is_wp_error( $k ) ) $note = trim( "{$note} the remote kept its backup of {$plan['from']} (" . $k->get_error_message() . ')' );
			return [ 'from' => $plan['from'], 'to' => $plan['to'], 'sha256' => $plan['sha256'], 'note' => $note ];
		}
		if ( $h['why'] === null && $h['version'] === $plan['from'] ) return new WP_Error( 'not_installed', "{$name} still runs {$plan['from']} and answers normally; the update did not take" . ( $note !== '' ? ": {$note}" : '' ) );
		$why = $h['why'] ?? "/info reports {$h['version']}, expected {$plan['to']}";
		$log( "unhealthy after the install ({$why}); restoring {$plan['from']} through the rescue endpoint" );
		$x = $c->rescue( 'restore_self', [ 'from' => $plan['from'] ] );
		$head = "{$name} is unhealthy after installing {$plan['to']}: {$why}";
		if ( is_wp_error( $x ) ) {
			return new WP_Error( 'health_failed', "{$head}\nRestoring {$plan['from']} through the rescue endpoint failed too (" . $x->get_error_message() . "). Next: wp envsync rescue {$name} --restore-self --from={$plan['from']}; if rescue does not answer, copy wp-content/envsync-*/self-update/backup/{$plan['top']}/ over wp-content/plugins/{$plan['top']}/ with the host's file manager." );
		}
		$again = self::health( $fresh(), $plan['url'], $before );
		$tail = $again['why'] === null ? "the site answers again on {$again['version']}." : "the site still looks unhealthy: {$again['why']}. Next: wp envsync rescue {$name}";
		return new WP_Error( 'health_failed', "{$head}\nRestored {$plan['from']} through the rescue endpoint; {$tail}" );
	}

	/** @return array{version:?string,why:?string} what /info reports now, and why the site is unhealthy (null when it is not) */
	public static function health( IXES_Client $c, $url, array $before ) {
		$i = $c->info();
		if ( is_wp_error( $i ) ) return [ 'version' => null, 'why' => '/info failed: ' . $i->get_error_message() ];
		$bad = IXES_Droptable::regressions( $before, IXES_Droptable::smoke( IXES_Droptable::smoke_urls( $url, bin2hex( random_bytes( 6 ) ) ), [ $c, 'probe' ] ) );
		$why = $bad ? implode( ', ', array_map( function ( $u, $w ) { return "{$u} ({$w})"; }, array_keys( $bad ), $bad ) ) : null;
		return [ 'version' => (string) ( $i['plugin'] ?? '' ), 'why' => $why ];
	}
}
