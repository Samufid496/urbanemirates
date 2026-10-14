<?php
/**
 * A small PDF writer for CVs: A4 pages, Helvetica / Helvetica-Bold text with word wrapping,
 * filled rectangles, lines and one JPEG photo. Text stays real text (selectable, readable by
 * applicant-tracking systems). Written in-house: no libraries.
 *
 * Coordinates are in points from the TOP-LEFT of the page (converted to PDF's bottom-left).
 * Text uses the built-in Windows-1252 character set; characters outside it become "?".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_Pdf {

	const W = 595.28; // A4 width in points.
	const H = 841.89; // A4 height in points.

	private $pages   = array();
	private $current = -1;
	private $images  = array();

	/** Helvetica glyph widths (1/1000 em) for bytes 32–126. */
	private static $w_regular = array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 );

	/** Helvetica-Bold glyph widths for bytes 32–126. */
	private static $w_bold = array( 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 );

	public function add_page() {
		$this->pages[] = '';
		$this->current = count( $this->pages ) - 1;
	}

	public function page_count() {
		return count( $this->pages );
	}

	private function out( $s ) {
		$this->pages[ $this->current ] .= $s . "\n";
	}

	private static function rgb( $hex ) {
		$hex = ltrim( $hex, '#' );
		return sprintf( '%.3F %.3F %.3F', hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/**
	 * UTF-8 → Windows-1252 bytes, with a few friendly replacements first.
	 */
	public static function encode( $text ) {
		$text = str_replace( array( '₹', "\t", "\r" ), array( 'Rs ', ' ', '' ), (string) $text );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = @mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $out ) {
				return $out;
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $out ) {
				return $out;
			}
		}
		return preg_replace( '/[^\x20-\x7E\n]/', '?', $text );
	}

	/**
	 * Width in points of an already-encoded string.
	 */
	public static function width( $bytes, $bold, $size ) {
		$table = $bold ? self::$w_bold : self::$w_regular;
		$sum   = 0;
		$len   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = ord( $bytes[ $i ] );
			if ( $c >= 32 && $c <= 126 ) {
				$sum += $table[ $c - 32 ];
			} elseif ( 0x95 === $c ) {
				$sum += 350; // Bullet.
			} elseif ( 0x96 === $c ) {
				$sum += 556; // En dash.
			} elseif ( 0x97 === $c ) {
				$sum += 1000; // Em dash.
			} elseif ( $c >= 0x91 && $c <= 0x94 ) {
				$sum += $bold ? 278 : 222; // Curly quotes.
			} else {
				$sum += 556;
			}
		}
		return $sum * $size / 1000;
	}

	/**
	 * Split UTF-8 text into lines (encoded) that fit $max_width. Keeps explicit line breaks.
	 */
	public static function wrap( $text, $bold, $size, $max_width ) {
		$lines = array();
		foreach ( explode( "\n", self::encode( $text ) ) as $para ) {
			$words = preg_split( '/ +/', trim( $para ) );
			$line  = '';
			foreach ( $words as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( self::width( $try, $bold, $size ) <= $max_width || '' === $line ) {
					$line = $try;
					// A single word longer than the line: hard-break it.
					while ( self::width( $line, $bold, $size ) > $max_width && strlen( $line ) > 1 ) {
						$cut = strlen( $line ) - 1;
						while ( $cut > 1 && self::width( substr( $line, 0, $cut ), $bold, $size ) > $max_width ) {
							$cut--;
						}
						$lines[] = substr( $line, 0, $cut );
						$line    = substr( $line, $cut );
					}
				} else {
					$lines[] = $line;
					$line    = $word;
				}
			}
			$lines[] = $line;
		}
		return $lines;
	}

	private static function escape( $bytes ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $bytes );
	}

	/**
	 * Draw one line of already-encoded text with its baseline at $y (from top).
	 */
	public function text_line( $x, $y, $bytes, $bold, $size, $color = '#121A2E' ) {
		$this->out( sprintf( 'BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET', $bold ? 'F2' : 'F1', $size, self::rgb( $color ), $x, self::H - $y, self::escape( $bytes ) ) );
	}

	/**
	 * Draw UTF-8 text on one line (no wrapping). Returns the width drawn.
	 */
	public function text( $x, $y, $text, $bold, $size, $color = '#121A2E' ) {
		$bytes = self::encode( $text );
		$this->text_line( $x, $y, $bytes, $bold, $size, $color );
		return self::width( $bytes, $bold, $size );
	}

	public function rect( $x, $y, $w, $h, $color ) {
		$this->out( sprintf( '%s rg %.2F %.2F %.2F %.2F re f', self::rgb( $color ), $x, self::H - $y - $h, $w, $h ) );
	}

	public function line( $x1, $y1, $x2, $y2, $color, $width = 0.75 ) {
		$this->out( sprintf( '%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb( $color ), $width, $x1, self::H - $y1, $x2, self::H - $y2 ) );
	}

	/**
	 * Place a JPEG (raw bytes). Returns false if the bytes are not a readable JPEG.
	 */
	public function jpeg( $bytes, $x, $y, $w, $h ) {
		$info = self::jpeg_info( $bytes );
		if ( ! $info ) {
			return false;
		}
		$name           = 'Im' . ( count( $this->images ) + 1 );
		$this->images[] = array(
			'name'  => $name,
			'data'  => $bytes,
			'info'  => $info,
		);
		$this->out( sprintf( 'q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, self::H - $y - $h, $name ) );
		return true;
	}

	/**
	 * Read width, height and colour channels from a JPEG's frame header.
	 */
	private static function jpeg_info( $bytes ) {
		if ( strlen( $bytes ) < 4 || "\xFF\xD8" !== substr( $bytes, 0, 2 ) ) {
			return false;
		}
		$pos = 2;
		$len = strlen( $bytes );
		while ( $pos + 9 < $len ) {
			if ( "\xFF" !== $bytes[ $pos ] ) {
				return false;
			}
			$marker = ord( $bytes[ $pos + 1 ] );
			$size   = unpack( 'n', substr( $bytes, $pos + 2, 2 ) )[1];
			// SOF0–SOF15 except DHT (C4), JPG (C8) and DAC (CC).
			if ( $marker >= 0xC0 && $marker <= 0xCF && ! in_array( $marker, array( 0xC4, 0xC8, 0xCC ), true ) ) {
				$h = unpack( 'n', substr( $bytes, $pos + 5, 2 ) )[1];
				$w = unpack( 'n', substr( $bytes, $pos + 7, 2 ) )[1];
				$c = ord( $bytes[ $pos + 9 ] );
				return array( 'w' => $w, 'h' => $h, 'channels' => $c );
			}
			$pos += 2 + $size;
		}
		return false;
	}

	/**
	 * Build the PDF file and return its bytes.
	 */
	public function output( $title = 'CV' ) {
		$objects = array();
		$n_pages = count( $this->pages );

		// 1 catalog, 2 page tree, 3 Helvetica, 4 Helvetica-Bold, 5 info, then images, then page + content pairs.
		$first_image = 6;
		$first_page  = $first_image + count( $this->images );

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids       = array();
		for ( $i = 0; $i < $n_pages; $i++ ) {
			$kids[] = ( $first_page + $i * 2 ) . ' 0 R';
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $n_pages . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[5] = '<< /Title (' . self::escape( self::encode( $title ) ) . ') /Producer (Urban Emirates) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';

		$xobjects = '';
		foreach ( $this->images as $i => $img ) {
			$num    = $first_image + $i;
			$space  = 1 === $img['info']['channels'] ? '/DeviceGray' : ( 4 === $img['info']['channels'] ? '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]' : '/DeviceRGB' );
			$objects[ $num ] = array(
				'dict'   => '<< /Type /XObject /Subtype /Image /Width ' . $img['info']['w'] . ' /Height ' . $img['info']['h'] . ' /ColorSpace ' . $space . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $img['data'] ) . ' >>',
				'stream' => $img['data'],
			);
			$xobjects .= '/' . $img['name'] . ' ' . $num . ' 0 R ';
		}
		$resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ( $xobjects ? ' /XObject << ' . $xobjects . '>>' : '' ) . ' >>';

		foreach ( $this->pages as $i => $content ) {
			$page_num                 = $first_page + $i * 2;
			$objects[ $page_num ]     = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources ' . $resources . ' /Contents ' . ( $page_num + 1 ) . ' 0 R >>';
			$compressed               = function_exists( 'gzcompress' ) ? gzcompress( $content ) : false;
			$objects[ $page_num + 1 ] = array(
				'dict'   => '<< /Length ' . strlen( false !== $compressed ? $compressed : $content ) . ( false !== $compressed ? ' /Filter /FlateDecode' : '' ) . ' >>',
				'stream' => false !== $compressed ? $compressed : $content,
			);
		}

		ksort( $objects );
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $num => $obj ) {
			$offsets[ $num ] = strlen( $pdf );
			$pdf            .= $num . " 0 obj\n";
			if ( is_array( $obj ) ) {
				$pdf .= $obj['dict'] . "\nstream\n" . $obj['stream'] . "\nendstream";
			} else {
				$pdf .= $obj;
			}
			$pdf .= "\nendobj\n";
		}
		$count = max( array_keys( $objects ) ) + 1;
		$xref  = strlen( $pdf );
		$pdf  .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
		for ( $i = 1; $i < $count; $i++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", isset( $offsets[ $i ] ) ? $offsets[ $i ] : 0 );
		}
		$pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}
}
