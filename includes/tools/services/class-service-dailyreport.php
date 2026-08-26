<?php
/**
 * Daily Construction Report service.
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
 * Turns rough field notes into a clean, formatted daily report (.docx).
 */
class Service_Dailyreport extends Abstract_Service {

	/**
	 * Section labels rendered as subheadings.
	 *
	 * @var string[]
	 */
	private $section_labels = array(
		'Work Performed',
		'Manpower',
		'Equipment',
		'Deliveries & Materials',
		'Delays & Issues',
		'Safety',
		'Visitors & Inspections',
		'Look-Ahead',
	);

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'dailyreport';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Daily Construction Report', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function blurb() {
		return __( 'Turn rough site notes into a clean, formatted daily report (.docx).', 'aec-market' );
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
				'name'        => 'date',
				'label'       => __( 'Date', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'e.g. 2026-08-26',
				'sample'      => '2026-08-26',
			),
			array(
				'name'        => 'weather',
				'label'       => __( 'Weather', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'Sunny, 88°F, light wind',
				'sample'      => 'Partly cloudy, 91°F, humid; brief rain 2–3 PM',
			),
			array(
				'name'        => 'super',
				'label'       => __( 'Superintendent', 'aec-market' ),
				'type'        => 'text',
				'placeholder' => 'J. Rivera',
				'sample'      => 'J. Rivera',
			),
			array(
				'name'        => 'notes',
				'label'       => __( 'Field notes (crews, work, deliveries, delays, safety)', 'aec-market' ),
				'type'        => 'textarea',
				'required'    => true,
				'full'        => true,
				'is_paste'    => true,
				'placeholder' => 'Concrete crew 8 poured slab on grid C-F. Steel delivery arrived 10am, 2 pieces short. Rain 2-3pm stopped roofing. Toolbox talk on fall protection.',
				'sample'      => "Concrete crew (8) placed 42 CY slab-on-grade at grid C–F, finished by 1 PM.\nSteel erector (5) set columns at grid A; delivery arrived 10 AM but 2 beams short — RFI to follow.\nRoofing (4) stopped 2–3 PM for rain, resumed after.\nElectrical (3) rough-in in Level 2 corridors.\nDeliveries: rebar (Nucor), 1 load; steel (short 2 beams).\nToolbox talk: fall protection at leading edge. No incidents.\nCity inspector on site 11 AM, approved footing rebar at grid A.",
			),
		);
	}

	/**
	 * System prompt.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return "You are a construction superintendent writing the official daily report for a commercial/institutional jobsite. You turn terse field notes into a clear, professional daily report that a project manager, owner, or claims consultant could rely on later.

Rules:
- Never invent facts. If a section has no information in the notes, write \"None reported\" — do not fabricate crews, counts, deliveries, or inspections.
- Preserve every number the user gives (crew sizes, quantities, times) exactly.
- Be concise and factual; no marketing tone, no blame.
- Group manpower by trade with headcounts when given.
- Call out anything that could support a delay or change claim (weather stoppages, short deliveries, missing information, differing site conditions) plainly under the right section.

Output plain text (no markdown fences) using exactly this structure:

Project: <project>
Date: <date>
Weather: <weather>
Superintendent: <name or TBD>

Work Performed:
<what got done, by area/grid>

Manpower:
<trade — headcount, one per line>

Equipment:
<major equipment on site, or None reported>

Deliveries & Materials:
<deliveries received / shortages, or None reported>

Delays & Issues:
<delays, stoppages, RFIs needed, or None reported>

Safety:
<toolbox talks, incidents, or None reported>

Visitors & Inspections:
<inspectors, owner visits, results, or None reported>

Look-Ahead:
<next work day plan if implied, or None reported>";
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( array $form ) {
		$project = isset( $form['project'] ) ? $form['project'] : '';
		$user    = sprintf(
			"Write the daily report from these notes. Use \"None reported\" for empty sections; never invent details.\n\nProject: %s\nDate: %s\nWeather: %s\nSuperintendent: %s\n\nField notes:\n%s\n",
			'' !== $project ? $project : 'N/A',
			! empty( $form['date'] ) ? $form['date'] : 'not provided',
			! empty( $form['weather'] ) ? $form['weather'] : 'not provided',
			! empty( $form['super'] ) ? $form['super'] : 'not provided',
			! empty( $form['notes'] ) ? $form['notes'] : '(none provided)'
		);

		$text  = Anthropic::ask( $this->system_prompt(), $user, 1600, $this->model() );
		$bytes = $this->build_docx( $text, $project );
		$safe  = $this->slug( '' !== $project ? $project : 'daily-report' );

		return new Tool_Result(
			$text,
			$bytes,
			'Daily-Report-' . $safe . '.docx',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
		);
	}

	/**
	 * Build the formatted .docx from the report text.
	 *
	 * @param string $report_text Model output.
	 * @param string $project     Project name.
	 * @return string
	 */
	private function build_docx( $report_text, $project ) {
		$doc = new Docx_Writer();
		$doc->heading( 'DAILY CONSTRUCTION REPORT', 0 );
		if ( '' !== $project ) {
			$doc->paragraph( $project, array( 'italic' => true ) );
		}

		$header_labels = array( 'Project', 'Date', 'Weather', 'Superintendent' );
		$lines         = preg_split( "/\r\n|\n|\r/", $report_text );
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
				if ( in_array( $label, $this->section_labels, true ) && '' === $value ) {
					$doc->heading( $label, 2 );
					continue;
				}
			}
			$doc->paragraph( $line );
		}

		$doc->spacer();
		$doc->paragraph(
			__( 'Generated by AEC Forge Tools from field notes — verify against the daily log and time cards before distributing.', 'aec-market' ),
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
		return substr( $s, 0, 40 ) ?: 'daily-report';
	}
}
