<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Many small files in one request. A per-file round trip (~150-300 ms through a CDN) dominates
 * a first deploy of thousands of tiny plugin files; one batch of a few MB costs a single trip.
 * Wire format: <json item list>\n<bytes of every item, back to back>.
 * From 0.8.0 both directions deflate it: PHP/JS text shrinks ~4x, and a host firewall that pattern-matches
 * plain bodies (see IXES_Rest::unpack_step) never sees the code inside.
 */
class IXES_Batch {
	const SMALL = 524288;     // files above this go through the chunked single-file path
	const MAX_BYTES = 4194304;
	const MAX_ITEMS = 400;
	const MIN_BYTES = 262144;
	const INFLATE_MAX = 67108864; // same cap as IXES_Rest::PACKED_MAX
	/** Codes a retry of the same batch can cure: bytes damaged or cut on the way. */
	const RETRY = [ 'bad_batch', 'checksum' ];

	/** Byte budget per batch: small enough that $parallel requests all get work, never above MAX_BYTES. */
	public static function budget( $total, $parallel ) {
		$want = (int) ceil( max( 0, (int) $total ) / ( 2 * max( 1, (int) $parallel ) ) );
		return max( self::MIN_BYTES, min( self::MAX_BYTES, $want ) );
	}

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

	/**
	 * A /file/batch answer, checked before anything touches the disk: exactly the paths asked for, in order,
	 * and every file's bytes matching the sha256 the remote hashed them with. An item with 'err' carries no bytes.
	 * @return array|WP_Error list of [ meta, bytes ]
	 */
	public static function open( $raw, $enc, array $want ) {
		if ( $enc === 'deflate' ) {
			$raw = function_exists( 'gzinflate' ) ? @gzinflate( (string) $raw, self::INFLATE_MAX ) : false;
			if ( $raw === false ) return new WP_Error( 'bad_batch', 'batch does not inflate' );
		} elseif ( $enc !== '' && $enc !== 'identity' ) {
			return new WP_Error( 'bad_batch', "unknown batch encoding {$enc}" );
		}
		$items = self::decode( $raw );
		if ( is_wp_error( $items ) ) return new WP_Error( 'bad_batch', $items->get_error_message() );
		// a remote answers for what it was asked, never for a path it picked itself
		if ( array_map( function ( $it ) { return (string) $it[0]['path']; }, $items ) !== array_map( 'strval', array_values( $want ) ) ) {
			return new WP_Error( 'bad_path', 'batch answer does not match the paths asked for' );
		}
		foreach ( $items as $it ) {
			if ( isset( $it[0]['err'] ) ) continue;
			if ( ! hash_equals( (string) ( $it[0]['sha256'] ?? '' ), hash( 'sha256', $it[1] ) ) ) return new WP_Error( 'checksum', "checksum mismatch {$it[0]['path']} in batch" );
		}
		return $items;
	}
}
