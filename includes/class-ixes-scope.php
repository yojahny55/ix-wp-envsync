<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What a pull/diff/push touches. Built from --only / --tables / --paths. Pure.
 * --only narrows categories; --tables narrows inside db; --paths narrows inside files. Omitted = everything.
 */
class IXES_Scope {
	const ONLY = [ 'db', 'files', 'uploads', 'themes', 'plugins', 'mu-plugins' ];
	const FOLDER = [ 'uploads' => 'uploads/', 'themes' => 'themes/', 'plugins' => 'plugins/', 'mu-plugins' => 'mu-plugins/' ];
	const FAMILIES = [
		[ 'posts', 'postmeta' ],
		[ 'terms', 'term_taxonomy', 'term_relationships', 'termmeta' ],
		[ 'users', 'usermeta' ],
		[ 'comments', 'commentmeta' ],
	];

	private $only; private $tables; private $paths; private $prefix;

	private function __construct( array $only, array $tables, array $paths, $prefix ) {
		$this->only = $only; $this->tables = $tables; $this->paths = $paths; $this->prefix = (string) $prefix;
	}

	private static function list( $v ) { return array_values( array_filter( array_map( 'trim', explode( ',', (string) $v ) ) ) ); }

	public static function from_assoc( array $assoc, $prefix ) {
		$only   = self::list( $assoc['only'] ?? '' );
		$tables = self::list( $assoc['tables'] ?? '' );
		$paths  = self::list( $assoc['paths'] ?? '' );
		foreach ( $only as $o ) if ( ! in_array( $o, self::ONLY, true ) ) throw new InvalidArgumentException( "--only accepts " . implode( '|', self::ONLY ) . ", got '{$o}'" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		if ( ! $only && $tables ) $only = [ 'db' ];
		if ( ! $only && $paths )  $only = [ 'files' ];
		return new self( $only, $tables, $paths, $prefix );
	}

	public static function from_array( array $a, $prefix ) {
		return new self( (array) ( $a['only'] ?? [] ), (array) ( $a['tables'] ?? [] ), (array) ( $a['paths'] ?? [] ), $prefix );
	}
	public function to_array() { return [ 'only' => $this->only, 'tables' => $this->tables, 'paths' => $this->paths ]; }

	public function is_full() { return ! $this->only && ! $this->tables && ! $this->paths; }
	public function db_wanted() { return ! $this->only || in_array( 'db', $this->only, true ); }
	public function files_wanted() {
		if ( ! $this->only ) return true;
		foreach ( $this->only as $o ) if ( $o !== 'db' ) return true;
		return false;
	}

	public function table_in( $name ) {
		if ( ! $this->db_wanted() ) return false;
		if ( ! $this->tables ) return true;
		$bare = strpos( $name, $this->prefix ) === 0 ? substr( $name, strlen( $this->prefix ) ) : $name;
		foreach ( $this->tables as $pat ) {
			if ( fnmatch( $pat, $name ) || fnmatch( $pat, $bare ) ) return true;
		}
		return false;
	}

	public function path_in( $rel ) {
		if ( ! $this->files_wanted() ) return false;
		if ( $this->only && ! in_array( 'files', $this->only, true ) ) {
			$hit = false;
			foreach ( $this->only as $o ) { // phpcs:ignore PHPCompatibility.ControlStructures.ForbiddenBreakContinueOutsideLoop
				if ( isset( self::FOLDER[ $o ] ) && strpos( $rel, self::FOLDER[ $o ] ) === 0 ) { $hit = true; break; }
			}
			if ( ! $hit ) return false;
		}
		if ( ! $this->paths ) return true;
		foreach ( $this->paths as $pat ) {
			$pat = ltrim( $pat, '/' );
			if ( substr( $pat, -1 ) === '/' ) { if ( strpos( $rel, $pat ) === 0 ) return true; }
			elseif ( fnmatch( $pat, $rel ) ) return true;
		}
		return false;
	}

	public function label() {
		if ( $this->is_full() ) return 'everything';
		$parts = [];
		// Don't show only if it was inferred from tables/paths
		$inferred = ( count( $this->only ) === 1 &&
		              ( ( $this->only[0] === 'db' && $this->tables ) ||
		                ( $this->only[0] === 'files' && $this->paths ) ) );
		if ( $this->only && ! $inferred )   $parts[] = implode( ',', $this->only );
		if ( $this->tables ) $parts[] = 'tables ' . implode( ',', $this->tables );
		if ( $this->paths )  $parts[] = 'paths ' . implode( ',', $this->paths );
		return implode( ', ', $parts );
	}

	/** One line per split family, e.g. "wp_posts selected without wp_postmeta; pull the full db before the next push". */
	public function family_warnings( array $selected ) {
		if ( ! $this->tables ) return [];
		$out = [];
		foreach ( self::FAMILIES as $fam ) {
			$in = []; $missing = [];
			foreach ( $fam as $t ) { $full = $this->prefix . $t; if ( in_array( $full, $selected, true ) ) $in[] = $full; else $missing[] = $full; }
			if ( $in && $missing ) $out[] = implode( ',', $in ) . ' selected without ' . implode( ',', $missing ) . '; pull the full db before the next push';
		}
		return $out;
	}
}
