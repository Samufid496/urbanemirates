<?php
/**
 * Turns one CV's data into: an HTML preview, a PDF, a Word (.docx) file and plain text.
 * Three templates — Classic, Modern, Gulf (with photo and personal details).
 * All formats share the same section order so what people preview is what they download.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KJ_CV_Render {

	const BLUE = '#2357E8';
	const INK  = '#121A2E';
	const GREY = '#5E6577';
	const LINE = '#D9DCE3';

	public static function templates() {
		return array(
			'classic' => 'Classic',
			'modern'  => 'Modern',
			'gulf'    => 'Gulf',
		);
	}

	public static function visa_labels() {
		return array(
			'residence_transferable' => 'Residence visa (transferable)',
			'residence'              => 'Residence visa',
			'visit'                  => 'Visit visa',
			'need'                   => 'Will need an employment visa',
			'citizen'                => 'GCC national',
		);
	}

	public static function notice_labels() {
		return array(
			'immediate' => 'Available immediately',
			'2w'        => '2 weeks',
			'1m'        => '1 month',
			'2m'        => '2 months',
			'3m'        => '3 months',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Shared content model                                                */
	/* ------------------------------------------------------------------ */

	private static function month( $ym ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $ym, $m ) ) {
			return (string) $ym;
		}
		return gmdate( 'M Y', gmmktime( 0, 0, 0, (int) $m[2], 1, (int) $m[1] ) );
	}

	private static function dates( $e ) {
		$start = self::month( $e['start'] );
		$end   = ! empty( $e['current'] ) ? 'Present' : self::month( $e['end'] );
		return trim( $start . ( $start && $end ? ' – ' : '' ) . $end );
	}

	/**
	 * Split duties text into bullet lines.
	 */
	public static function bullets( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( preg_replace( '/^[\s\-\*•·]+/u', '', $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Everything a template prints, in order.
	 */
	public static function model( array $cv ) {
		$p       = $cv['personal'];
		$visa    = self::visa_labels();
		$notice  = self::notice_labels();
		$place   = trim( $p['city'] . ( $p['city'] && $p['country'] ? ', ' : '' ) . $p['country'] );
		$contact = array_values( array_filter( array( $p['phone'], $p['email'], $place ) ) );

		$details = array_filter(
			array(
				'Nationality'     => $p['nationality'],
				'Date of birth'   => $p['dob'] ? self::dob( $p['dob'] ) : '',
				'Current location'=> $place,
				'Visa status'     => isset( $visa[ $p['visa_status'] ] ) ? $visa[ $p['visa_status'] ] : '',
				'Notice period'   => isset( $notice[ $p['notice'] ] ) ? $notice[ $p['notice'] ] : '',
				'Driving licence' => $p['driving_licence'],
			)
		);

		$experience = array();
		foreach ( $cv['experience'] as $e ) {
			if ( '' === $e['title'] && '' === $e['company'] ) {
				continue;
			}
			$experience[] = array(
				'title'   => $e['title'],
				'meta'    => implode( ' · ', array_filter( array( $e['company'], $e['city'] ) ) ),
				'dates'   => self::dates( $e ),
				'bullets' => self::bullets( $e['duties'] ),
			);
		}

		$education = array();
		foreach ( $cv['education'] as $e ) {
			if ( '' === $e['degree'] && '' === $e['school'] ) {
				continue;
			}
			$education[] = array(
				'title' => $e['degree'],
				'meta'  => $e['school'],
				'dates' => $e['year'],
			);
		}

		$licences = array();
		foreach ( $cv['licences'] as $l ) {
			if ( '' !== $l['name'] ) {
				$licences[] = trim( $l['name'] . ( $l['detail'] ? ' — ' . $l['detail'] : '' ) );
			}
		}

		return array(
			'name'       => $p['name'] ? $p['name'] : 'Your Name',
			'headline'   => $p['headline'] ? $p['headline'] : $cv['target']['title'],
			'contact'    => $contact,
			'details'    => $details,
			'photo'      => $p['photo'],
			'summary'    => $cv['summary'],
			'experience' => $experience,
			'education'  => $education,
			'skills'     => $cv['skills'],
			'languages'  => $cv['languages'],
			'licences'   => $licences,
		);
	}

	private static function dob( $date ) {
		$t = strtotime( $date );
		return $t ? gmdate( 'j M Y', $t ) : $date;
	}

	/* ------------------------------------------------------------------ */
	/* HTML preview                                                        */
	/* ------------------------------------------------------------------ */

	public static function html( array $cv, $template ) {
		$m = self::model( $cv );
		$t = array_key_exists( $template, self::templates() ) ? $template : 'classic';

		$out  = '<div class="cvp cvp-' . esc_attr( $t ) . '">';
		$out .= '<header class="cvp-head">';
		if ( 'gulf' === $t && $m['photo'] ) {
			$out .= '<img class="cvp-photo" src="' . esc_attr( $m['photo'] ) . '" alt="">';
		}
		$out .= '<div class="cvp-id"><h1 class="cvp-name">' . esc_html( $m['name'] ) . '</h1>';
		if ( $m['headline'] ) {
			$out .= '<p class="cvp-headline">' . esc_html( $m['headline'] ) . '</p>';
		}
		if ( $m['contact'] ) {
			$out .= '<p class="cvp-contact">' . esc_html( implode( '  ·  ', $m['contact'] ) ) . '</p>';
		}
		$out .= '</div></header>';

		if ( 'gulf' === $t && $m['details'] ) {
			$out .= '<dl class="cvp-details">';
			foreach ( $m['details'] as $k => $v ) {
				$out .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
			}
			$out .= '</dl>';
		}

		if ( $m['summary'] ) {
			$out .= self::html_section( 'Profile', '<p>' . nl2br( esc_html( $m['summary'] ) ) . '</p>' );
		}
		if ( $m['experience'] ) {
			$out .= self::html_section( 'Experience', self::html_items( $m['experience'] ) );
		}
		if ( $m['education'] ) {
			$out .= self::html_section( 'Education', self::html_items( $m['education'] ) );
		}
		if ( $m['licences'] ) {
			$out .= self::html_section( 'Licences & certificates', '<ul>' . implode( '', array_map( function ( $l ) { return '<li>' . esc_html( $l ) . '</li>'; }, $m['licences'] ) ) . '</ul>' );
		}
		if ( $m['skills'] ) {
			$out .= self::html_section( 'Skills', '<p>' . esc_html( $m['skills'] ) . '</p>' );
		}
		if ( $m['languages'] ) {
			$out .= self::html_section( 'Languages', '<p>' . esc_html( $m['languages'] ) . '</p>' );
		}
		if ( 'gulf' !== $t && $m['details'] ) {
			$bits = array();
			foreach ( $m['details'] as $k => $v ) {
				if ( 'Current location' !== $k ) {
					$bits[] = $k . ': ' . $v;
				}
			}
			if ( $bits ) {
				$out .= self::html_section( 'Personal details', '<p>' . esc_html( implode( '  ·  ', $bits ) ) . '</p>' );
			}
		}
		return $out . '</div>';
	}

	private static function html_section( $title, $body ) {
		return '<section class="cvp-section"><h2>' . esc_html( $title ) . '</h2>' . $body . '</section>';
	}

	private static function html_items( array $items ) {
		$out = '';
		foreach ( $items as $it ) {
			$out .= '<div class="cvp-item"><div class="cvp-item-head"><strong>' . esc_html( $it['title'] ) . '</strong><span>' . esc_html( $it['dates'] ) . '</span></div>';
			if ( $it['meta'] ) {
				$out .= '<div class="cvp-item-meta">' . esc_html( $it['meta'] ) . '</div>';
			}
			if ( ! empty( $it['bullets'] ) ) {
				$out .= '<ul>' . implode( '', array_map( function ( $b ) { return '<li>' . esc_html( $b ) . '</li>'; }, $it['bullets'] ) ) . '</ul>';
			}
			$out .= '</div>';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Plain text                                                          */
	/* ------------------------------------------------------------------ */

	public static function text( array $cv ) {
		$m   = self::model( $cv );
		$out = array( strtoupper( $m['name'] ) );
		if ( $m['headline'] ) {
			$out[] = $m['headline'];
		}
		if ( $m['contact'] ) {
			$out[] = implode( ' | ', $m['contact'] );
		}
		$section = function ( $title ) use ( &$out ) {
			$out[] = '';
			$out[] = strtoupper( $title );
			$out[] = str_repeat( '-', strlen( $title ) );
		};
		if ( $m['summary'] ) {
			$section( 'Profile' );
			$out[] = $m['summary'];
		}
		foreach ( array( 'experience' => 'Experience', 'education' => 'Education' ) as $key => $title ) {
			if ( ! $m[ $key ] ) {
				continue;
			}
			$section( $title );
			foreach ( $m[ $key ] as $it ) {
				$out[] = $it['title'] . ( $it['dates'] ? ' (' . $it['dates'] . ')' : '' );
				if ( $it['meta'] ) {
					$out[] = $it['meta'];
				}
				foreach ( isset( $it['bullets'] ) ? $it['bullets'] : array() as $b ) {
					$out[] = '- ' . $b;
				}
				$out[] = '';
			}
			array_pop( $out );
		}
		if ( $m['licences'] ) {
			$section( 'Licences & certificates' );
			foreach ( $m['licences'] as $l ) {
				$out[] = '- ' . $l;
			}
		}
		if ( $m['skills'] ) {
			$section( 'Skills' );
			$out[] = $m['skills'];
		}
		if ( $m['languages'] ) {
			$section( 'Languages' );
			$out[] = $m['languages'];
		}
		if ( $m['details'] ) {
			$section( 'Personal details' );
			foreach ( $m['details'] as $k => $v ) {
				$out[] = $k . ': ' . $v;
			}
		}
		return implode( "\r\n", $out ) . "\r\n";
	}

	/* ------------------------------------------------------------------ */
	/* PDF                                                                 */
	/* ------------------------------------------------------------------ */

	public static function pdf( array $cv, $template ) {
		$m   = self::model( $cv );
		$t   = array_key_exists( $template, self::templates() ) ? $template : 'classic';
		$pdf = new KJ_Pdf();
		$pdf->add_page();

		$left   = 50;
		$right  = KJ_Pdf::W - 50;
		$width  = $right - $left;
		$bottom = KJ_Pdf::H - 50;
		$y      = 56;
		$accent = 'classic' === $t ? self::INK : self::BLUE;

		$need = function ( $h ) use ( &$y, $pdf, $bottom ) {
			if ( $y + $h > $bottom ) {
				$pdf->add_page();
				$y = 56;
			}
		};
		$para = function ( $text, $bold, $size, $color, $indent = 0, $lead = 1.38 ) use ( &$y, $pdf, $left, $width, $need ) {
			foreach ( KJ_Pdf::wrap( $text, $bold, $size, $width - $indent ) as $line ) {
				$need( $size * $lead );
				$y += $size * $lead;
				$pdf->text_line( $left + $indent, $y, $line, $bold, $size, $color );
			}
		};
		$heading = function ( $title ) use ( &$y, $pdf, $left, $right, $need, $accent ) {
			$need( 40 );
			$y += 24;
			$pdf->text( $left, $y, strtoupper( $title ), true, 10, $accent );
			$y += 6;
			$pdf->line( $left, $y, $right, $y, self::LINE, 0.75 );
			$y += 2;
		};

		// Header.
		if ( 'modern' === $t ) {
			$pdf->rect( 0, 0, KJ_Pdf::W, 118, self::BLUE );
			$pdf->text( $left, 58, $m['name'], true, 24, '#FFFFFF' );
			if ( $m['headline'] ) {
				$pdf->text( $left, 80, $m['headline'], false, 12, '#FFFFFF' );
			}
			if ( $m['contact'] ) {
				$pdf->text( $left, 100, implode( '   ·   ', $m['contact'] ), false, 9, '#DCE6FF' );
			}
			$y = 124;
		} else {
			$text_right = $right;
			if ( 'gulf' === $t && $m['photo'] && preg_match( '#^data:image/jpeg;base64,(.+)$#', $m['photo'], $mm ) ) {
				$size = 78;
				if ( $pdf->jpeg( base64_decode( $mm[1] ), $right - $size, 40, $size, $size ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					$text_right = $right - $size - 16;
				}
			}
			$y = 66;
			$pdf->text( $left, $y, $m['name'], true, 24, self::INK );
			if ( $m['headline'] ) {
				$y += 20;
				$pdf->text( $left, $y, $m['headline'], false, 12, $accent );
			}
			if ( $m['contact'] ) {
				foreach ( KJ_Pdf::wrap( implode( '   ·   ', $m['contact'] ), false, 9, $text_right - $left ) as $line ) {
					$y += 15;
					$pdf->text_line( $left, $y, $line, false, 9, self::GREY );
				}
			}
			$y = max( $y, 'gulf' === $t && $text_right < $right ? 122 : $y );
		}

		// Gulf: personal details as a two-column table.
		if ( 'gulf' === $t && $m['details'] ) {
			$y += 14;
			$col = 0;
			foreach ( $m['details'] as $k => $v ) {
				$x = $left + $col * ( $width / 2 );
				if ( 0 === $col ) {
					$need( 28 );
					$y += 14;
				}
				$pdf->text( $x, $y, $k, false, 8, self::GREY );
				$pdf->text( $x, $y + 11, $v, true, 9.5, self::INK );
				if ( 1 === $col ) {
					$y += 12;
				}
				$col = 1 - $col;
			}
			if ( 1 === $col ) {
				$y += 12;
			}
		}

		if ( $m['summary'] ) {
			$heading( 'Profile' );
			$para( $m['summary'], false, 10, self::INK );
		}

		foreach ( array( 'experience' => 'Experience', 'education' => 'Education' ) as $key => $title ) {
			if ( ! $m[ $key ] ) {
				continue;
			}
			$heading( $title );
			foreach ( $m[ $key ] as $it ) {
				$need( 40 );
				$y += 18;
				$pdf->text( $left, $y, $it['title'], true, 10.5, self::INK );
				if ( $it['dates'] ) {
					$dates = KJ_Pdf::encode( $it['dates'] );
					$pdf->text_line( $right - KJ_Pdf::width( $dates, false, 9 ), $y, $dates, false, 9, self::GREY );
				}
				if ( $it['meta'] ) {
					$y += 13;
					$pdf->text( $left, $y, $it['meta'], false, 9.5, self::GREY );
				}
				foreach ( isset( $it['bullets'] ) ? $it['bullets'] : array() as $b ) {
					$lines = KJ_Pdf::wrap( $b, false, 10, $width - 14 );
					foreach ( $lines as $i => $line ) {
						$need( 14 );
						$y += 13.8;
						if ( 0 === $i ) {
							$pdf->text_line( $left + 3, $y, "\x95", false, 10, $accent );
						}
						$pdf->text_line( $left + 14, $y, $line, false, 10, self::INK );
					}
				}
				$y += 4;
			}
		}

		if ( $m['licences'] ) {
			$heading( 'Licences & certificates' );
			foreach ( $m['licences'] as $l ) {
				$lines = KJ_Pdf::wrap( $l, false, 10, $width - 14 );
				foreach ( $lines as $i => $line ) {
					$need( 14 );
					$y += 13.8;
					if ( 0 === $i ) {
						$pdf->text_line( $left + 3, $y, "\x95", false, 10, $accent );
					}
					$pdf->text_line( $left + 14, $y, $line, false, 10, self::INK );
				}
			}
		}
		if ( $m['skills'] ) {
			$heading( 'Skills' );
			$para( $m['skills'], false, 10, self::INK );
		}
		if ( $m['languages'] ) {
			$heading( 'Languages' );
			$para( $m['languages'], false, 10, self::INK );
		}
		if ( 'gulf' !== $t && $m['details'] ) {
			$bits = array();
			foreach ( $m['details'] as $k => $v ) {
				if ( 'Current location' !== $k ) {
					$bits[] = $k . ': ' . $v;
				}
			}
			if ( $bits ) {
				$heading( 'Personal details' );
				$para( implode( '   ·   ', $bits ), false, 10, self::INK );
			}
		}

		return $pdf->output( $m['name'] . ' – CV' );
	}

	/* ------------------------------------------------------------------ */
	/* Word (.docx)                                                        */
	/* ------------------------------------------------------------------ */

	public static function docx_supported() {
		return class_exists( 'ZipArchive' );
	}

	private static function x( $s ) {
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function run( $text, $opts = array() ) {
		$rpr = '';
		if ( ! empty( $opts['b'] ) ) {
			$rpr .= '<w:b/>';
		}
		if ( ! empty( $opts['color'] ) ) {
			$rpr .= '<w:color w:val="' . ltrim( $opts['color'], '#' ) . '"/>';
		}
		if ( ! empty( $opts['size'] ) ) {
			$rpr .= '<w:sz w:val="' . (int) ( $opts['size'] * 2 ) . '"/>';
		}
		if ( ! empty( $opts['caps'] ) ) {
			$rpr .= '<w:caps/>';
		}
		return '<w:r>' . ( $rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '' ) . '<w:t xml:space="preserve">' . self::x( $text ) . '</w:t></w:r>';
	}

	private static function p( $runs, $style = '', $extra_ppr = '' ) {
		$ppr = ( $style ? '<w:pStyle w:val="' . $style . '"/>' : '' ) . $extra_ppr;
		return '<w:p>' . ( $ppr ? '<w:pPr>' . $ppr . '</w:pPr>' : '' ) . $runs . '</w:p>';
	}

	public static function docx( array $cv, $template ) {
		$m      = self::model( $cv );
		$t      = array_key_exists( $template, self::templates() ) ? $template : 'classic';
		$accent = 'classic' === $t ? self::INK : self::BLUE;
		$body   = '';
		$photo  = '';

		if ( 'gulf' === $t && $m['photo'] && preg_match( '#^data:image/jpeg;base64,(.+)$#', $m['photo'], $mm ) ) {
			$photo = base64_decode( $mm[1] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$emu   = 914400; // 1 inch in EMU; photo is 1 inch square.
			$body .= '<w:p><w:pPr><w:jc w:val="right"/><w:spacing w:after="0"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $emu . '" cy="' . $emu . '"/><wp:docPr id="1" name="Photo"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="1" name="photo.jpg"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdPhoto"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $emu . '" cy="' . $emu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
		}

		$body .= self::p( self::run( $m['name'], array( 'b' => true, 'size' => 22, 'color' => 'modern' === $t ? self::BLUE : self::INK ) ), '', '<w:spacing w:after="40"/>' );
		if ( $m['headline'] ) {
			$body .= self::p( self::run( $m['headline'], array( 'size' => 12, 'color' => $accent ) ), '', '<w:spacing w:after="40"/>' );
		}
		if ( $m['contact'] ) {
			$body .= self::p( self::run( implode( '   ·   ', $m['contact'] ), array( 'size' => 9, 'color' => self::GREY ) ) );
		}

		if ( 'gulf' === $t && $m['details'] ) {
			$rows  = '';
			$pairs = array_chunk( $m['details'], 2, true );
			foreach ( $pairs as $pair ) {
				$rows .= '<w:tr>';
				foreach ( array_pad( array_keys( $pair ), 2, '' ) as $k ) {
					$cell  = $k ? self::p( self::run( $k, array( 'size' => 8, 'color' => self::GREY ) ), '', '<w:spacing w:after="0"/>' ) . self::p( self::run( $pair[ $k ], array( 'b' => true, 'size' => 9.5 ) ), '', '<w:spacing w:after="80"/>' ) : self::p( '' );
					$rows .= '<w:tc><w:tcPr><w:tcW w:w="4680" w:type="dxa"/></w:tcPr>' . $cell . '</w:tc>';
				}
				$rows .= '</w:tr>';
			}
			$body .= '<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="4680"/><w:gridCol w:w="4680"/></w:tblGrid>' . $rows . '</w:tbl>';
		}

		$heading = function ( $title ) use ( $accent ) {
			return self::p( self::run( $title, array( 'b' => true, 'size' => 10, 'color' => $accent, 'caps' => true ) ), 'UESection' );
		};
		$bullet = function ( $text ) use ( $accent ) {
			return self::p( self::run( "•\t", array( 'color' => $accent ) ) . self::run( $text ), 'UEBullet' );
		};

		if ( $m['summary'] ) {
			$body .= $heading( 'Profile' ) . self::p( self::run( $m['summary'] ) );
		}
		foreach ( array( 'experience' => 'Experience', 'education' => 'Education' ) as $key => $title ) {
			if ( ! $m[ $key ] ) {
				continue;
			}
			$body .= $heading( $title );
			foreach ( $m[ $key ] as $it ) {
				$body .= self::p( self::run( $it['title'], array( 'b' => true, 'size' => 10.5 ) ) . ( $it['dates'] ? '<w:r><w:tab/></w:r>' . self::run( $it['dates'], array( 'size' => 9, 'color' => self::GREY ) ) : '' ), 'UEItem' );
				if ( $it['meta'] ) {
					$body .= self::p( self::run( $it['meta'], array( 'size' => 9.5, 'color' => self::GREY ) ), '', '<w:spacing w:after="40"/>' );
				}
				foreach ( isset( $it['bullets'] ) ? $it['bullets'] : array() as $b ) {
					$body .= $bullet( $b );
				}
			}
		}
		if ( $m['licences'] ) {
			$body .= $heading( 'Licences & certificates' );
			foreach ( $m['licences'] as $l ) {
				$body .= $bullet( $l );
			}
		}
		if ( $m['skills'] ) {
			$body .= $heading( 'Skills' ) . self::p( self::run( $m['skills'] ) );
		}
		if ( $m['languages'] ) {
			$body .= $heading( 'Languages' ) . self::p( self::run( $m['languages'] ) );
		}
		if ( 'gulf' !== $t && $m['details'] ) {
			$bits = array();
			foreach ( $m['details'] as $k => $v ) {
				if ( 'Current location' !== $k ) {
					$bits[] = $k . ': ' . $v;
				}
			}
			if ( $bits ) {
				$body .= $heading( 'Personal details' ) . self::p( self::run( implode( '   ·   ', $bits ) ) );
			}
		}

		$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
			. '<w:body>' . $body
			. '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000" w:header="500" w:footer="500" w:gutter="0"/></w:sectPr>'
			. '</w:body></w:document>';

		$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="20"/><w:color w:val="121A2E"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="UESection"><w:name w:val="CV Section"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="280" w:after="100"/><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="2" w:color="D9DCE3"/></w:pBdr></w:pPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="UEItem"><w:name w:val="CV Item"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="120" w:after="0"/><w:tabs><w:tab w:val="right" w:pos="9900"/></w:tabs></w:pPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="UEBullet"><w:name w:val="CV Bullet"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="40"/><w:tabs><w:tab w:val="left" w:pos="280"/></w:tabs><w:ind w:left="280" w:hanging="280"/></w:pPr></w:style>'
			. '</w:styles>';

		$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. ( $photo ? '<Relationship Id="rIdPhoto" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/photo.jpg"/>' : '' )
			. '</Relationships>';

		$types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Default Extension="jpg" ContentType="image/jpeg"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '</Types>';

		$root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '</Relationships>';

		$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:title>' . self::x( $m['name'] . ' – CV' ) . '</dc:title><dc:creator>' . self::x( $m['name'] ) . '</dc:creator>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created>'
			. '</cp:coreProperties>';

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( 'cv.docx' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$zip->addFromString( '[Content_Types].xml', $types );
		$zip->addFromString( '_rels/.rels', $root_rels );
		$zip->addFromString( 'docProps/core.xml', $core );
		$zip->addFromString( 'word/document.xml', $document );
		$zip->addFromString( 'word/styles.xml', $styles );
		$zip->addFromString( 'word/_rels/document.xml.rels', $rels );
		if ( $photo ) {
			$zip->addFromString( 'word/media/photo.jpg', $photo );
		}
		$zip->close();
		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );
		return $bytes;
	}
}
