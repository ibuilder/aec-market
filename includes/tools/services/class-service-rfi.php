<?php
/**
 * RFI Draft Generator service.
 *
 * @package AEC_Forge_Tools\Services
 */

namespace AEC_Forge_Tools\Services;

use AEC_Forge_Tools\Abstract_Service;
use AEC_Forge_Tools\Anthropic;
use AEC_Forge_Tools\Tool_Result;
use AEC_Forge_Tools\Files\Docx_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a rough field question into a formatted, spec-referenced RFI (.docx).
 */
class Service_Rfi extends Abstract_Service {

	/**
	 * Header labels rendered as "bold label: value".
	 *
	 * @var string[]
	 */
	private $header_labels = array(
		'RFI No.',
		'Date',
		'Project',
		'To',
		'From',
		'Subject',
		'Spec Reference',
		'Drawing Reference',
		'Response Required By',
	);

	/**
	 * Section labels rendered as subheadings.
	 *
	 * @var string[]
	 */
	private $section_labels = array(
		'Question',
		'Background / Field Condition',
		'Proposed Resolution',
		'Impact',
		'Cost',
		'Schedule',
	);

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'rfi';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'RFI Draft Generator', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function blurb() {
		return __( 'Turn a rough field question into a clean, spec-referenced RFI (.docx).', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_credits() {
		return 1;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_model() {
		// Narrative-only from structured inputs — a cheaper tier handles it well.
		return 'claude-haiku-4-5';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields() {
		return array(
			array(
				'name'        => 'project',
				'label'       => __( 'Project', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'NC Museum of History — Expansion',
				'sample'      => 'NC Museum of History — Expansion',
			),
			array(
				'name'        => 'recipient',
				'label'       => __( 'Send to (design team)', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'Architect of Record',
				'sample'      => 'Architect of Record',
			),
			array(
				'name'        => 'sender',
				'label'       => __( 'From (PM / firm)', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'Barnhill–Balfour Beatty JV',
				'sample'      => 'Barnhill–Balfour Beatty JV',
			),
			array(
				'name'        => 'due',
				'label'       => __( 'Response needed by', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'e.g. 2026-07-25',
				'sample'      => '2026-07-25',
			),
			array(
				'name'        => 'spec',
				'label'       => __( 'Spec section (optional)', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => '09 51 00 Acoustical Ceilings',
				'sample'      => '09 51 00 Acoustical Ceilings',
			),
			array(
				'name'        => 'drawing',
				'label'       => __( 'Drawing / detail (optional)', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'A-501, Detail 3',
				'sample'      => 'A-501, Detail 3',
			),
			array(
				'name'        => 'question',
				'label'       => __( 'The issue / question', 'aec-market' ),
				'type'        => 'textarea',
				'required'    => true,
				'full'        => true,
				'placeholder' => 'Ceiling grid conflicts with the sprinkler main shown on FP-201 at column line C-4…',
				'sample'      => "Ceiling grid at column line C-4 conflicts with the sprinkler main shown on FP-201. The grid can't hold the 9'-6\" ceiling height called out on A-501. Should we drop the ceiling to 9'-0\" or reroute the main?",
			),
		);
	}

	/**
	 * System prompt.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return "You are a senior construction project manager drafting a formal Request for Information (RFI) to a design team. You have run RFIs on large commercial and institutional projects and know exactly what an architect or engineer needs to answer without a follow-up.

Write the RFI so it is specific, answerable, and self-contained. Always:
- Reference the relevant spec section (CSI MasterFormat) and drawing/detail when provided; if the user didn't give one, note the reference is TBD rather than inventing a number.
- State the question so there is exactly one thing being asked. If there are multiple issues, split them and note they may warrant separate RFIs.
- Include a proposed solution or interpretation when reasonable.
- Flag potential cost and/or schedule impact explicitly so it isn't missed.
- Keep the tone professional and neutral; never assign blame.

Output a clean RFI using this structure (plain text, no markdown code fences):

RFI No.: [TBD]
Date: [today]
Project: <project>
To: <design team / recipient>
From: <PM / firm>
Subject: <one line>
Spec Reference: <section or TBD>
Drawing Reference: <sheet/detail or TBD>

Question:
<the precise question>

Background / Field Condition:
<what prompted this>

Proposed Resolution:
<your suggested interpretation, if any>

Impact:
Cost: <potential impact / none identified>
Schedule: <potential impact / none identified>
Response Required By: <date or \"at earliest convenience to avoid delay\">";
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( array $form ) {
		$project = isset( $form['project'] ) ? $form['project'] : '';
		$user    = sprintf(
			"Draft an RFI from these inputs. Fill gaps sensibly; mark unknown references as TBD rather than fabricating them.\n\nProject: %s\nRecipient (design team): %s\nFrom (PM/firm): %s\nSpec section: %s\nDrawing/detail reference: %s\nResponse needed by: %s\n\nThe issue / question (as described in the field):\n%s\n",
			$project ? $project : 'N/A',
			! empty( $form['recipient'] ) ? $form['recipient'] : 'Architect of Record',
			! empty( $form['sender'] ) ? $form['sender'] : 'General Contractor',
			! empty( $form['spec'] ) ? $form['spec'] : 'not provided',
			! empty( $form['drawing'] ) ? $form['drawing'] : 'not provided',
			! empty( $form['due'] ) ? $form['due'] : 'not provided',
			! empty( $form['question'] ) ? $form['question'] : '(none provided)'
		);

		$text  = Anthropic::ask( $this->system_prompt(), $user, 1500, $this->model() );
		$bytes = $this->build_docx( $text, $project );
		$safe  = $this->slug( '' !== $project ? $project : 'rfi' );

		return new Tool_Result(
			$text,
			$bytes,
			'RFI-' . $safe . '.docx',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
		);
	}

	/**
	 * Build the formatted .docx from the RFI text.
	 *
	 * @param string $rfi_text Model output.
	 * @param string $project  Project name.
	 * @return string
	 */
	private function build_docx( $rfi_text, $project ) {
		$doc = new Docx_Writer();
		$doc->heading( 'REQUEST FOR INFORMATION', 0 );
		if ( '' !== $project ) {
			$doc->paragraph( $project, array( 'italic' => true ) );
		}

		$lines = preg_split( "/\r\n|\n|\r/", $rfi_text );
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			if ( '' === trim( $line ) ) {
				$doc->spacer();
				continue;
			}
			$pos = strpos( $line, ':' );
			if ( false !== $pos ) {
				$label = trim( substr( $line, 0, $pos ) );
				$value = trim( substr( $line, $pos + 1 ) );
				if ( in_array( $label, $this->header_labels, true ) ) {
					$doc->paragraph(
						array(
							array( 'text' => $label . ': ', 'bold' => true ),
							array( 'text' => $value ),
						)
					);
					continue;
				}
				if ( in_array( $label, $this->section_labels, true ) && '' === $value ) {
					$doc->heading( $label, 2 );
					continue;
				}
				if ( in_array( $label, $this->section_labels, true ) ) {
					$doc->paragraph(
						array(
							array( 'text' => $label . ': ', 'bold' => true ),
							array( 'text' => $value ),
						)
					);
					continue;
				}
			}
			$doc->paragraph( $line );
		}

		$doc->spacer();
		$doc->paragraph(
			__( 'Drafting aid generated by AEC Forge Tools — review against the contract documents and drawings before issuing. Not a substitute for the PM\'s or design team\'s professional judgment.', 'aec-market' ),
			array( 'italic' => true, 'size' => 16 )
		);

		return $doc->output();
	}

	/**
	 * Filename-safe slug.
	 *
	 * @param string $s Input.
	 * @return string
	 */
	private function slug( $s ) {
		$s = preg_replace( '/[\/\s]+/', '-', trim( $s ) );
		$s = preg_replace( '/[^A-Za-z0-9\-_]/', '', $s );
		return substr( $s, 0, 40 ) ?: 'rfi';
	}
}
