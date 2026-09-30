<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Must-use plugins and drop-ins: the files WordPress loads on every request, rescue.php included.
 * A broken one takes the whole site down with no EnvSync command left to recover it, so they get
 * their own plan section, travel last, land in one step, and can be moved aside without booting WordPress.
 *
 * No WordPress functions here: rescue.php's bare path loads this class (and IXES_Auth) on a site whose
 * WordPress fatals, and only defines ABSPATH first.
 */
class IXES_Mu {
	/** Drop-ins WordPress loads straight from wp-content when they exist. */
	const DROPINS = [ 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' ];

	/** Paths a host installs for itself, which only work on that host. Excluded by default; see host_excludes(). */
	const HOST_SPECIFIC = [
		'mu-plugins/wp-toolkit.php'                  => 'Plesk WP Toolkit',
		'mu-plugins/wp-toolkit/'                     => 'Plesk WP Toolkit',
		'mu-plugins/imunify-security-bots.php'       => 'Imunify',
		'plugins/imunify-security/'                  => 'Imunify',
		'imunify-security/'                          => 'Imunify',
		'mu-plugins/mu-plugin.php'                   => 'WP Engine',
		'mu-plugins/wpengine-common/'                => 'WP Engine',
		'mu-plugins/wpe-wp-sign-on-plugin.php'       => 'WP Engine',
		'mu-plugins/wpe-wp-sign-on-plugin/'          => 'WP Engine',
		'mu-plugins/wpengine-security-auditor.php'   => 'WP Engine',
		'mu-plugins/slt-force-strong-passwords.php'  => 'WP Engine',
		'mu-plugins/force-strong-passwords/'         => 'WP Engine',
		'mu-plugins/wpe-cache-plugin.php'            => 'WP Engine',
		'mu-plugins/wpe-cache-plugin/'               => 'WP Engine',
		'mu-plugins/kinsta-mu-plugins.php'           => 'Kinsta',
		'mu-plugins/kinsta-mu-plugins/'              => 'Kinsta',
		'mu-plugins/gd-system-plugin.php'            => 'GoDaddy',
		'mu-plugins/gd-system-plugin/'               => 'GoDaddy',
		'mu-plugins/pantheon.php'                    => 'Pantheon',
		'mu-plugins/pantheon/'                       => 'Pantheon',
		'mu-plugins/endurance-page-cache.php'        => 'Bluehost',
		'mu-plugins/endurance-browser-cache.php'     => 'Bluehost',
	];

	const KEY_FILE = 'rescue-key.json';
	const WARNING  = 'mu-plugins and drop-ins load on every request, rescue included: if one crashes the site, run wp envsync rescue %s --quarantine-mu';

	public static function is_boot_path( $rel ) {
		$rel = (string) $rel;
		return strpos( $rel, 'mu-plugins/' ) === 0 || in_array( $rel, self::DROPINS, true );
	}

	/** 0 = a file inside a mu-plugin folder, 1 = a loader in mu-plugins/, 2 = a drop-in. */
	private static function rank( $rel ) {
		if ( in_array( $rel, self::DROPINS, true ) ) return 2;
		return substr_count( $rel, '/' ) === 1 ? 1 : 0;
	}

	/** Everything else first, then mu-plugin folders, then their loaders, then drop-ins: a cut transfer never leaves a loader pointing at missing code. */
	public static function boot_last( array $rels ) {
		$out = [ [], [], [], [] ];
		foreach ( $rels as $rel ) $out[ self::is_boot_path( $rel ) ? 1 + self::rank( $rel ) : 0 ][] = $rel;
		return array_merge( ...$out );
	}

	/** Deletes the other way round: a loader goes before the folder it requires. */
	public static function loaders_first( array $rels ) {
		$first = []; $rest = [];
		foreach ( $rels as $rel ) {
			if ( self::is_boot_path( $rel ) && self::rank( $rel ) > 0 ) $first[] = $rel; else $rest[] = $rel;
		}
		return array_merge( $first, $rest );
	}

	/**
	 * Runs of files in the order given: consecutive small files share a batch, a large one goes alone.
	 * @param array $sizes rel => bytes, in sending order
	 * @return array list of [ 'batch' => rels ] or [ 'large' => rel ]
	 */
	public static function ordered_runs( array $sizes ) {
		$runs = []; $cur = []; $bytes = 0;
		foreach ( $sizes as $rel => $n ) {
			if ( $n > IXES_Batch::SMALL ) {
				if ( $cur ) { $runs[] = [ 'batch' => $cur ]; $cur = []; $bytes = 0; }
				$runs[] = [ 'large' => $rel ];
				continue;
			}
			if ( $cur && ( $bytes + $n > IXES_Batch::MAX_BYTES || count( $cur ) >= IXES_Batch::MAX_ITEMS ) ) { $runs[] = [ 'batch' => $cur ]; $cur = []; $bytes = 0; }
			$cur[] = $rel; $bytes += $n;
		}
		if ( $cur ) $runs[] = [ 'batch' => $cur ];
		return $runs;
	}

	/** The plan row a boot path belongs to: 'mu-plugins/x.php', 'mu-plugins/x/' or 'db.php (drop-in)'. */
	public static function slug_of( $rel ) {
		if ( in_array( $rel, self::DROPINS, true ) ) return $rel . ' (drop-in)';
		$p = explode( '/', $rel );
		return count( $p ) > 2 ? "mu-plugins/{$p[1]}/" : $rel;
	}

	/**
	 * One row per mu-plugin file or top-level folder, and per drop-in.
	 * $new: pushed paths the remote does not have yet (null for a pull, where it is not known).
	 * @return array list of [ slug, files, delete, change: new|changed|delete|'' ]
	 */
	public static function groups( array $push, array $delete, ?array $new = null ) {
		$new = $new === null ? null : array_flip( $new );
		$g = [];
		foreach ( [ 'files' => $push, 'delete' => $delete ] as $k => $list ) {
			foreach ( $list as $rel ) {
				if ( ! self::is_boot_path( $rel ) ) continue;
				$slug = self::slug_of( $rel );
				if ( ! isset( $g[ $slug ] ) ) $g[ $slug ] = [ 'slug' => $slug, 'files' => 0, 'delete' => 0, 'new' => 0 ];
				$g[ $slug ][ $k ]++;
				if ( $k === 'files' && $new !== null && isset( $new[ $rel ] ) ) $g[ $slug ]['new']++;
			}
		}
		uksort( $g, function ( $a, $b ) {
			$da = substr( $a, -9 ) === '(drop-in)'; $db = substr( $b, -9 ) === '(drop-in)';
			return $da <=> $db ?: strcmp( $a, $b );
		} );
		$out = [];
		foreach ( $g as $x ) {
			if ( ! $x['files'] ) $change = 'delete';
			elseif ( $new === null ) $change = '';
			else $change = $x['new'] === $x['files'] ? 'new' : 'changed';
			$out[] = [ 'slug' => $x['slug'], 'files' => $x['files'], 'delete' => $x['delete'], 'change' => $change ];
		}
		return $out;
	}

	/** The host-specific paths this environment still excludes (env add --remove-exclude takes one back). */
	public static function host_excludes( array $env ) {
		return array_values( array_diff( array_keys( self::HOST_SPECIFIC ), (array) ( $env['host_included'] ?? [] ) ) );
	}

	/** --remove-exclude: a host-specific path is taken back into the sync; any other is dropped from the env's own list. */
	public static function remove_excludes( array $env, array $paths ) {
		$env['excludes'] = array_values( array_diff( (array) ( $env['excludes'] ?? [] ), $paths ) );
		$host = array_values( array_intersect( $paths, array_keys( self::HOST_SPECIFIC ) ) );
		if ( $host ) $env['host_included'] = array_values( array_unique( array_merge( (array) ( $env['host_included'] ?? [] ), $host ) ) );
		return $env;
	}

	/** --add-exclude: a host-specific path goes back to its default (excluded); any other joins the env's own list. */
	public static function add_excludes( array $env, array $paths ) {
		$host = array_intersect( $paths, array_keys( self::HOST_SPECIFIC ) );
		if ( isset( $env['host_included'] ) ) $env['host_included'] = array_values( array_diff( (array) $env['host_included'], $host ) );
		$env['excludes'] = array_values( array_unique( array_merge( (array) ( $env['excludes'] ?? [] ), array_diff( $paths, $host ) ) ) );
		return $env;
	}

	/** The HOST_SPECIFIC entry $rel falls under, or null. */
	public static function host_entry( $rel ) {
		foreach ( self::HOST_SPECIFIC as $p => $host ) {
			if ( substr( $p, -1 ) === '/' ? strpos( $rel, $p ) === 0 : $rel === $p ) return $p;
		}
		return null;
	}

	/** One warning per host-specific entry the push would send to an environment that does not have it. */
	public static function host_warnings( array $push, array $remote_hashes, $env_name ) {
		$hit = [];
		foreach ( $push as $rel ) {
			$p = self::host_entry( $rel );
			if ( $p !== null && array_key_exists( $rel, $remote_hashes ) && $remote_hashes[ $rel ] === null ) $hit[ $p ] = true;
		}
		$out = [];
		foreach ( array_keys( $hit ) as $p ) $out[] = "{$p} belongs to " . self::HOST_SPECIFIC[ $p ] . " and {$env_name} does not have it; it works only on that host. Keep it out with: wp envsync env add {$env_name} --add-exclude={$p}";
		return $out;
	}

	/**
	 * A forced push (no baseline) sends mu-plugins and drop-ins only when --only names mu-plugins:
	 * the rest of the site can be rolled back, a crashing mu-plugin takes rollback down with it.
	 */
	public static function hold_boot( array $plan, array $only ) {
		if ( in_array( 'mu-plugins', $only, true ) ) return $plan;
		$held = 0;
		foreach ( [ 'push', 'delete' ] as $k ) {
			$keep = [];
			foreach ( $plan['files'][ $k ] as $rel ) {
				if ( self::is_boot_path( $rel ) ) { $held++; unset( $plan['remote_file_hashes'][ $rel ] ); } else $keep[] = $rel;
			}
			$plan['files'][ $k ] = $keep;
		}
		if ( $held ) $plan['warnings'][] = "held back {$held} mu-plugin/drop-in file(s): a forced push sends them only with --only naming mu-plugins (e.g. --only=mu-plugins, after checking each one works on this host)";
		return $plan;
	}

	// ---------- rescue without WordPress ----------

	/** Written on every authenticated request whose key differs: rescue.php's bare path verifies the token against it. @return bool whether it wrote */
	public static function write_key( $storage, $token, $allow_http ) {
		$body = json_encode( [ 'sha256' => hash( 'sha256', (string) $token ), 'allow_http' => (bool) $allow_http ] );
		$f = $storage . '/' . self::KEY_FILE;
		if ( is_file( $f ) && file_get_contents( $f ) === $body ) return false;
		return file_put_contents( $f, $body ) !== false;
	}

	/** @return array|null [ allow_http ] when $token is the one the key was written for */
	public static function key_matches( $storage, $token ) {
		$k = json_decode( (string) @file_get_contents( $storage . '/' . self::KEY_FILE ), true );
		if ( ! is_array( $k ) || ! is_string( $k['sha256'] ?? null ) || ! hash_equals( $k['sha256'], hash( 'sha256', (string) $token ) ) ) return null;
		return [ 'allow_http' => ! empty( $k['allow_http'] ) ];
	}

	/**
	 * Authenticate a rescue request with no WordPress: the storage folder's key stands in for the token hash.
	 * @return string|WP_Error the storage folder that holds the matching key
	 */
	public static function bare_auth( $content_dir, $token, $ts, $body, $sig, $https, $now = null ) {
		$now = $now === null ? time() : $now;
		foreach ( glob( $content_dir . '/envsync-*', GLOB_ONLYDIR ) ?: [] as $dir ) {
			if ( ! preg_match( '/^envsync-[0-9a-f]{16}$/', basename( $dir ) ) ) continue;
			$k = self::key_matches( $dir, $token );
			if ( $k === null ) continue;
			if ( ! $https && ! $k['allow_http'] ) return new WP_Error( 'https', 'https required', [ 'status' => 403 ] );
			if ( abs( $now - (int) $ts ) > IXES_Auth::SKEW ) break;
			if ( ! hash_equals( IXES_Auth::sign( $token, 'POST', '/envsync/v1/rescue', $ts, $body ), (string) $sig ) ) break;
			return $dir;
		}
		return new WP_Error( 'auth', 'bad signature, or no rescue key yet (it is written by the first request of a push)', [ 'status' => 401 ] );
	}

	private static function safe( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		return $rel === '' || $rel[0] === '/' || strpos( $rel, '..' ) !== false || strpos( $rel, "\0" ) !== false ? null : $rel;
	}

	private static function move( $from, $to ) {
		if ( ! is_dir( dirname( $to ) ) && ! @mkdir( dirname( $to ), 0755, true ) ) return false;
		if ( @rename( $from, $to ) ) return true;
		return @copy( $from, $to ) && @unlink( $from );
	}

	private static function rrmdir( $d ) {
		if ( ! is_dir( $d ) ) return;
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $d, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $d );
	}

	/**
	 * Move every mu-plugin and drop-in job $job (default: the newest) pushed or deleted into quarantine/<job>/,
	 * then put back the versions its snapshot kept. Files only: works however broken WordPress is.
	 * @return array|WP_Error [ job, quarantined, restored, errors ]
	 */
	public static function quarantine( $content_dir, $storage, $job = null ) {
		if ( $job === null ) {
			$jobs = glob( $storage . '/jobs/*', GLOB_ONLYDIR ) ?: [];
			sort( $jobs );
			$job = $jobs ? basename( end( $jobs ) ) : null;
		}
		$job = preg_replace( '/[^a-z0-9-]/', '', (string) $job );
		$jd = $storage . '/jobs/' . $job;
		$meta = $job !== '' ? json_decode( (string) @file_get_contents( $jd . '/meta.json' ), true ) : null;
		if ( ! is_array( $meta ) ) return new WP_Error( 'nojob', 'no such push job', [ 'status' => 404 ] );
		$plan = (array) ( $meta['plan']['files'] ?? [] );
		$out = [ 'job' => $job, 'quarantined' => [], 'restored' => [], 'errors' => [] ];
		$paths = [];
		foreach ( array_merge( (array) ( $plan['push'] ?? [] ), (array) ( $plan['delete'] ?? [] ) ) as $rel ) {
			$rel = self::safe( $rel );
			if ( $rel !== null && self::is_boot_path( $rel ) ) $paths[ $rel ] = true;
		}
		foreach ( array_keys( $paths ) as $rel ) {
			$live = $content_dir . '/' . $rel;
			if ( is_file( $live ) ) {
				if ( self::move( $live, $storage . '/quarantine/' . $job . '/' . $rel ) ) $out['quarantined'][] = $rel;
				else { $out['errors'][] = "cannot move {$rel}"; continue; }
			}
			$snap = $jd . '/files/' . $rel;
			if ( is_file( $snap ) ) {
				if ( ( is_dir( dirname( $live ) ) || @mkdir( dirname( $live ), 0755, true ) ) && @copy( $snap, $live ) ) $out['restored'][] = $rel;
				else $out['errors'][] = "cannot restore {$rel}";
			}
		}
		// what never left staging must not land later
		self::rrmdir( $jd . '/stage' );
		return $out;
	}

	/**
	 * Move a push's staged mu-plugins and drop-ins into place, folders before their loaders.
	 * $each( $rel ) runs before each move (the applier records what the push created).
	 * @return true|WP_Error
	 */
	public static function commit_staged( $stage, $content_dir, ?callable $each = null ) {
		if ( ! is_dir( $stage ) ) return true;
		$rels = [];
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $f->isFile() && substr( $f->getFilename(), -9 ) !== '.ixes-tmp' ) $rels[] = str_replace( '\\', '/', substr( $f->getPathname(), strlen( $stage ) + 1 ) );
		}
		sort( $rels, SORT_STRING );
		foreach ( self::boot_last( $rels ) as $rel ) {
			if ( $each ) $each( $rel );
			if ( ! self::move( $stage . '/' . $rel, $content_dir . '/' . $rel ) ) return new WP_Error( 'io', "cannot move staged {$rel} into place" );
		}
		self::rrmdir( $stage );
		return true;
	}
}
