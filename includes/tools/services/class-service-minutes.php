<?php
/**
 * Meeting Minutes service.
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
 * Turns rough meeting notes into structured OAC minutes with action items (.docx).
 */
class Service_Minutes extends Abstract_Service {

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'minutes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Meeting Minutes', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function blurb() {
		return __( 'Turn rough meeting notes into structured minutes with tracked action items (.docx).', 'aec-market' );
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
				'name'        => 'type',
				'label'       => __( 'Meeting type', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'OAC / Coordination / Progress',
				'sample'      => 'OAC (Owner-Architect-Contractor)',
			),
			array(
				'name'        => 'date',
				'label'       => __( 'Date', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'e.g. 2026-08-26',
				'sample'      => '2026-08-26',
			),
			array(
				'name'        => 'attendees',
				'label'       => __( 'Attendees (name — company/role)', 'aec-market' ),
				'type'        => 'textarea',
				'placeholder' => "J. Rivera — GC Super\nA. Chen — Architect\nM. Ford — Owner PM",
				'sample'      => "J. Rivera — Barnhill GC, Superintendent\nA. Chen — Clark Nexsen, Architect\nM. Ford — NC DNCR, Owner PM\nS. Patel — MEP Engineer",
			),
			array(
				'name'        => 'notes',
				'label'       => __( 'Raw notes / discussion', 'aec-market' ),
				'type'        => 'textarea',
				'required'    => true,
				'full'        => true,
				'is_paste'    => true,
				'placeholder' => 'Discussed RFI 42 ceiling conflict — architect to respond by Fri. Owner approved paint samples. Steel delivery delay may push milestone 3 days. Next meeting in 2 weeks.',
				'sample'      => "RFI 42 (ceiling/sprinkler conflict at C-4): architect to issue response by Fri 8/29.\nPaint submittal: owner approved SW-7015 for corridors.\nSteel delivery 2 beams short — erector says 3-day impact to milestone 3; GC to submit time-impact analysis.\nChange event #7 (added floor boxes) — pricing due from GC next week.\nLong-lead switchgear: 14 weeks, order this week to protect schedule.\nNext OAC: 9/9, same time.",
			),
		);
	}

	/**
	 * System prompt.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return "You are a construction project manager producing the official minutes of a project meeting (OAC, coordination, or progress). You turn rough notes into clean, defensible minutes that become part of the project record.

Rules:
- Never invent decisions, names, dates, or numbers. If something is unclear, mark it \"[to confirm]\" rather than guessing.
- Preserve every RFI/submittal/change number and every date exactly as given.
- Separate DISCUSSION (what was talked about / decided) from ACTION ITEMS (who owes what, by when).
- Every action item must have an owner and a due date; if the notes don't give one, write the owner or date as \"[to confirm]\".
- Neutral, factual tone. No blame.

Output plain text (no markdown fences) using exactly this structure:

Project: <project>
Meeting: <type>
Date: <date>

Attendees:
<name — company/role, one per line>

Discussion & Decisions:
1. <topic> — <what was discussed/decided>
2. ...

Action Items:
- <action> | Owner: <name> | Due: <date>
- ...

Next Meeting: <date/time or [to confirm]>";
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( array $form ) {
		$project = isset( $form['project'] ) ? $form['project'] : '';
		$user    = sprintf(
			"Produce the meeting minutes from these notes. Do not invent anything; use [to confirm] for gaps.\n\nProject: %s\nMeeting type: %s\nDate: %s\n\nAttendees:\n%s\n\nRaw notes:\n%s\n",
			'' !== $project ? $project : 'N/A',
			! empty( $form['type'] ) ? $form['type'] : 'Project meeting',
			! empty( $form['date'] ) ? $form['date'] : 'not provided',
			! empty( $form['attendees'] ) ? $form['attendees'] : 'not provided',
			! empty( $form['notes'] ) ? $form['notes'] : '(none provided)'
		);

		$text  = Anthropic::ask( $this->system_prompt(), $user, 1800, $this->model() );
		$bytes = $this->build_docx( $text, $project );
		$safe  = $this->slug( '' !== $project ? $project : 'minutes' );

		return new Tool_Result(
			$text,
			$bytes,
			'Minutes-' . $safe . '.docx',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
		);
	}

	/**
	 * Build the formatted .docx from the minutes text.
	 *
	 * @param string $minutes_text Model output.
	 * @param string $project      Project name.
	 * @return string
	 */
	private function build_docx( $minutes_text, $project ) {
		$doc            = new Docx_Writer();
		$header_labels  = array( 'Project', 'Meeting', 'Date', 'Next Meeting' );
		$section_labels = array( 'Attendees', 'Discussion & Decisions', 'Action Items' );

		$doc->heading( 'MEETING MINUTES', 0 );
		if ( '' !== $project ) {
			$doc->paragraph( $project, array( 'italic' => true ) );
		}

		$lines = preg_split( "/\r\n|\n|\r/", $minutes_text );
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
				if ( in_array( $label, $header_labels, true ) ) {
					$doc->paragraph(
						array(
							array( 'text' => $label . ': ', 'bold' => true ),
							array( 'text' => $value ),
						)
					);
					continue;
				}
				if ( in_array( $label, $section_labels, true ) && '' === $value ) {
					$doc->heading( $label, 2 );
					continue;
				}
			}
			$doc->paragraph( $line );
		}

		$doc->spacer();
		$doc->paragraph(
			__( 'Generated by AEC Forge Tools — circulate for correction; minutes stand as written if no objection is received.', 'aec-market' ),
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
		return substr( $s, 0, 40 ) ?: 'minutes';
	}
}
