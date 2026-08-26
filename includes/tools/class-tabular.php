<?php
/**
 * Tabular input parsing (CSV + minimal XLSX) and value coercion helpers.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Parses uploaded/pasted tables and normalizes cell values.
 */
class Tabular {

	/**
	 * Load rows from a form's upload or paste field.
	 *
	 * @param array $form Inputs (file_bytes/_filename, or file_text/paste).
	 * @return array{0: array<int,array<string,string>>, 1: array<int,string>}
	 *
	 * @throws \RuntimeException On empty/invalid input.
	 */
	public static function load( array $form ) {
		$filename = isset( $form['_filename'] ) ? strtolower( (string) $form['_filename'] ) : '';
		$bytes    = isset( $form['file_bytes'] ) ? $form['file_bytes'] : '';

		if ( '' !== $bytes && ( self::ends_with( $filename, '.xlsx' ) || self::ends_with( $filename, '.xlsm' ) ) ) {
			return self::parse_xlsx( $bytes );
		}

		$text = '';
		foreach ( array( 'file_text', 'paste', 'log_text' ) as $k ) {
			if ( ! empty( $form[ $k ] ) ) {
				$text = (string) $form[ $k ];
				break;
			}
		}
		$text = trim( $text );
		if ( '' === $text ) {
			throw new \RuntimeException(
				esc_html__( 'No data provided. Upload a CSV/XLSX export or paste the table, then run again.', 'aec-market' )
			);
		}
		return self::parse_csv( $text );
	}

	/**
	 * Parse CSV text into rows keyed by header.
	 *
	 * @param string $text CSV text.
	 * @return array{0: array, 1: array}
	 *
	 * @throws \RuntimeException When no rows are found.
	 */
	public static function parse_csv( $text ) {
		$text = preg_replace( "/^\xEF\xBB\xBF/", '', $text ); // strip BOM.

		// Parse through a memory stream with fgetcsv so quoted fields containing
		// embedded newlines (common in Excel exports) stay in one field rather
		// than being split into corrupt, column-shifted rows.
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			throw new \RuntimeException( esc_html__( 'Could not read the pasted table.', 'aec-market' ) );
		}
		fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );

		$rows = array();
		$head = null;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		while ( false !== ( $cells = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) {
			// A blank line yields array( null ).
			if ( array( null ) === $cells ) {
				continue;
			}
			if ( null === $head ) {
				$head = array_map( 'trim', $cells );
				continue;
			}
			$record    = array();
			$has_value = false;
			foreach ( $head as $i => $col ) {
				if ( '' === $col ) {
					continue;
				}
				$value           = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
				$record[ $col ]  = $value;
				$has_value       = $has_value || '' !== $value;
			}
			if ( $has_value ) {
				$rows[] = $record;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! $head || ! $rows ) {
			throw new \RuntimeException(
				esc_html__( 'Could not read any rows. Make sure the file has a header row followed by data.', 'aec-market' )
			);
		}
		return array( $rows, array_values( array_filter( $head, 'strlen' ) ) );
	}

	/**
	 * Minimal XLSX reader (first worksheet) using ZipArchive + SimpleXML.
	 *
	 * @param string $bytes Raw .xlsx bytes.
	 * @return array{0: array, 1: array}
	 *
	 * @throws \RuntimeException On unreadable spreadsheet.
	 */
	public static function parse_xlsx( $bytes ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException(
				esc_html__( 'This server cannot read .xlsx files. Please upload a CSV or paste the data.', 'aec-market' )
			);
		}

		$tmp = wp_tempnam( 'aec-forge-tools-xlsx' );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( esc_html__( 'Could not open that spreadsheet.', 'aec-market' ) );
		}

		$shared = array();
		$ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss_xml ) {
			$ss = simplexml_load_string( $ss_xml );
			if ( false !== $ss ) {
				foreach ( $ss->si as $si ) {
					$shared[] = self::si_text( $si );
				}
			}
		}

		// Resolve the FIRST sheet in display order via workbook.xml + its rels —
		// the data sheet is not always physically sheet1.xml (e.g. after a sheet
		// was deleted). Fall back to sheet1.xml, then to any worksheet present.
		$sheet_xml = false;
		$path      = self::first_sheet_path( $zip );
		if ( false !== $path ) {
			$sheet_xml = $zip->getFromName( $path );
		}
		if ( false === $sheet_xml ) {
			$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		}
		if ( false === $sheet_xml ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( is_string( $name ) && preg_match( '#^xl/worksheets/sheet[^/]*\.xml$#', $name ) ) {
					$sheet_xml = $zip->getFromName( $name );
					if ( false !== $sheet_xml ) {
						break;
					}
				}
			}
		}
		$zip->close();
		wp_delete_file( $tmp );

		if ( false === $sheet_xml ) {
			throw new \RuntimeException( esc_html__( 'The spreadsheet has no readable worksheet.', 'aec-market' ) );
		}

		$sheet = simplexml_load_string( $sheet_xml );
		if ( false === $sheet ) {
			throw new \RuntimeException( esc_html__( 'The spreadsheet is malformed.', 'aec-market' ) );
		}

		$matrix = array();
		foreach ( $sheet->sheetData->row as $row ) {
			$cells = array();
			$max   = 0;
			foreach ( $row->c as $c ) {
				$ref  = (string) $c['r'];
				$col  = self::col_index( preg_replace( '/\d+/', '', $ref ) );
				$type = (string) $c['t'];
				$val  = '';
				if ( 's' === $type ) {
					$idx = (int) $c->v;
					$val = isset( $shared[ $idx ] ) ? $shared[ $idx ] : '';
				} elseif ( 'inlineStr' === $type ) {
					$val = self::si_text( $c->is );
				} else {
					$val = (string) $c->v;
				}
				$cells[ $col ] = trim( $val );
				$max           = max( $max, $col );
			}
			$line = array();
			for ( $i = 0; $i <= $max; $i++ ) {
				$line[] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$matrix[] = $line;
		}

		// First non-empty row is the header.
		$head = null;
		$rows = array();
		foreach ( $matrix as $line ) {
			$non_empty = array_filter( $line, 'strlen' );
			if ( null === $head ) {
				if ( $non_empty ) {
					$head = array_map( 'trim', $line );
				}
				continue;
			}
			if ( ! $non_empty ) {
				continue;
			}
			$record = array();
			foreach ( $head as $i => $col ) {
				if ( '' === $col ) {
					continue;
				}
				$record[ $col ] = isset( $line[ $i ] ) ? $line[ $i ] : '';
			}
			$rows[] = $record;
		}

		if ( ! $head || ! $rows ) {
			throw new \RuntimeException( esc_html__( 'No data rows found under the header.', 'aec-market' ) );
		}
		return array( $rows, array_values( array_filter( $head, 'strlen' ) ) );
	}

	/**
	 * Build a column mapper from canonical keys to actual headers.
	 *
	 * @param array $fieldnames Header labels.
	 * @param array $aliases    canonical => array of lowercase candidate labels.
	 * @return array{0: callable, 1: array, 2: array} getter, map, missing.
	 */
	public static function mapper( $fieldnames, $aliases ) {
		$lowered = array();
		foreach ( (array) $fieldnames as $fn ) {
			$lowered[ strtolower( trim( (string) $fn ) ) ] = $fn;
		}
		$map = array();
		foreach ( $aliases as $canonical => $names ) {
			foreach ( $names as $name ) {
				if ( isset( $lowered[ $name ] ) ) {
					$map[ $canonical ] = $lowered[ $name ];
					break;
				}
			}
		}
		$getter = static function ( $row, $canonical ) use ( $map ) {
			if ( ! isset( $map[ $canonical ] ) ) {
				return '';
			}
			$col = $map[ $canonical ];
			return isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
		};
		$missing = array();
		foreach ( $aliases as $canonical => $names ) {
			if ( ! isset( $map[ $canonical ] ) ) {
				$missing[] = $canonical;
			}
		}
		return array( $getter, $map, $missing );
	}

	/**
	 * Tolerant currency/number parse. '$1,234.50' -> 1234.5, '(500)' -> -500.
	 *
	 * @param mixed $val Raw value.
	 * @return float|null
	 */
	public static function money( $val ) {
		if ( null === $val || '' === $val ) {
			return null;
		}
		if ( is_int( $val ) || is_float( $val ) ) {
			return (float) $val;
		}
		$s   = trim( (string) $val );
		$neg = ( '' !== $s && '(' === $s[0] && ')' === substr( $s, -1 ) );
		$s   = str_replace( array( '(', ')', '$', ',', '%', ' ' ), '', $s );
		if ( '' === $s || ! is_numeric( $s ) ) {
			return null;
		}
		$num = (float) $s;
		return $neg ? -$num : $num;
	}

	/**
	 * Parse a date string into a DateTimeImmutable (UTC) or null.
	 *
	 * @param mixed $val Raw value.
	 * @return \DateTimeImmutable|null
	 */
	public static function date_val( $val ) {
		$s = trim( (string) $val );
		if ( '' === $s ) {
			return null;
		}
		$formats = array( 'Y-m-d', 'm/d/Y', 'm/d/y', 'd-M-Y', 'd-M-y', 'm-d-Y', 'Y/m/d' );
		foreach ( $formats as $fmt ) {
			$dt = \DateTimeImmutable::createFromFormat( '!' . $fmt, $s, new \DateTimeZone( 'UTC' ) );
			if ( false === $dt ) {
				continue;
			}
			// Reject overflow dates (e.g. 02/30/2026) that PHP silently rolls over
			// into a valid-but-wrong date. getLastErrors() is false when clean
			// (PHP 8.2+) or an array of zero counts otherwise.
			$errors = \DateTimeImmutable::getLastErrors();
			if ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) {
				return $dt;
			}
		}
		return null;
	}

	/**
	 * Whole days between two dates (b - a).
	 *
	 * @param \DateTimeImmutable $a Earlier.
	 * @param \DateTimeImmutable $b Later.
	 * @return int
	 */
	public static function days_between( $a, $b ) {
		return (int) round( ( $b->getTimestamp() - $a->getTimestamp() ) / 86400 );
	}

	/**
	 * Resolve the archive path of the first worksheet in display order.
	 *
	 * Reads xl/workbook.xml for the first <sheet>'s relationship id, then maps
	 * that id to a target via xl/_rels/workbook.xml.rels.
	 *
	 * @param \ZipArchive $zip Open archive.
	 * @return string|false Worksheet path (e.g. 'xl/worksheets/sheet2.xml') or false.
	 */
	private static function first_sheet_path( $zip ) {
		$wb_xml = $zip->getFromName( 'xl/workbook.xml' );
		if ( false === $wb_xml ) {
			return false;
		}
		$wb = simplexml_load_string( $wb_xml );
		if ( false === $wb || ! isset( $wb->sheets->sheet[0] ) ) {
			return false;
		}
		$first = $wb->sheets->sheet[0];

		// The r:id lives in the relationships namespace.
		$rid = '';
		foreach ( $first->getNameSpaces( true ) as $uri ) {
			$attrs = $first->attributes( $uri );
			if ( isset( $attrs['id'] ) ) {
				$rid = (string) $attrs['id'];
				break;
			}
		}
		if ( '' === $rid ) {
			return false;
		}

		$rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $rels_xml ) {
			return false;
		}
		$rels = simplexml_load_string( $rels_xml );
		if ( false === $rels ) {
			return false;
		}
		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = ltrim( (string) $rel['Target'], '/' );
				if ( 0 !== strpos( $target, 'xl/' ) ) {
					$target = 'xl/' . $target;
				}
				return $target;
			}
		}
		return false;
	}

	/**
	 * Extract text from a sharedStrings <si> node (handles rich runs).
	 *
	 * @param \SimpleXMLElement $si Node.
	 * @return string
	 */
	private static function si_text( $si ) {
		if ( ! $si ) {
			return '';
		}
		if ( isset( $si->t ) && count( $si->t ) ) {
			$out = '';
			foreach ( $si->t as $t ) {
				$out .= (string) $t;
			}
			if ( '' !== $out ) {
				return $out;
			}
		}
		$out = '';
		foreach ( $si->r as $r ) {
			$out .= (string) $r->t;
		}
		return $out;
	}

	/**
	 * Convert a column letter (A, B, AA) to a 0-based index.
	 *
	 * @param string $letters Column letters.
	 * @return int
	 */
	private static function col_index( $letters ) {
		$letters = strtoupper( $letters );
		$n       = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$n = $n * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return $n - 1;
	}

	/**
	 * Whether $haystack ends with $needle.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 !== $len && substr( $haystack, -$len ) === $needle;
	}
}
