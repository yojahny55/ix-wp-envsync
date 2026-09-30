<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** One table's rows seen one at a time: where a row is (diff --id) and which rows sit in each column (diff --list). */
class IXES_Rowinfo {

	/** Columns that tell a core table's rows apart at a glance, keyed by the table name without its prefix. */
	const LABELS = [
		'posts' => [ 'post_type', 'post_status', 'post_date', 'post_title' ],
		'postmeta' => [ 'post_id', 'meta_key' ],
		'terms' => [ 'name', 'slug' ],
		'term_taxonomy' => [ 'taxonomy', 'term_id' ],
		'options' => [ 'option_name' ],
		'comments' => [ 'comment_post_ID', 'comment_author', 'comment_date' ],
		'commentmeta' => [ 'comment_id', 'meta_key' ],
		'termmeta' => [ 'term_id', 'meta_key' ],
		'users' => [ 'user_login' ],
		'usermeta' => [ 'user_id', 'meta_key' ],
	];

	/**
	 * Where a row is, and its fields: the ones that differ when both sides have it, all of them when one does.
	 * Values are compared as the hash compares them, each side through its own placeholders.
	 * @return array{where: string, fields: array<int, array{0: string, 1: ?string, 2: ?string}>} [ column, remote, local ]
	 */
	public static function compare( ?array $local, ?array $remote, array $local_pairs, array $remote_pairs ) {
		if ( $local === null && $remote === null ) return [ 'where' => 'neither', 'fields' => [] ];
		$fields = [];
		foreach ( array_unique( array_merge( array_keys( (array) $local ), array_keys( (array) $remote ) ) ) as $col ) {
			$l = $local === null ? null : self::text( IXES_Hasher::normalize( $local[ $col ] ?? null, $local_pairs ) );
			$r = $remote === null ? null : self::text( IXES_Hasher::normalize( $remote[ $col ] ?? null, $remote_pairs ) );
			if ( $local !== null && $remote !== null && $l === $r ) continue;
			$fields[] = [ (string) $col, $r, $l ];
		}
		if ( $remote === null ) return [ 'where' => 'local-only', 'fields' => $fields ];
		if ( $local === null ) return [ 'where' => 'remote-only', 'fields' => $fields ];
		return [ 'where' => $fields ? 'differs' : 'same', 'fields' => $fields ];
	}

	private static function text( $v ) { return $v === null ? null : (string) $v; }

	/**
	 * The keys in one column of a plan table, and the side that holds those rows. Both vocabularies work:
	 * a first deploy's (local-only, differs, remote-only) and a baseline's (push, insert, delete, remote-wins, kept-remote).
	 * @return array{ids: string[], side: string}|null null for a column that does not exist
	 */
	public static function ids( array $t, $column ) {
		$s = function ( array $ids ) { return array_values( array_map( 'strval', $ids ) ); };
		switch ( $column ) {
			case 'push': return [ 'ids' => $s( $t['push'] ), 'side' => 'local' ];
			case 'insert': case 'local-only': return [ 'ids' => $s( $t['insert'] ), 'side' => 'local' ];
			case 'delete': return [ 'ids' => $s( $t['delete'] ), 'side' => 'remote' ];
			case 'remote-wins': case 'differs': return [ 'ids' => $s( $t['conflict'] ), 'side' => 'local' ];
			case 'kept-remote': case 'remote-only': return [ 'ids' => $s( array_diff( $t['kept'], $t['conflict'] ) ), 'side' => 'remote' ];
		}
		return null;
	}

	/** @return string[] */
	public static function label_columns( $table, $prefix ) {
		$bare = strpos( $table, $prefix ) === 0 ? substr( $table, strlen( $prefix ) ) : $table;
		return self::LABELS[ $bare ] ?? [];
	}

	public static function label( array $row, array $columns ) {
		$out = [];
		foreach ( $columns as $c ) if ( isset( $row[ $c ] ) && (string) $row[ $c ] !== '' ) $out[] = mb_strimwidth( (string) $row[ $c ], 0, 80, '…' );
		return implode( ' ', $out );
	}

	/** /dump returns rows with a key above 'from': a numeric key's row comes back from the one before it. */
	public static function cursor_before( $id ) {
		$id = (string) $id;
		return ctype_digit( $id ) ? (int) $id - 1 : null;
	}
}
