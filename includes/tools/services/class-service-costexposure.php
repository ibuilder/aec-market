<?php
/**
 * Change-Event -> Cost-Exposure Report service.
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
 * Turns a change-event export into a cost-exposure summary (.xlsx) + OAC narrative.
 */
class Service_Costexposure extends Abstract_Service {

	/**
	 * Column aliases.
	 *
	 * @var array
	 */
	private $aliases = array(
		'number' => array( 'number', 'no', 'no.', 'change event', 'change event #', 'ce', 'ce #', 'id', 'cor', 'pco', 'pco #', 'item' ),
		'title'  => array( 'title', 'description', 'name', 'summary', 'scope', 'change event title' ),
		'trade'  => array( 'trade', 'csi', 'csi division', 'division', 'cost code', 'trade/csi', 'cost code/csi', 'responsible trade', 'subcontractor' ),
		'status' => array( 'status', 'state', 'change event status' ),
		'type'   => array( 'type', 'category', 'change type' ),
		'cost'   => array( 'estimated cost', 'rom', 'rom cost', 'estimated amount', 'cost', 'amount', 'value', 'potential cost', 'budget', 'estimated', 'total' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'costexposure';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'Change-Event → Cost-Exposure Report', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function blurb() {
		return __( 'Turn a change-event export into a clean cost-exposure summary + OAC narrative.', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_credits() {
		return 3;
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
		return 'change-events.csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields() {
		return array(
			array( 'name' => 'project', 'label' => __( 'Project (optional)', 'aec-market' ), 'type' => 'text', 'placeholder' => 'NC Museum — Expansion', 'sample' => 'NC Museum of History — Expansion' ),
			array( 'name' => 'asof', 'label' => __( 'As-of date (optional)', 'aec-market' ), 'type' => 'text', 'placeholder' => '2026-07-14', 'sample' => '2026-07-14' ),
			array( 'name' => 'file', 'label' => __( 'Upload change-event export (CSV or XLSX)', 'aec-market' ), 'type' => 'file', 'accept' => '.csv,.xlsx,.xlsm' ),
			array( 'name' => 'paste', 'label' => __( '…or paste it here (CSV)', 'aec-market' ), 'type' => 'textarea', 'full' => true, 'is_paste' => true, 'placeholder' => 'Number, Title, Trade/CSI, Status, Type, Estimated Cost' ),
		);
	}

	/**
	 * System prompt.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return "You are a senior construction project manager preparing the cost-exposure section of an Owner-Architect-Contractor (OAC) update. You have been given fully computed exposure totals (potential, approved, pending), a by-trade breakdown, and the top pending risks. Do NOT recompute totals or invent change events, trades, or dollar figures not present in the data.

Write a concise, paste-ready narrative (plain text, no markdown code fences):

COST EXPOSURE — OAC UPDATE
As of: <date>  Project: <project>

HEADLINE
- One or two sentences: total potential exposure of \$X across N change events, of which \$Y is approved and \$Z is still pending.

WHERE THE RISK SITS
- 3-5 bullets calling out the trades/divisions carrying the most pending exposure, and the single largest pending items.

RECOMMENDED ACTIONS
- 2-4 crisp actions to convert pending exposure to approved or retire risk.

Always tie exposure back to cost and schedule impact. Keep it factual and owner-ready.";
	}

	/**
	 * Money format helper.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private function fmt( $n ) {
		return '$' . number_format( (float) $n, 2 );
	}

	/**
	 * Bucket a status string.
	 *
	 * @param string $status Raw status.
	 * @return string approved|pending|void
	 */
	private function bucket( $status ) {
		$s = strtolower( (string) $status );
		foreach ( array( 'void', 'reject', 'denied', 'withdraw', 'cancel', 'not proceed', 'n/a' ) as $k ) {
			if ( false !== strpos( $s, $k ) ) {
				return 'void';
			}
		}
		foreach ( array( 'approv', 'executed', 'final', 'incorporated', 'accepted' ) as $k ) {
			if ( false !== strpos( $s, $k ) ) {
				return 'approved';
			}
		}
		return 'pending';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( array $form ) {
		list( $rows, $fieldnames ) = Tabular::load( $form );
		$project = isset( $form['project'] ) ? trim( (string) $form['project'] ) : '';
		$asof    = isset( $form['asof'] ) ? trim( (string) $form['asof'] ) : '';

		$computed  = $this->compute( $rows, $fieldnames );
		$xlsx      = $this->build_xlsx( $computed, $project, $asof );
		$narrative = Anthropic::ask( $this->system_prompt(), $this->facts( $computed, $project, $asof ), 1500, $this->model() );
		$safe      = substr( preg_replace( '/[\/\s]+/', '-', $project ? $project : 'cost-exposure' ), 0, 40 );

		return new Tool_Result(
			$narrative,
			$xlsx,
			'cost-exposure-' . $safe . '.xlsx',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}

	/**
	 * Compute exposure totals and breakdowns.
	 *
	 * @param array $rows       Parsed rows.
	 * @param array $fieldnames Headers.
	 * @return array
	 *
	 * @throws \RuntimeException When the cost column is missing.
	 */
	public function compute( $rows, $fieldnames ) {
		list( $get, $map, $missing ) = Tabular::mapper( $fieldnames, $this->aliases );
		if ( ! isset( $map['cost'] ) ) {
			throw new \RuntimeException(
				esc_html__( "Couldn't find a cost/estimate column. Include a column like 'Estimated Cost', 'ROM', or 'Amount'.", 'aec-market' )
			);
		}

		$items = array();
		$i     = 0;
		foreach ( $rows as $r ) {
			++$i;
			$status  = '' !== $get( $r, 'status' ) ? $get( $r, 'status' ) : 'Pending';
			$items[] = array(
				'number' => '' !== $get( $r, 'number' ) ? $get( $r, 'number' ) : (string) $i,
				'title'  => '' !== $get( $r, 'title' ) ? $get( $r, 'title' ) : '(untitled)',
				'trade'  => '' !== $get( $r, 'trade' ) ? $get( $r, 'trade' ) : 'Unassigned',
				'status' => $status,
				'bucket' => $this->bucket( $status ),
				'type'   => $get( $r, 'type' ),
				'cost'   => (float) ( Tabular::money( $get( $r, 'cost' ) ) ?? 0 ),
			);
		}

		$active   = array_values( array_filter( $items, static function ( $it ) {
			return 'void' !== $it['bucket'];
		} ) );
		$approved = array_filter( $active, static function ( $it ) {
			return 'approved' === $it['bucket'];
		} );
		$pending  = array_values( array_filter( $active, static function ( $it ) {
			return 'pending' === $it['bucket'];
		} ) );

		$sum = static function ( $arr ) {
			$t = 0.0;
			foreach ( $arr as $it ) {
				$t += $it['cost'];
			}
			return $t;
		};

		$by_trade = array();
		foreach ( $active as $it ) {
			if ( ! isset( $by_trade[ $it['trade'] ] ) ) {
				$by_trade[ $it['trade'] ] = array( 'count' => 0, 'total' => 0.0, 'approved' => 0.0, 'pending' => 0.0 );
			}
			$by_trade[ $it['trade'] ]['count']++;
			$by_trade[ $it['trade'] ]['total']            += $it['cost'];
			$by_trade[ $it['trade'] ][ $it['bucket'] ]    += $it['cost'];
		}
		uasort( $by_trade, static function ( $a, $b ) {
			return $b['total'] <=> $a['total'];
		} );

		usort( $pending, static function ( $a, $b ) {
			return $b['cost'] <=> $a['cost'];
		} );
		$top_pending = array_slice( $pending, 0, 8 );

		$summary = array(
			'count_all'       => count( $items ),
			'count_active'    => count( $active ),
			'count_approved'  => count( $approved ),
			'count_pending'   => count( $pending ),
			'count_void'      => count( $items ) - count( $active ),
			'total_potential' => $sum( $active ),
			'approved_total'  => $sum( $approved ),
			'pending_total'   => $sum( $pending ),
		);

		return array(
			'items'       => $items,
			'summary'     => $summary,
			'by_trade'    => $by_trade,
			'top_pending' => $top_pending,
			'missing'     => $missing,
		);
	}

	/**
	 * Build the summary / by-trade / detail workbook.
	 *
	 * @param array  $c       Compute output.
	 * @param string $project Project label.
	 * @param string $asof    As-of date.
	 * @return string
	 */
	private function build_xlsx( $c, $project, $asof ) {
		$summary  = $c['summary'];
		$by_trade = $c['by_trade'];
		$items    = $c['items'];

		$wb = new Xlsx_Writer();

		$s = $wb->add_sheet( 'Summary' );
		$wb->add_row( $s, array( Xlsx_Writer::bold( 'COST EXPOSURE SUMMARY' ) ) );
		$wb->add_row( $s, array( Xlsx_Writer::text( 'Project: ' . ( '' !== $project ? $project : 'TBD' ) ) ) );
		$wb->add_row( $s, array( Xlsx_Writer::text( 'As of: ' . ( '' !== $asof ? $asof : 'TBD' ) ) ) );
		$wb->add_row( $s, array( Xlsx_Writer::text( '' ) ) );
		$rows = array(
			array( 'Total potential exposure (active)', $summary['total_potential'], true, true ),
			array( '  Approved', $summary['approved_total'], false, true ),
			array( '  Pending', $summary['pending_total'], false, true ),
			array( 'Change events — total', $summary['count_all'], false, false ),
			array( 'Change events — active', $summary['count_active'], false, false ),
			array( 'Change events — approved', $summary['count_approved'], false, false ),
			array( 'Change events — pending', $summary['count_pending'], false, false ),
			array( 'Change events — void/rejected (excluded)', $summary['count_void'], false, false ),
		);
		foreach ( $rows as $row ) {
			list( $label, $val, $bold, $money ) = $row;
			$wb->add_row(
				$s,
				array(
					$bold ? Xlsx_Writer::bold( $label ) : Xlsx_Writer::text( $label ),
					$money ? Xlsx_Writer::money( $val, $bold ) : Xlsx_Writer::num( $val ),
				)
			);
		}
		$wb->set_widths( $s, array( 44, 18 ) );

		$t = $wb->add_sheet( 'By Trade' );
		$wb->add_row( $t, array_map( array( Xlsx_Writer::class, 'header' ), array( 'Trade / CSI Division', 'Count', 'Total Exposure', 'Approved', 'Pending' ) ) );
		foreach ( $by_trade as $trade => $d ) {
			$wb->add_row(
				$t,
				array(
					Xlsx_Writer::text( $trade ),
					Xlsx_Writer::num( $d['count'] ),
					Xlsx_Writer::money( $d['total'] ),
					Xlsx_Writer::money( $d['approved'] ),
					Xlsx_Writer::money( $d['pending'] ),
				)
			);
		}
		$wb->set_widths( $t, array( 34, 8, 16, 16, 16 ) );
		$wb->freeze_header( $t );

		$d = $wb->add_sheet( 'Detail' );
		$wb->add_row( $d, array_map( array( Xlsx_Writer::class, 'header' ), array( 'Number', 'Title', 'Trade / CSI', 'Status', 'Bucket', 'Type', 'Estimated Cost' ) ) );
		usort( $items, static function ( $a, $b ) {
			return $b['cost'] <=> $a['cost'];
		} );
		foreach ( $items as $it ) {
			$wb->add_row(
				$d,
				array(
					Xlsx_Writer::text( $it['number'] ),
					Xlsx_Writer::text( $it['title'] ),
					Xlsx_Writer::text( $it['trade'] ),
					Xlsx_Writer::text( $it['status'] ),
					Xlsx_Writer::text( $it['bucket'] ),
					Xlsx_Writer::text( $it['type'] ),
					Xlsx_Writer::money( $it['cost'] ),
				)
			);
		}
		$wb->set_widths( $d, array( 12, 40, 26, 16, 12, 16, 16 ) );
		$wb->freeze_header( $d );

		return $wb->output();
	}

	/**
	 * Fact sheet for the model.
	 *
	 * @param array  $c       Compute output.
	 * @param string $project Project.
	 * @param string $asof    As-of date.
	 * @return string
	 */
	private function facts( $c, $project, $asof ) {
		$summary     = $c['summary'];
		$by_trade    = $c['by_trade'];
		$top_pending = $c['top_pending'];
		$missing     = $c['missing'];

		$parts   = array();
		$parts[] = 'PROJECT: ' . ( '' !== $project ? $project : 'TBD' );
		$parts[] = 'AS OF: ' . ( '' !== $asof ? $asof : 'TBD' );
		$parts[] = sprintf( 'TOTAL POTENTIAL EXPOSURE (active): %s across %d change events', $this->fmt( $summary['total_potential'] ), $summary['count_active'] );
		$parts[] = sprintf( '  APPROVED: %s (%d events)', $this->fmt( $summary['approved_total'] ), $summary['count_approved'] );
		$parts[] = sprintf( '  PENDING: %s (%d events)', $this->fmt( $summary['pending_total'] ), $summary['count_pending'] );
		$parts[] = sprintf( '  VOID/REJECTED (excluded): %d events', $summary['count_void'] );
		$parts[] = '';
		$parts[] = 'EXPOSURE BY TRADE (top by total):';
		$n       = 0;
		foreach ( $by_trade as $trade => $d ) {
			if ( $n++ >= 8 ) {
				break;
			}
			$parts[] = sprintf( '  - %s: total %s (approved %s, pending %s), %d events', $trade, $this->fmt( $d['total'] ), $this->fmt( $d['approved'] ), $this->fmt( $d['pending'] ), $d['count'] );
		}
		$parts[] = '';
		$parts[] = 'TOP PENDING RISKS (largest unapproved):';
		foreach ( $top_pending as $it ) {
			$parts[] = sprintf( '  - %s %s [%s]: %s — status %s', $it['number'], $it['title'], $it['trade'], $this->fmt( $it['cost'] ), $it['status'] );
		}
		if ( $missing ) {
			$parts[] = '';
			$parts[] = 'COLUMNS NOT DETECTED (defaulted): ' . implode( ', ', $missing );
		}

		return implode( "\n", $parts );
	}
}
