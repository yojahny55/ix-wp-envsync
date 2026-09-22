<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Many small files in one request. A per-file round trip (~150-300 ms through a CDN) dominates
 * a first deploy of thousands of tiny plugin files; one batch of a few MB costs a single trip.
 * Wire format: <json item list>\n<bytes of every item, back to back>.
 */
class IXES_Batch {
	const SMALL = 524288;     // files above this go through the chunked single-file path
	const MAX_BYTES = 4194304;
	const MAX_ITEMS = 400;

	/**
	 * @param array $sizes rel => bytes, in send order
	 * @return array [ batches: list of rel lists, large: rel list ]
	 */
	public static function pack( array $sizes, $max_bytes = self::MAX_BYTES, $max_items = self::MAX_ITEMS, $small = self::SMALL ) {
		$batches = []; $large = []; $cur = []; $bytes = 0;
		foreach ( $sizes as $rel => $n ) {
			if ( $n > $small ) { $large[] = $rel; continue; }
			if ( $cur && ( $bytes + $n > $max_bytes || count( $cur ) >= $max_items ) ) { $batches[] = $cur; $cur = []; $bytes = 0; }
			$cur[] = $rel; $bytes += $n;
		}
		if ( $cur ) $batches[] = $cur;
		return [ 'batches' => $batches, 'large' => $large ];
	}

	/** @param array $items list of [ meta array (path, sha256, ...), bytes string ] */
	public static function encode( array $items ) {
		$meta = []; $body = '';
		foreach ( $items as $it ) { $meta[] = $it[0] + [ 'size' => strlen( $it[1] ) ]; $body .= $it[1]; }
		return wp_json_encode( $meta ) . "\n" . $body;
	}

	/** @return array|WP_Error list of [ meta, bytes ] */
	public static function decode( $raw ) {
		$nl = strpos( (string) $raw, "\n" );
		if ( $nl === false ) return new WP_Error( 'bad_batch', 'batch has no item list', [ 'status' => 400 ] );
		$meta = json_decode( substr( $raw, 0, $nl ), true );
		if ( ! is_array( $meta ) ) return new WP_Error( 'bad_batch', 'batch item list unreadable', [ 'status' => 400 ] );
		$out = []; $off = $nl + 1;
		foreach ( $meta as $m ) {
			$n = (int) ( $m['size'] ?? -1 );
			if ( $n < 0 || $off + $n > strlen( $raw ) || ! isset( $m['path'] ) ) return new WP_Error( 'bad_batch', 'batch is truncated', [ 'status' => 400 ] );
			$out[] = [ $m, (string) substr( $raw, $off, $n ) ];
			$off += $n;
		}
		if ( $off !== strlen( $raw ) ) return new WP_Error( 'bad_batch', 'batch has trailing bytes', [ 'status' => 400 ] );
		return $out;
	}
}
