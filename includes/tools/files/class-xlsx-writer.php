<?php
/**
 * Minimal, dependency-free XLSX (OOXML) writer.
 *
 * Produces a real .xlsx (a zip of XML parts) with multiple sheets, inline
 * strings, number/percent formats, a styled header row, bold totals, column
 * widths and a frozen header. No PhpSpreadsheet, no Composer — just ZipArchive,
 * which is core PHP. Purpose-built for AEC_Forge_Tools's tabular deliverables.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny spreadsheet writer.
 */
class Xlsx_Writer {

	// Style indexes matching the cellXfs order in styles_xml().
	const S_DEFAULT     = 0;
	const S_HEADER      = 1;
	const S_MONEY       = 2;
	const S_PERCENT     = 3;
	const S_MONEY_BOLD  = 4;
	const S_BOLD        = 5;

	/**
	 * Sheets: list of array{ name, rows, widths, freeze }.
	 *
	 * @var array
	 */
	private $sheets = array();

	/**
	 * Add a sheet; returns its integer handle.
	 *
	 * @param string $name Sheet name.
	 * @return int
	 */
	public function add_sheet( $name ) {
		$this->sheets[] = array(
			'name'   => $this->clean_sheet_name( $name ),
			'rows'   => array(),
			'widths' => array(),
			'freeze' => false,
		);
		return count( $this->sheets ) - 1;
	}

	/**
	 * Append a row of cells to a sheet.
	 *
	 * @param int   $sheet Sheet handle.
	 * @param array $cells Cells (scalars or cell arrays from the helpers).
	 * @return void
	 */
	public function add_row( $sheet, array $cells ) {
		$norm = array();
		foreach ( $cells as $cell ) {
			$norm[] = is_array( $cell ) ? $cell : self::auto( $cell );
		}
		$this->sheets[ $sheet ]['rows'][] = $norm;
	}

	/**
	 * Set column widths for a sheet.
	 *
	 * @param int   $sheet  Sheet handle.
	 * @param array $widths Column widths (in Excel units).
	 * @return void
	 */
	public function set_widths( $sheet, array $widths ) {
		$this->sheets[ $sheet ]['widths'] = $widths;
	}

	/**
	 * Freeze the header row (row 1) on a sheet.
	 *
	 * @param int $sheet Sheet handle.
	 * @return void
	 */
	public function freeze_header( $sheet ) {
		$this->sheets[ $sheet ]['freeze'] = true;
	}

	// ---- Cell helpers ---------------------------------------------------

	/**
	 * Auto-typed cell (number if numeric, else string).
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	public static function auto( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			return array( 't' => 'n', 'v' => $v, 's' => self::S_DEFAULT );
		}
		return array( 't' => 's', 'v' => (string) $v, 's' => self::S_DEFAULT );
	}

	/**
	 * String cell.
	 *
	 * @param mixed $v     Value.
	 * @param int   $style Style index.
	 * @return array
	 */
	public static function text( $v, $style = self::S_DEFAULT ) {
		return array( 't' => 's', 'v' => (string) $v, 's' => $style );
	}

	/**
	 * Header cell.
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	public static function header( $v ) {
		return array( 't' => 's', 'v' => (string) $v, 's' => self::S_HEADER );
	}

	/**
	 * Numeric cell.
	 *
	 * @param mixed $v     Value.
	 * @param int   $style Style index.
	 * @return array
	 */
	public static function num( $v, $style = self::S_DEFAULT ) {
		return array( 't' => 'n', 'v' => 0 + $v, 's' => $style );
	}

	/**
	 * Money cell.
	 *
	 * @param mixed $v    Value.
	 * @param bool  $bold Bold totals.
	 * @return array
	 */
	public static function money( $v, $bold = false ) {
		return self::num( $v, $bold ? self::S_MONEY_BOLD : self::S_MONEY );
	}

	/**
	 * Percent cell (expects a fraction, e.g. 0.64).
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	public static function percent( $v ) {
		return self::num( $v, self::S_PERCENT );
	}

	/**
	 * Bold string cell.
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	public static function bold( $v ) {
		return array( 't' => 's', 'v' => (string) $v, 's' => self::S_BOLD );
	}

	// ---- Output ---------------------------------------------------------

	/**
	 * Render the workbook to raw .xlsx bytes.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When zipping fails.
	 */
	public function output() {
		if ( ! $this->sheets ) {
			$this->add_sheet( 'Sheet1' );
		}
		$tmp = wp_tempnam( 'aec-forge-tools-xlsx' );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the spreadsheet file.', 'aec-market' ) );
		}

		$zip->addFromString( '[Content_Types].xml', $this->content_types_xml() );
		$zip->addFromString( '_rels/.rels', $this->root_rels_xml() );
		$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml() );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels_xml() );
		$zip->addFromString( 'xl/styles.xml', $this->styles_xml() );
		foreach ( $this->sheets as $i => $sheet ) {
			$zip->addFromString( 'xl/worksheets/sheet' . ( $i + 1 ) . '.xml', $this->sheet_xml( $sheet ) );
		}
		$zip->close();

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		wp_delete_file( $tmp );
		return $bytes;
	}

	// ---- XML parts ------------------------------------------------------

	/**
	 * Build a worksheet XML string.
	 *
	 * @param array $sheet Sheet data.
	 * @return string
	 */
	private function sheet_xml( $sheet ) {
		$cols = '';
		if ( ! empty( $sheet['widths'] ) ) {
			$cols .= '<cols>';
			foreach ( $sheet['widths'] as $i => $w ) {
				$cols .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
			}
			$cols .= '</cols>';
		}

		$sheet_views = '';
		if ( ! empty( $sheet['freeze'] ) ) {
			$sheet_views = '<sheetViews><sheetView workbookViewId="0">'
				. '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
				. '</sheetView></sheetViews>';
		}

		$data = '';
		foreach ( $sheet['rows'] as $r => $row ) {
			$rownum = $r + 1;
			$data  .= '<row r="' . $rownum . '">';
			foreach ( $row as $c => $cell ) {
				$ref   = $this->col_letter( $c ) . $rownum;
				$style = isset( $cell['s'] ) ? (int) $cell['s'] : 0;
				$attr  = ' r="' . $ref . '"' . ( $style ? ' s="' . $style . '"' : '' );
				if ( 'n' === $cell['t'] && is_numeric( $cell['v'] ) ) {
					$data .= '<c' . $attr . '><v>' . $this->num_str( $cell['v'] ) . '</v></c>';
				} else {
					$data .= '<c' . $attr . ' t="inlineStr"><is><t xml:space="preserve">'
						. $this->esc( (string) $cell['v'] ) . '</t></is></c>';
				}
			}
			$data .= '</row>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. $sheet_views . $cols . '<sheetData>' . $data . '</sheetData></worksheet>';
	}

	/**
	 * Content types manifest.
	 *
	 * @return string
	 */
	private function content_types_xml() {
		$overrides = '';
		foreach ( $this->sheets as $i => $unused ) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . ( $i + 1 )
				. '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $overrides . '</Types>';
	}

	/**
	 * Root relationships.
	 *
	 * @return string
	 */
	private function root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Workbook part listing sheets.
	 *
	 * @return string
	 */
	private function workbook_xml() {
		$sheets = '';
		foreach ( $this->sheets as $i => $sheet ) {
			$sheets .= '<sheet name="' . $this->esc( $sheet['name'] ) . '" sheetId="' . ( $i + 1 )
				. '" r:id="rId' . ( $i + 1 ) . '"/>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $sheets . '</sheets></workbook>';
	}

	/**
	 * Workbook relationships (sheets + styles).
	 *
	 * @return string
	 */
	private function workbook_rels_xml() {
		$rels = '';
		foreach ( $this->sheets as $i => $unused ) {
			$rels .= '<Relationship Id="rId' . ( $i + 1 )
				. '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'
				. ( $i + 1 ) . '.xml"/>';
		}
		$style_id = count( $this->sheets ) + 1;
		$rels    .= '<Relationship Id="rId' . $style_id
			. '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. $rels . '</Relationships>';
	}

	/**
	 * Styles: fonts, fills, number formats, and the cellXfs referenced by S_*.
	 *
	 * @return string
	 */
	private function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="2">'
			. '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
			. '<numFmt numFmtId="165" formatCode="0.0%"/>'
			. '</numFmts>'
			. '<fonts count="3">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="3">'
			. '<fill><patternFill patternType="none"/></fill>'
			. '<fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF1F2A37"/><bgColor indexed="64"/></patternFill></fill>'
			. '</fills>'
			. '<borders count="1"><border/></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="6">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
			. '<xf numFmtId="164" fontId="2" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
			. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	// ---- utils ----------------------------------------------------------

	/**
	 * Column index (0-based) to letter(s).
	 *
	 * @param int $index Column index.
	 * @return string
	 */
	private function col_letter( $index ) {
		$letter = '';
		$index += 1;
		while ( $index > 0 ) {
			$mod    = ( $index - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$index  = (int) ( ( $index - $mod ) / 26 );
		}
		return $letter;
	}

	/**
	 * Format a number for XML (no thousands separators, dot decimal).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private function num_str( $v ) {
		if ( is_int( $v ) ) {
			return (string) $v;
		}
		$s = rtrim( rtrim( sprintf( '%.10F', (float) $v ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * Escape text for XML.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function esc( $s ) {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Sanitize a sheet name to Excel's constraints.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private function clean_sheet_name( $name ) {
		$name = preg_replace( '/[\\\\\/\?\*\[\]:]/', ' ', (string) $name );
		$name = trim( $name );
		if ( '' === $name ) {
			$name = 'Sheet';
		}
		return mb_substr( $name, 0, 31 );
	}
}
