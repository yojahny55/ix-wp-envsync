<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class IXES_Baseline {
	private $path; private $pdo = null; private $json = null; private $json_path;

	public function __construct( $path ) {
		$this->path = $path;
		$this->json_path = $path . '.json';
		if ( class_exists( 'PDO' ) && in_array( 'sqlite', PDO::getAvailableDrivers(), true ) ) {
			$this->pdo = new PDO( 'sqlite:' . $path );
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
			$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS rows (tbl TEXT, pk TEXT, hash TEXT, PRIMARY KEY (tbl, pk))' );
			$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS files (path TEXT PRIMARY KEY, hash TEXT)' );
			$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)' );
		} else {
			$this->json = file_exists( $this->json_path ) ? json_decode( file_get_contents( $this->json_path ), true ) : null;
			if ( ! is_array( $this->json ) ) $this->json = [ 'rows' => [], 'files' => [], 'meta' => [] ];
		}
	}

	private function save_json() { file_put_contents( $this->json_path, json_encode( $this->json ) ); }

	public function exists() { return $this->meta( 'created_at' ) !== null; }

	public function reset() {
		if ( $this->pdo ) {
			$this->pdo->exec( 'DELETE FROM rows; DELETE FROM files; DELETE FROM meta;' );
		} else {
			$this->json = [ 'rows' => [], 'files' => [], 'meta' => [] ];
			$this->save_json();
		}
	}

	/** Marks the baseline as complete. Called once the pull has committed tables and finished files. */
	public function commit() { $this->meta( 'created_at', time() ); }

	public function delete_table( $table ) {
		if ( $this->pdo ) {
			$st = $this->pdo->prepare( 'DELETE FROM rows WHERE tbl = ?' ); $st->execute( [ $table ] );
		} else {
			unset( $this->json['rows'][ $table ] ); $this->save_json();
		}
	}

	public function delete_file( $path ) {
		if ( $this->pdo ) { $st = $this->pdo->prepare( 'DELETE FROM files WHERE path = ?' ); $st->execute( [ $path ] ); }
		else { unset( $this->json['files'][ $path ] ); $this->save_json(); }
	}

	/** "2026-09-12", "2026-09-12 · partial 2026-09-22 (themes)" or "-" for CLI and admin tables. */
	public function baseline_label() {
		$c = $this->meta( 'created_at' ); $p = $this->meta( 'partial_at' );
		if ( ! $c && ! $p ) return '-';
		$out = $c ? wp_date( 'Y-m-d H:i', (int) $c ) : 'none';
		if ( $p && ( ! $c || $p > $c ) ) $out .= ' · partial ' . wp_date( 'Y-m-d H:i', (int) $p ) . ' (' . $this->meta( 'partial_scope' ) . ')';
		return $out;
	}

	public function write_rows( $table, array $map ) {
		if ( $this->pdo ) {
			$this->pdo->beginTransaction();
			$st = $this->pdo->prepare( 'INSERT OR REPLACE INTO rows (tbl, pk, hash) VALUES (?, ?, ?)' );
			foreach ( $map as $pk => $h ) $st->execute( [ $table, (string) $pk, $h ] );
			$this->pdo->commit();
		} else {
			if ( ! isset( $this->json['rows'][ $table ] ) ) $this->json['rows'][ $table ] = [];
			foreach ( $map as $pk => $h ) $this->json['rows'][ $table ][ (string) $pk ] = $h;
			$this->save_json();
		}
	}

	public function rows( $table ) {
		$out = [];
		if ( $this->pdo ) {
			$st = $this->pdo->prepare( 'SELECT pk, hash FROM rows WHERE tbl = ?' );
			$st->execute( [ $table ] );
			foreach ( $st as $r ) $out[ self::key( $r['pk'] ) ] = $r['hash'];
		} else {
			foreach ( ( $this->json['rows'][ $table ] ?? [] ) as $pk => $h ) $out[ self::key( $pk ) ] = $h;
		}
		ksort( $out );
		return $out;
	}

	private static function key( $pk ) { return ctype_digit( (string) $pk ) ? (int) $pk : (string) $pk; }

	public function write_files( array $map ) {
		if ( $this->pdo ) {
			$this->pdo->beginTransaction();
			$st = $this->pdo->prepare( 'INSERT OR REPLACE INTO files (path, hash) VALUES (?, ?)' );
			foreach ( $map as $p => $h ) $st->execute( [ $p, $h ] );
			$this->pdo->commit();
		} else {
			foreach ( $map as $p => $h ) $this->json['files'][ $p ] = $h;
			$this->save_json();
		}
	}

	public function files() {
		if ( $this->pdo ) {
			$out = [];
			foreach ( $this->pdo->query( 'SELECT path, hash FROM files' ) as $r ) $out[ $r['path'] ] = $r['hash'];
			return $out;
		}
		return $this->json['files'];
	}

	public function tables() {
		if ( $this->pdo ) return $this->pdo->query( 'SELECT DISTINCT tbl FROM rows ORDER BY tbl' )->fetchAll( PDO::FETCH_COLUMN );
		return array_keys( $this->json['rows'] );
	}

	public function meta( $k, $v = null ) {
		if ( func_num_args() === 1 ) {
			if ( $this->pdo ) {
				$st = $this->pdo->prepare( 'SELECT v FROM meta WHERE k = ?' ); $st->execute( [ $k ] );
				$r = $st->fetchColumn();
				return $r === false ? null : ( ctype_digit( (string) $r ) ? (int) $r : $r );
			}
			return $this->json['meta'][ $k ] ?? null;
		}
		if ( $this->pdo ) {
			$st = $this->pdo->prepare( 'INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)' ); $st->execute( [ $k, (string) $v ] );
		} else {
			$this->json['meta'][ $k ] = $v; $this->save_json();
		}
		return $v;
	}
}
