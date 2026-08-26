<?php
/**
 * Submittals Log Analyzer service.
 *
 * @package AEC_Forge_Tools\Services
 */

namespace AEC_Forge_Tools\Services;

use AEC_Forge_Tools\Abstract_Service;
use AEC_Forge_Tools\Anthropic;
use AEC_Forge_Tools\Tabular;
use AEC_Forge_Tools\Tool_Result;
use AEC_Forge_Tools\Files\Xlsx_Writer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a submittal-log export and returns a prioritized risk read + .xlsx.
 * All date/lead-time math is done in PHP; the model only writes the narrative.
 */
class Service_Submittals extends Abstract_Service {

	/**
	 * Column aliases.
	 *
	 * @var array
	 */
	private $aliases = array(
		'number'    => array( 'submittal no', 'submittal no.', 'submittal number', 'no', 'no.', 'number', 'id', 'item' ),
		'title'     => array( 'title', 'description', 'submittal title', 'name' ),
		'spec'      => array( 'spec', 'spec section', 'specification', 'section', 'csi' ),
		'ball'      => array( 'ball in court', 'ball-in-court', 'responsible', 'responsible party', 'bic', 'with', 'held by' ),
		'submitted' => array( 'date submitted', 'submitted', 'submitted date', 'date sent' ),
		'due'       => array( 'date due', 'due', 'due date', 'date required', 'response due', 'review due' ),
		'lead_wks'  => array( 'lead time (wks)', 'lead time', 'lead time weeks', 'lead', 'lead (wks)' ),
		'on_site'   => array( 'required on site', 'required on-site', 'need by', 'on site', 'on-site date', 'required by' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'submittals';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Submittals Log Analyzer', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function blurb() {
		return __( 'Upload a submittal log (CSV/XLSX) and get a prioritized risk read + .xlsx.', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_credits() {
		return 2;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_model() {
		// All math is done in PHP; the model only writes the risk narrative.
		return 'claude-haiku-4-5';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_type() {
		return 'upload';
	}

	/**
	 * {@inheritDoc}
	 */
	public function sample_file() {
		return 'submittals-log.csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields() {
		return array(
			array(
				'name'        => 'today',
				'label'       => __( "Today's date (for overdue math)", 'aec-market' ),
				'type'        => 'text',
				'placeholder' => '2026-07-15',
				'sample'      => '2026-07-15',
			),
			array(
				'name'   => 'file',
				'label'  => __( 'Upload submittal log (CSV or XLSX)', 'aec-market' ),
				'type'   => 'file',
				'accept' => '.csv,.xlsx,.xlsm',
			),
			array(
				'name'        => 'log_text',
				'label'       => __( '…or paste the log here', 'aec-market' ),
				'type'        => 'textarea',
				'full'        => true,
				'is_paste'    => true,
				'placeholder' => 'Submittal No, Title, Spec, Ball In Court, Date Submitted, Date Due, Lead Time (wks), Required On Site',
			),
		);
	}

	/**
	 * System prompt.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return "You are a senior construction project manager reviewing a submittal log the way you would in a Monday coordination meeting. You have already been given the parsed, calculated facts (which items are overdue, which are at lead-time risk against their required-on-site date, who holds the ball). Do NOT recalculate dates or invent submittals, spec sections, or numbers that are not in the data — if something is missing, say so.

Produce a tight, paste-ready risk read with these sections (plain text, no markdown code fences):

SUBMITTAL LOG — RISK READ
Prepared: <today>
Log size: <N items>

TOP PRIORITIES (do these first)
- A short, ranked list of the 3-6 items that most threaten the schedule. For each: submittal no + title, why it's urgent, who holds the ball, and the one action to take.

OVERDUE (review past due)
- Bullet each overdue item: no, title, days overdue, ball-in-court.

LEAD-TIME EXPOSURE (won't arrive in time)
- Items where approval + fabrication/lead time pushes delivery past the required-on-site date.

BALL-IN-COURT SUMMARY
- Count of open items by responsible party.

WATCH / DATA GAPS
- Items missing key dates or lead times.

Keep it concise and specific. Flag schedule impact explicitly. This is a drafting aid for a PM's review, not a contractual determination.";
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( array $form ) {
		list( $rows, $fieldnames ) = Tabular::load( $form );

		$today = Tabular::date_val( isset( $form['today'] ) ? $form['today'] : '' );
		if ( null === $today ) {
			$today = new \DateTimeImmutable( 'today', new \DateTimeZone( 'UTC' ) );
		}

		$analyzed = $this->analyze( $rows, $fieldnames, $today );
		$facts    = $this->facts_block( $analyzed, $today );

		$narrative = Anthropic::ask(
			$this->system_prompt(),
			"Here is the parsed submittal log with all date math already done. Write the risk read from these facts only.\n\n" . $facts,
			2000,
			$this->model()
		);

		$xlsx = $this->build_xlsx( $analyzed, $today );

		return new Tool_Result(
			$narrative,
			$xlsx,
			'submittals-risk-' . $today->format( 'Y-m-d' ) . '.xlsx',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}

	/**
	 * Compute per-row overdue and lead-time exposure.
	 *
	 * @param array              $rows       Parsed rows.
	 * @param array              $fieldnames Headers.
	 * @param \DateTimeImmutable $today      Reference date.
	 * @return array
	 */
	public function analyze( $rows, $fieldnames, $today ) {
		list( $get, $map, $missing ) = Tabular::mapper( $fieldnames, $this->aliases );

		$out = array();
		foreach ( $rows as $r ) {
			$number = $get( $r, 'number' );
			$title  = $get( $r, 'title' );
			if ( '' === $number && '' === $title ) {
				continue;
			}
			$due     = Tabular::date_val( $get( $r, 'due' ) );
			$on_site = Tabular::date_val( $get( $r, 'on_site' ) );
			$lead    = Tabular::money( $get( $r, 'lead_wks' ) );

			$days_overdue = null !== $due ? Tabular::days_between( $due, $today ) : null;
			$is_overdue   = ( null !== $days_overdue && $days_overdue > 0 );

			$lead_gap = null;
			$at_risk  = false;
			if ( null !== $on_site && null !== $lead ) {
				$weeks_available = Tabular::days_between( $today, $on_site ) / 7.0;
				$lead_gap        = round( $lead - $weeks_available, 1 );
				$at_risk         = $lead_gap > 0;
			}

			$out[] = array(
				'number'       => '' !== $number ? $number : '(no #)',
				'title'        => '' !== $title ? $title : '(untitled)',
				'spec'         => $get( $r, 'spec' ),
				'ball'         => '' !== $get( $r, 'ball' ) ? $get( $r, 'ball' ) : 'Unassigned',
				'due'          => null !== $due ? $due->format( 'Y-m-d' ) : '',
				'days_overdue' => $days_overdue,
				'is_overdue'   => $is_overdue,
				'lead_wks'     => $lead,
				'on_site'      => null !== $on_site ? $on_site->format( 'Y-m-d' ) : '',
				'lead_gap_wks' => $lead_gap,
				'at_lead_risk' => $at_risk,
			);
		}

		return array(
			'rows'    => $out,
			'missing' => $missing,
		);
	}

	/**
	 * Build the deterministic fact sheet handed to the model.
	 *
	 * @param array              $analyzed Analysis result.
	 * @param \DateTimeImmutable $today    Reference date.
	 * @return string
	 */
	private function facts_block( $analyzed, $today ) {
		$rows      = $analyzed['rows'];
		$overdue   = array_filter( $rows, static function ( $r ) {
			return $r['is_overdue'];
		} );
		$lead_risk = array_filter( $rows, static function ( $r ) {
			return $r['at_lead_risk'];
		} );

		$ball = array();
		foreach ( $rows as $r ) {
			$ball[ $r['ball'] ] = ( isset( $ball[ $r['ball'] ] ) ? $ball[ $r['ball'] ] : 0 ) + 1;
		}

		$lines   = array();
		$lines[] = 'TODAY: ' . $today->format( 'Y-m-d' );
		$lines[] = 'TOTAL ITEMS: ' . count( $rows );
		$lines[] = '';
		$lines[] = 'OVERDUE ITEMS (computed):';
		if ( $overdue ) {
			usort( $overdue, static function ( $a, $b ) {
				return $b['days_overdue'] <=> $a['days_overdue'];
			} );
			foreach ( $overdue as $r ) {
				$lines[] = sprintf( '  - %s | %s | due %s | %d days overdue | ball: %s', $r['number'], $r['title'], $r['due'], $r['days_overdue'], $r['ball'] );
			}
		} else {
			$lines[] = '  (none)';
		}
		$lines[] = '';
		$lines[] = 'LEAD-TIME EXPOSURE (computed: lead time exceeds weeks until required-on-site):';
		if ( $lead_risk ) {
			usort( $lead_risk, static function ( $a, $b ) {
				return $b['lead_gap_wks'] <=> $a['lead_gap_wks'];
			} );
			foreach ( $lead_risk as $r ) {
				$lines[] = sprintf( '  - %s | %s | required on site %s | lead %s wks | short by ~%s wks | ball: %s', $r['number'], $r['title'], $r['on_site'], $r['lead_wks'], $r['lead_gap_wks'], $r['ball'] );
			}
		} else {
			$lines[] = '  (none computed — check that lead time and required-on-site columns are present)';
		}
		$lines[] = '';
		$lines[] = 'BALL-IN-COURT COUNTS:';
		arsort( $ball );
		foreach ( $ball as $party => $count ) {
			$lines[] = sprintf( '  - %s: %d', $party, $count );
		}
		$lines[] = '';
		$lines[] = 'ALL ITEMS:';
		foreach ( $rows as $r ) {
			$lines[] = sprintf(
				'  - %s | %s | spec %s | due %s | on-site %s | lead %s wks | ball %s',
				$r['number'],
				$r['title'],
				'' !== $r['spec'] ? $r['spec'] : 'TBD',
				'' !== $r['due'] ? $r['due'] : 'n/a',
				'' !== $r['on_site'] ? $r['on_site'] : 'n/a',
				null !== $r['lead_wks'] ? $r['lead_wks'] : 'n/a',
				$r['ball']
			);
		}
		if ( $analyzed['missing'] ) {
			$lines[] = '';
			$lines[] = 'COLUMNS NOT DETECTED IN UPLOAD: ' . implode( ', ', $analyzed['missing'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build the risk-read .xlsx.
	 *
	 * @param array              $analyzed Analysis result.
	 * @param \DateTimeImmutable $today    Reference date.
	 * @return string
	 */
	private function build_xlsx( $analyzed, $today ) {
		$rows = $analyzed['rows'];
		$wb   = new Xlsx_Writer();
		$s    = $wb->add_sheet( 'Risk Read' );

		$wb->add_row(
			$s,
			array(
				Xlsx_Writer::header( 'Submittal No' ),
				Xlsx_Writer::header( 'Title' ),
				Xlsx_Writer::header( 'Spec' ),
				Xlsx_Writer::header( 'Ball In Court' ),
				Xlsx_Writer::header( 'Date Due' ),
				Xlsx_Writer::header( 'Days Overdue' ),
				Xlsx_Writer::header( 'Lead (wks)' ),
				Xlsx_Writer::header( 'Required On Site' ),
				Xlsx_Writer::header( 'Lead Gap (wks)' ),
				Xlsx_Writer::header( 'Flag' ),
			)
		);

		usort(
			$rows,
			static function ( $a, $b ) {
				$rank = static function ( $r ) {
					return $r['is_overdue'] ? 0 : ( $r['at_lead_risk'] ? 1 : 2 );
				};
				if ( $rank( $a ) !== $rank( $b ) ) {
					return $rank( $a ) <=> $rank( $b );
				}
				return ( (int) $b['days_overdue'] ) <=> ( (int) $a['days_overdue'] );
			}
		);

		foreach ( $rows as $r ) {
			$flag = $r['is_overdue'] ? 'OVERDUE' : ( $r['at_lead_risk'] ? 'LEAD RISK' : '' );
			$wb->add_row(
				$s,
				array(
					Xlsx_Writer::text( $r['number'] ),
					Xlsx_Writer::text( $r['title'] ),
					Xlsx_Writer::text( $r['spec'] ),
					Xlsx_Writer::text( $r['ball'] ),
					Xlsx_Writer::text( $r['due'] ),
					null !== $r['days_overdue'] ? Xlsx_Writer::num( $r['days_overdue'] ) : Xlsx_Writer::text( '' ),
					null !== $r['lead_wks'] ? Xlsx_Writer::num( $r['lead_wks'] ) : Xlsx_Writer::text( '' ),
					Xlsx_Writer::text( $r['on_site'] ),
					null !== $r['lead_gap_wks'] ? Xlsx_Writer::num( $r['lead_gap_wks'] ) : Xlsx_Writer::text( '' ),
					$r['is_overdue'] || $r['at_lead_risk'] ? Xlsx_Writer::bold( $flag ) : Xlsx_Writer::text( $flag ),
				)
			);
		}

		$wb->set_widths( $s, array( 14, 34, 12, 16, 12, 13, 10, 16, 13, 12 ) );
		$wb->freeze_header( $s );
		return $wb->output();
	}
}
