<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One plan summary for humans (render_text) and agents (the same array as JSON).
 * build() is pure: callers gather inventories, sizes and active lists and hand them in.
 */
class IXES_Report {
	const SCHEMA = 1;

	public static function build( array $in ) {
		$push = $in['direction'] === 'push';
		$sizes = $in['sizes'];
		$r = [
			'schema' => self::SCHEMA, 'kind' => $in['kind'], 'env' => $in['env'], 'url' => $in['url'], 'created' => $in['created'],
			'direction' => $in['direction'], 'baseline_at' => $in['baseline_at'], 'first_deploy' => (bool) $in['first_deploy'],
			'scope' => $in['scope'], 'scope_full' => (bool) $in['scope_full'],
			'summary' => [ 'files' => count( $in['files'] ), 'delete' => count( $in['deletes'] ), 'bytes' => null, 'rows' => 0, 'conflicts' => count( $in['conflicts'] ) ],
			'tables' => [], 'new_tables' => array_values( (array) ( $in['new_tables'] ?? [] ) ), 'plugins' => [], 'themes' => [], 'other' => [], 'conflicts' => $in['conflicts'], 'warnings' => $in['warnings'],
		];

		foreach ( $in['tables'] as $name => $t ) {
			if ( ! $push ) { $r['tables'][] = [ 'name' => $name, 'rows' => (int) $t['rows'] ]; continue; }
			if ( ! array_sum( $t ) ) continue;
			$r['tables'][] = [ 'name' => $name ] + $t;
			$r['summary']['rows'] += $t['push'] + $t['insert'] + $t['delete'];
		}
		if ( $in['rows'] !== null ) $r['summary']['rows'] = (int) $in['rows'];

		// group every moving/deleted file: plugins/<slug>, themes/<slug>, or an "other" folder
		$groups = [];
		foreach ( [ 'files' => $in['files'], 'delete' => $in['deletes'] ] as $k => $list ) {
			foreach ( $list as $rel ) {
				list( $kind, $key ) = self::group_of( $rel );
				if ( ! isset( $groups[ $kind ][ $key ] ) ) $groups[ $kind ][ $key ] = [ 'files' => 0, 'delete' => 0, 'bytes' => $sizes === null ? null : 0 ];
				$groups[ $kind ][ $key ][ $k ]++;
				if ( $k === 'files' && $sizes !== null ) $groups[ $kind ][ $key ]['bytes'] += (int) ( $sizes[ $rel ] ?? 0 );
			}
		}
		if ( $sizes !== null ) { $r['summary']['bytes'] = 0; foreach ( $in['files'] as $rel ) $r['summary']['bytes'] += (int) ( $sizes[ $rel ] ?? 0 ); }

		$before = $in['before']; $source = $in['source'];
		$ver = function ( $inv, $type, $slug ) { return $inv === null ? '?' : ( $inv[ $type ][ $slug ] ?? null ); };

		$act_b = array_map( [ __CLASS__, 'plugin_slug' ], (array) $in['active_before'] );
		$act_a = array_map( [ __CLASS__, 'plugin_slug' ], (array) $in['active_after'] );
		$slugs = array_unique( array_merge( array_keys( $groups['plugins'] ?? [] ), array_diff( $act_b, $act_a ), array_diff( $act_a, $act_b ) ) );
		sort( $slugs );
		foreach ( $slugs as $slug ) {
			$g = $groups['plugins'][ $slug ] ?? [ 'files' => 0, 'delete' => 0, 'bytes' => $sizes === null ? null : 0 ];
			$b = in_array( $slug, $act_b, true ); $a = in_array( $slug, $act_a, true );
			$vb = $ver( $before, 'plugins', $slug );
			$r['plugins'][] = [ 'slug' => $slug ] + $g + [
				'version' => [ 'before' => $vb, 'after' => ( $g['files'] + $g['delete'] ) ? $ver( $source, 'plugins', $slug ) : $vb ],
				'active' => [ 'before' => $b, 'after' => $a ],
				'change' => $a && ! $b ? 'turns on' : ( $b && ! $a ? 'turns off' : ( $a ? 'stays on' : '' ) ),
			];
		}

		$ss_b = $before === null ? null : ( $before['stylesheet'] ?? null );
		$ss_a = $in['stylesheet_after'];
		$slugs = array_keys( $groups['themes'] ?? [] );
		foreach ( [ $ss_b, $ss_a ] as $s ) if ( $s !== null && $ss_b !== $ss_a && ! in_array( $s, $slugs, true ) ) $slugs[] = $s;
		sort( $slugs );
		foreach ( $slugs as $slug ) {
			$g = $groups['themes'][ $slug ] ?? [ 'files' => 0, 'delete' => 0, 'bytes' => $sizes === null ? null : 0 ];
			$b = $slug === $ss_b; $a = $slug === $ss_a;
			$vb = $ver( $before, 'themes', $slug );
			$r['themes'][] = [ 'slug' => $slug ] + $g + [
				'version' => [ 'before' => $vb, 'after' => ( $g['files'] + $g['delete'] ) ? $ver( $source, 'themes', $slug ) : $vb ],
				'active' => [ 'before' => $b, 'after' => $a ],
				'change' => $a && ! $b ? 'becomes active' : ( $b && ! $a ? 'stops being active' : ( $a ? 'active' : '' ) ),
			];
		}

		foreach ( $groups['other'] ?? [] as $group => $g ) $r['other'][] = [ 'group' => $group ] + $g;
		usort( $r['other'], function ( $x, $y ) { return self::other_rank( $x['group'] ) <=> self::other_rank( $y['group'] ) ?: strcmp( $x['group'], $y['group'] ); } );
		return $r;
	}

	// ---------- adapters: read WordPress state, then call build() ----------

	/** $kind is 'diff' or 'push'; $plan is the planner's plan (after --force merged conflicts into push, for a push). */
	public static function from_push_plan( array $plan, array $info, $kind ) {
		global $wpdb;
		$tables = []; $conflicts = [];
		foreach ( $plan['tables'] as $name => $t ) {
			$tables[ $name ] = [ 'push' => count( $t['push'] ) + count( $t['set_insert'] ), 'insert' => count( $t['insert'] ), 'delete' => count( $t['delete'] ) + count( $t['set_delete'] ?? [] ), 'prod_wins' => count( $t['conflict'] ), 'kept_prod' => count( $t['kept'] ) ];
			foreach ( $t['conflict'] as $id ) $conflicts[] = [ 'type' => 'row', 'table' => $name, 'id' => (string) $id, 'title' => (string) ( $plan['conflict_detail'][ $name ][ $id ] ?? '' ) ];
		}
		foreach ( $plan['files']['conflict'] as $rel ) $conflicts[] = [ 'type' => 'file', 'path' => $rel ];
		$sizes = [];
		foreach ( $plan['files']['push'] as $rel ) $sizes[ $rel ] = is_file( WP_CONTENT_DIR . '/' . $rel ) ? (int) filesize( WP_CONTENT_DIR . '/' . $rel ) : 0;
		$before = $info['inventory'] ?? null;
		// the active theme changes only if this push carries the local 'stylesheet' option row
		$opt = $plan['tables'][ $wpdb->options ] ?? null;
		$ss_id = $opt ? $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name = 'stylesheet'" ) : null;
		$moves_ss = $ss_id !== null && in_array( (string) $ss_id, array_map( 'strval', array_merge( $opt['push'], $opt['insert'] ) ), true );
		$remote_active = (array) ( $info['active_plugins'] ?? [] );
		$sc = IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' );
		return self::build( [
			'kind' => $kind, 'env' => $plan['env'], 'url' => (string) ( $info['url'] ?? '' ), 'created' => $plan['created'], 'direction' => 'push',
			'baseline_at' => $plan['baseline_at'], 'first_deploy' => (bool) $plan['two_way'], 'scope' => $sc->label(), 'scope_full' => $sc->is_full(),
			'tables' => $tables, 'new_tables' => array_keys( (array) ( $plan['new_tables'] ?? [] ) ), 'rows' => null,
			'files' => $plan['files']['push'], 'deletes' => $plan['files']['delete'], 'sizes' => $sizes,
			'before' => $before, 'source' => IXES_Transfer::inventory(),
			'active_before' => $remote_active, 'active_after' => $plan['active_plugins'] !== null ? $plan['active_plugins'] : $remote_active,
			'stylesheet_after' => $moves_ss ? get_stylesheet() : ( $before['stylesheet'] ?? null ),
			'conflicts' => $conflicts, 'warnings' => (array) ( $plan['warnings'] ?? [] ),
		] );
	}

	public static function from_pull_plan( array $plan ) {
		global $wpdb;
		$tables = [];
		foreach ( $plan['tables'] as $t ) $tables[ $t['name'] ] = [ 'rows' => (int) $t['rows'] ];
		$local  = IXES_Transfer::inventory();
		$remote = $plan['info']['inventory'] ?? null;
		$opts_in = isset( $tables[ $wpdb->options ] );
		$local_active = (array) get_option( 'active_plugins', [] );
		$bl = new IXES_Baseline( ixes_storage_dir() . '/baseline-' . $plan['env'] . '.sqlite' );
		$sc = IXES_Scope::from_array( (array) ( $plan['scope'] ?? [] ), '' );
		return self::build( [
			'kind' => 'pull', 'env' => $plan['env'], 'url' => (string) $plan['info']['url'], 'created' => $plan['created'], 'direction' => 'pull',
			'baseline_at' => $bl->meta( 'created_at' ), 'first_deploy' => false, 'scope' => $sc->label(), 'scope_full' => $sc->is_full(),
			'tables' => $tables, 'rows' => array_sum( array_column( $plan['tables'], 'rows' ) ),
			'files' => $plan['files']['transfer'], 'deletes' => $plan['files']['delete'], 'sizes' => $plan['sizes'] ?? null,
			'before' => $local, 'source' => $remote,
			'active_before' => $local_active, 'active_after' => $opts_in ? (array) ( $plan['info']['active_plugins'] ?? [] ) : $local_active,
			'stylesheet_after' => $opts_in ? ( $remote['stylesheet'] ?? null ) : $local['stylesheet'],
			'conflicts' => [], 'warnings' => (array) ( $plan['warnings'] ?? [] ),
		] );
	}

	/** Writes the manifest agents read; returns the latest-copy path. */
	public static function save( array $report ) {
		$dir = ixes_storage_dir() . '/plans'; wp_mkdir_p( $dir );
		$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		file_put_contents( "{$dir}/report-{$report['kind']}-{$report['env']}-" . date( 'Ymd-His', (int) $report['created'] ) . '.json', $json );
		$latest = "{$dir}/{$report['kind']}-{$report['env']}-latest.json";
		file_put_contents( $latest, $json );
		return $latest;
	}

	/** The outcome of a push or pull, written on success and on failure. */
	public static function save_run( $kind, $env, array $data ) {
		$dir = ixes_storage_dir() . '/runs'; wp_mkdir_p( $dir );
		$path = "{$dir}/{$kind}-{$env}-latest.json";
		file_put_contents( $path, wp_json_encode( [ 'schema' => self::SCHEMA, 'kind' => $kind, 'env' => $env ] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		return $path;
	}

	// 'akismet/akismet.php' -> 'akismet'; a single-file plugin keeps its file name ('hello.php')
	public static function plugin_slug( $file ) { $d = dirname( (string) $file ); return $d === '.' ? (string) $file : $d; }

	private static function group_of( $rel ) {
		$p = explode( '/', $rel );
		if ( count( $p ) === 1 ) return [ 'other', 'wp-content root' ];
		if ( $p[0] === 'plugins' || $p[0] === 'themes' ) return [ $p[0], $p[1] ];
		if ( $p[0] === 'uploads' && count( $p ) > 2 ) return [ 'other', "uploads/{$p[1]}/" ];
		return [ 'other', $p[0] . '/' ];
	}
	private static function other_rank( $g ) { return strpos( $g, 'uploads/' ) === 0 ? 0 : ( $g === 'wp-content root' ? 2 : 1 ); }

	public static function render_text( array $r ) {
		$push = $r['direction'] === 'push';
		$o = [];
		$o[] = $push ? "{$r['env']}  ←  local" : "{$r['env']}  →  local";
		if ( $r['baseline_at'] ) $o[] = '  baseline: ' . wp_date( 'Y-m-d H:i', (int) $r['baseline_at'] );
		elseif ( $push && $r['first_deploy'] ) $o[] = "  baseline: none — first deploy, local overwrites {$r['env']}";
		else $o[] = '  baseline: none';
		if ( ! $r['scope_full'] ) $o[] = "  scope: {$r['scope']}";
		$s = $r['summary'];
		$o[] = '  ' . number_format( $s['files'] ) . ( $s['files'] === 1 ? ' file' : ' files' ) . ( $s['delete'] ? ' (+' . number_format( $s['delete'] ) . ' deleted)' : '' )
			. ' · ' . self::size( $s['bytes'] ) . ' · ' . number_format( $s['rows'] ) . ' rows';

		if ( $r['tables'] ) {
			$o[] = ''; $o[] = 'DATABASE';
			$o[] = $push
				? self::table( [ 'table', 'push', 'insert', 'delete', 'remote-wins', 'kept-remote' ], array_map( function ( $t ) use ( $r ) { return [ $t['name'] . ( in_array( $t['name'], $r['new_tables'], true ) ? ' (new)' : '' ), $t['push'], $t['insert'], $t['delete'], $t['prod_wins'], $t['kept_prod'] ]; }, $r['tables'] ) )
				: self::table( [ 'table', 'rows' ], array_map( function ( $t ) { return [ $t['name'], $t['rows'] ]; }, $r['tables'] ) );
		}
		$has_del = $s['delete'] > 0;
		foreach ( [ 'plugins' => 'plugin', 'themes' => 'theme' ] as $k => $label ) {
			if ( ! $r[ $k ] ) continue;
			$o[] = ''; $o[] = strtoupper( $k );
			$head = array_merge( [ $label, 'files' ], $has_del ? [ 'delete' ] : [], [ 'size', 'version', 'active' ] );
			$o[] = self::table( $head, array_map( function ( $x ) use ( $has_del ) {
				return array_merge( [ $x['slug'], $x['files'] ], $has_del ? [ $x['delete'] ] : [], [ $x['files'] ? self::size( $x['bytes'] ) : '—', self::version( $x['version'] ), $x['change'] ] );
			}, $r[ $k ] ) );
		}
		if ( $r['other'] ) {
			$o[] = ''; $o[] = 'OTHER FILES';
			$head = array_merge( [ 'folder', 'files' ], $has_del ? [ 'delete' ] : [], [ 'size' ] );
			$o[] = self::table( $head, array_map( function ( $x ) use ( $has_del ) { return array_merge( [ $x['group'], $x['files'] ], $has_del ? [ $x['delete'] ] : [], [ $x['files'] ? self::size( $x['bytes'] ) : '—' ] ); }, $r['other'] ) );
		}
		if ( $r['conflicts'] ) {
			$o[] = ''; $o[] = "CONFLICTS ({$r['env']} wins)";
			foreach ( $r['conflicts'] as $c ) $o[] = $c['type'] === 'file' ? "  file                 {$c['path']}" : sprintf( '  %-20s #%s  %s', $c['table'], $c['id'], $c['title'] );
		}
		if ( ! $s['files'] && ! $s['delete'] && ! $r['tables'] && ! $r['plugins'] && ! $r['themes'] ) { $o[] = ''; $o[] = 'Nothing to ' . ( $push ? 'push' : 'pull' ) . '.'; }
		return implode( "\n", $o ) . "\n";
	}

	private static function version( array $v ) {
		$f = function ( $x ) { return $x === null ? '—' : (string) $x; };
		return $v['before'] === $v['after'] ? $f( $v['after'] ) : $f( $v['before'] ) . ' → ' . $f( $v['after'] );
	}

	public static function size( $bytes ) {
		if ( $bytes === null ) return '? MB';
		if ( $bytes < 1024 ) return $bytes . ' B';
		if ( $bytes < 1048576 ) return number_format( $bytes / 1024, 1 ) . ' KB';
		if ( $bytes < 1073741824 ) return number_format( $bytes / 1048576, 1 ) . ' MB';
		return number_format( $bytes / 1073741824, 2 ) . ' GB';
	}

	// ponytail: own ASCII table so render_text stays a pure string (format_items echoes) and numbers right-align
	private static function table( array $head, array $rows ) {
		$w = array_map( 'mb_strlen', $head );
		foreach ( $rows as $row ) foreach ( $row as $i => $cell ) $w[ $i ] = max( $w[ $i ], mb_strlen( is_int( $cell ) ? number_format( $cell ) : (string) $cell ) );
		$line = '+' . implode( '+', array_map( function ( $n ) { return str_repeat( '-', $n + 2 ); }, $w ) ) . '+';
		$fmt = function ( array $row ) use ( $w ) {
			$c = [];
			foreach ( $row as $i => $cell ) {
				$txt = is_int( $cell ) ? number_format( $cell ) : (string) $cell;
				$pad = str_repeat( ' ', $w[ $i ] - mb_strlen( $txt ) );
				$c[] = ' ' . ( is_int( $cell ) ? $pad . $txt : $txt . $pad ) . ' ';
			}
			return '|' . implode( '|', $c ) . '|';
		};
		return implode( "\n", array_merge( [ $line, $fmt( $head ), $line ], array_map( $fmt, $rows ), [ $line ] ) );
	}
}
