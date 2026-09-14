<?php
defined('ABSPATH') || exit;

/**
 * VPO_C2PA — xoá dấu vết AI (C2PA / Content Credentials) + XMP provenance khỏi ảnh.
 *
 * Xử lý NHỊ PHÂN trực tiếp (không cần Imagick) — như PixClean nhưng bằng PHP:
 *   - JPEG : bỏ segment APP11 (0xFFEB, chứa JUMBF/C2PA) + APP1 (0xFFE1) nếu là XMP.
 *   - PNG  : bỏ chunk `caBX` (C2PA) + iTXt/tEXt/zTXt có keyword XMP/C2PA.
 *   - WebP : bỏ chunk 'C2PA' + 'XMP ' trong RIFF (sửa lại kích thước RIFF).
 *
 * CHỈ đụng phần dấu vết AI/XMP — KHÔNG đụng pixel, ICC, EXIF (strip_meta lo EXIF riêng).
 */
class VPO_C2PA {

	/** Xoá dấu vết AI khỏi 1 file. Trả về true nếu có thay đổi & đã ghi lại. */
	public static function strip( $file ) {
		if ( ! is_string( $file ) || ! is_file( $file ) || ! is_writable( $file ) ) {
			return false;
		}
		$data = @file_get_contents( $file );
		if ( $data === false || strlen( $data ) < 12 ) {
			return false;
		}

		$out = null;
		if ( substr( $data, 0, 2 ) === "\xFF\xD8" ) {
			$out = self::jpeg( $data );
		} elseif ( substr( $data, 0, 8 ) === "\x89PNG\r\n\x1a\n" ) {
			$out = self::png( $data );
		} elseif ( substr( $data, 0, 4 ) === 'RIFF' && substr( $data, 8, 4 ) === 'WEBP' ) {
			$out = self::webp( $data );
		}

		if ( is_string( $out ) && $out !== $data && strlen( $out ) > 0 ) {
			return (bool) @file_put_contents( $file, $out, LOCK_EX );
		}
		return false;
	}

	/* ---------- JPEG ---------- */
	private static function jpeg( $d ) {
		$len = strlen( $d );
		$i   = 2;
		$out = "\xFF\xD8";
		$changed = false;
		while ( $i + 2 <= $len ) {
			if ( $d[ $i ] !== "\xFF" ) { $out .= substr( $d, $i ); break; } // cấu trúc lạ → copy phần còn lại
			$marker = ord( $d[ $i + 1 ] );
			if ( $marker === 0xDA ) { $out .= substr( $d, $i ); break; }     // SOS → sau đây là dữ liệu nén
			if ( ( $marker >= 0xD0 && $marker <= 0xD9 ) || $marker === 0x01 ) { // marker không payload
				$out .= substr( $d, $i, 2 ); $i += 2; continue;
			}
			if ( $i + 4 > $len ) { $out .= substr( $d, $i ); break; }
			$seglen = ( ord( $d[ $i + 2 ] ) << 8 ) | ord( $d[ $i + 3 ] );
			if ( $seglen < 2 || $i + 2 + $seglen > $len ) { $out .= substr( $d, $i ); break; }
			$seg  = substr( $d, $i, 2 + $seglen );
			$drop = false;
			if ( $marker === 0xEB ) {                       // APP11 → JUMBF/C2PA
				$drop = true;
			} elseif ( $marker === 0xE1 ) {                 // APP1 → bỏ nếu là XMP
				$head = substr( $seg, 4, 40 );
				if ( stripos( $head, 'ns.adobe.com/xap/1.0/' ) !== false
					|| stripos( $head, 'ns.adobe.com/xmp/' ) !== false ) {
					$drop = true;
				}
			}
			if ( $drop ) { $changed = true; } else { $out .= $seg; }
			$i += 2 + $seglen;
		}
		return $changed ? $out : $d;
	}

	/* ---------- PNG ---------- */
	private static function png( $d ) {
		$len = strlen( $d );
		$i   = 8;
		$out = substr( $d, 0, 8 );
		$changed = false;
		while ( $i + 8 <= $len ) {
			$clen  = unpack( 'N', substr( $d, $i, 4 ) )[1];
			$type  = substr( $d, $i + 4, 4 );
			$total = 12 + $clen; // length(4) + type(4) + data + crc(4)
			if ( $clen < 0 || $i + $total > $len ) { $out .= substr( $d, $i ); break; }
			$chunk = substr( $d, $i, $total );
			$drop  = false;
			if ( $type === 'caBX' ) {                        // C2PA
				$drop = true;
			} elseif ( $type === 'iTXt' || $type === 'tEXt' || $type === 'zTXt' ) {
				$kw  = substr( $chunk, 8, 80 );
				$nul = strpos( $kw, "\x00" );
				$kw  = ( $nul !== false ) ? substr( $kw, 0, $nul ) : $kw;
				if ( stripos( $kw, 'xmp' ) !== false || stripos( $kw, 'c2pa' ) !== false ) {
					$drop = true;
				}
			}
			if ( $drop ) { $changed = true; } else { $out .= $chunk; }
			$i += $total;
			if ( $type === 'IEND' ) { break; }
		}
		return $changed ? $out : $d;
	}

	/* ---------- WebP ---------- */
	private static function webp( $d ) {
		$len = strlen( $d );
		$i   = 12;
		$out = substr( $d, 0, 12 );
		$changed = false;
		while ( $i + 8 <= $len ) {
			$fourcc = substr( $d, $i, 4 );
			$clen   = unpack( 'V', substr( $d, $i + 4, 4 ) )[1];
			$pad    = $clen & 1;
			$total  = 8 + $clen + $pad;
			if ( $i + 8 + $clen > $len ) { $out .= substr( $d, $i ); break; }
			$chunk = substr( $d, $i, min( $total, $len - $i ) );
			if ( $fourcc === 'C2PA' || $fourcc === 'XMP ' ) {
				$changed = true;
			} else {
				$out .= $chunk;
			}
			$i += $total;
		}
		if ( ! $changed ) { return $d; }
		// Sửa lại RIFF size = tổng byte - 8.
		return substr( $out, 0, 4 ) . pack( 'V', strlen( $out ) - 8 ) . substr( $out, 8 );
	}
}
