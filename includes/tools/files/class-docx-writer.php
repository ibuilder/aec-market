<?php
/**
 * Minimal, dependency-free DOCX (OOXML) writer.
 *
 * Builds a real .docx (zip of XML) with a title, headings, bold/italic runs and
 * normal paragraphs — enough for a clean, paste-ready RFI. No PhpWord, no
 * Composer; just ZipArchive.
 *
 * @package AEC_Forge_Tools\Files
 */

namespace AEC_Forge_Tools\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny Word document writer.
 */
class Docx_Writer {

	/**
	 * Body XML fragments (paragraphs).
	 *
	 * @var string[]
	 */
	private $body = array();

	/**
	 * Add a heading paragraph.
	 *
	 * @param string $text  Text.
	 * @param int    $level 0 = Title, 1 = Heading1, 2 = Heading2.
	 * @return void
	 */
	public function heading( $text, $level = 1 ) {
		$style = 0 === $level ? 'Title' : 'Heading' . max( 1, (int) $level );
		$this->body[] = '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>'
			. $this->run( $text, array() ) . '</w:p>';
	}

	/**
	 * Add a paragraph.
	 *
	 * @param string|array $content String, or array of runs
	 *                              (each: array{ text, bold?, italic?, size? }).
	 * @param array        $opts    Paragraph-wide run options (italic/bold/size).
	 * @return void
	 */
	public function paragraph( $content, array $opts = array() ) {
		$runs = '';
		if ( is_array( $content ) ) {
			foreach ( $content as $r ) {
				$text = isset( $r['text'] ) ? $r['text'] : '';
				$runs .= $this->run( $text, $r );
			}
		} else {
			$runs = $this->run( (string) $content, $opts );
		}
		$this->body[] = '<w:p>' . $runs . '</w:p>';
	}

	/**
	 * Add an empty spacer paragraph.
	 *
	 * @return void
	 */
	public function spacer() {
		$this->body[] = '<w:p/>';
	}

	/**
	 * Render to raw .docx bytes.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When zipping fails.
	 */
	public function output() {
		$tmp = wp_tempnam( 'aec-forge-tools-docx' );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the document file.', 'aec-market' ) );
		}
		$zip->addFromString( '[Content_Types].xml', $this->content_types_xml() );
		$zip->addFromString( '_rels/.rels', $this->root_rels_xml() );
		$zip->addFromString( 'word/document.xml', $this->document_xml() );
		$zip->addFromString( 'word/styles.xml', $this->styles_xml() );
		$zip->addFromString( 'word/_rels/document.xml.rels', $this->document_rels_xml() );
		$zip->close();

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		wp_delete_file( $tmp );
		return $bytes;
	}

	// ---- XML ------------------------------------------------------------

	/**
	 * Build a single run with optional formatting.
	 *
	 * @param string $text Text.
	 * @param array  $opts bold/italic/size (half-points).
	 * @return string
	 */
	private function run( $text, array $opts ) {
		$rpr = '';
		if ( ! empty( $opts['bold'] ) ) {
			$rpr .= '<w:b/>';
		}
		if ( ! empty( $opts['italic'] ) ) {
			$rpr .= '<w:i/>';
		}
		if ( ! empty( $opts['size'] ) ) {
			$sz   = (int) $opts['size'];
			$rpr .= '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/>';
		}
		$rpr = '' !== $rpr ? '<w:rPr>' . $rpr . '</w:rPr>' : '';
		return '<w:r>' . $rpr . '<w:t xml:space="preserve">' . $this->esc( $text ) . '</w:t></w:r>';
	}

	/**
	 * Main document part.
	 *
	 * @return string
	 */
	private function document_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body>' . implode( '', $this->body )
			. '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
			. '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
			. '</w:body></w:document>';
	}

	/**
	 * Styles part: Normal, Title, Heading1, Heading2.
	 *
	 * @return string
	 */
	private function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault></w:docDefaults>'
			. '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="36"/></w:rPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
			. '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="120" w:after="60"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>'
			. '</w:styles>';
	}

	/**
	 * Content types manifest.
	 *
	 * @return string
	 */
	private function content_types_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '</Types>';
	}

	/**
	 * Root relationships.
	 *
	 * @return string
	 */
	private function root_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Document relationships (styles).
	 *
	 * @return string
	 */
	private function document_rels_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';
	}

	/**
	 * Escape text for XML.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private function esc( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
