<?php
/**
 * Value object returned by a service run.
 *
 * @package AEC_Forge_Tools
 */

namespace AEC_Forge_Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Result of running a service: on-screen text plus an optional file deliverable.
 */
class Tool_Result {

	/**
	 * On-screen text (narrative / preview).
	 *
	 * @var string
	 */
	public $text;

	/**
	 * Raw file bytes, or null for text-only results.
	 *
	 * @var string|null
	 */
	public $file_bytes;

	/**
	 * Download filename.
	 *
	 * @var string
	 */
	public $file_name;

	/**
	 * File MIME type.
	 *
	 * @var string
	 */
	public $file_mime;

	/**
	 * Constructor.
	 *
	 * @param string      $text       On-screen text.
	 * @param string|null $file_bytes File bytes.
	 * @param string      $file_name  Filename.
	 * @param string      $file_mime  MIME type.
	 */
	public function __construct( $text, $file_bytes = null, $file_name = '', $file_mime = 'application/octet-stream' ) {
		$this->text       = $text;
		$this->file_bytes = $file_bytes;
		$this->file_name  = $file_name;
		$this->file_mime  = $file_mime;
	}

	/**
	 * Whether a file deliverable is attached.
	 *
	 * @return bool
	 */
	public function has_file() {
		return null !== $this->file_bytes && '' !== $this->file_bytes;
	}
}
