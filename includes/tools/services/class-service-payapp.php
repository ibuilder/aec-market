<?php
/**
 * G702/G703 Pay-App Processor service.
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
 * Rolls a prior G703 forward and recomputes the G702 summary. All arithmetic is
 * done in PHP and written as literal values into a real .xlsx.
 */
class Service_Payapp extends Abstract_Service {

	/**
	 * Column aliases.
	 *
	 * @var array
	 */
	private $aliases = array(
		'item'          => array( 'item no', 'item no.', 'item', 'item number', 'no', 'no.', '#', 'line', 'line item' ),
		'description'   => array( 'description of work', 'description', 'desc', 'work description', 'scope' ),
		'scheduled'     => array( 'scheduled value', 'scheduled value ($)', 'scheduled', 'contract value', 'sched value', 'value' ),
		'from_previous' => array( 'work completed from previous application', 'from previous', 'from previous application', 'previously completed', 'previous', 'prior completed', 'completed from previous', 'prior' ),
		'this_period'   => array( 'work completed this period', 'this period', 'current period', 'this application', 'period', 'current' ),
		'stored'        => array( 'materials presently stored', 'stored materials', 'stored', 'present stored materials', 'materials stored' ),
	);

	/**
	 * {@inheritDoc}
	 */
	public function key() {
		return 'payapp';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name() {
		return __( 'G702/G703 Pay-App Processor', 'aec-market' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function blurb() {
		return __( "Roll a prior pay app forward into this period's G703 continuation sheet + G702 summary.", 'aec-market' );
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
		return 'g703-prior.csv';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields() {
		return array(
			array( 'name' => 'period', 'label' => __( 'Period ending', 'aec-market' ), 'type' => 'text', 'placeholder' => '2026-07-31', 'sample' => '2026-07-31' ),
			array( 'name' => 'retainage_pct', 'label' => __( 'Retainage %', 'aec-market' ), 'type' => 'text', 'placeholder' => '5', 'sample' => '5' ),
			array( 'name' => 'original_contract', 'label' => __( 'Original Contract Sum ($, optional)', 'aec-market' ), 'type' => 'text', 'placeholder' => 'auto = sum of scheduled values' ),
			array( 'name' => 'net_change_orders', 'label' => __( 'Net change orders to date ($, optional)', 'aec-market' ), 'type' => 'text', 'placeholder' => '0' ),
			array( 'name' => 'previous_payments', 'label' => __( 'Less previous certificates for payment ($, optional)', 'aec-market' ), 'type' => 'text', 'placeholder' => "prior period's total earned less retainage", 'sample' => '1577000' ),
			array( 'name' => 'file', 'label' => __( 'Upload prior G703 continuation sheet (CSV or XLSX)', 'aec-market' ), 'type' => 'file', 'accept' => '.csv,.xlsx,.xlsm' ),
			array( 'name' => 'paste', 'label' => __( '…or paste it here (CSV)', 'aec-market' ), 'type' => 'textarea', 'full' => true, 'is_paste' => true, 'placeholder' => 'Item No, Description, Scheduled Value, From Previous, This Period, Stored Materials' ),
		);
	}

	/**
	 * System prompt.
	 *
	 * @return string
	 */
	private function system_prompt() {
		return "You are a senior construction project manager writing the cover note for a G702/G703 payment application you are about to submit. You have been given the fully computed numbers (every line's total-completed-and-stored, %, and balance, plus the G702 rollup) and a list of automatically detected anomalies. Do NOT recompute anything or invent line items or numbers not in the data.

Write a concise, paste-ready narrative (plain text, no markdown code fences):

PAY APPLICATION — COVER NOTE
Period: <period>
Application summary in one line: current payment due of \$X on a contract sum to date of \$Y (Z% complete).

WHAT CHANGED THIS PERIOD
- 2-4 bullets on the largest this-period billings by line item.

FLAGS TO RESOLVE BEFORE SUBMITTING
- List each anomaly provided. If none, say \"None detected — figures tie out.\" Always state schedule/cost impact plainly.

NOTES
- State any assumptions the tool made (e.g. approximated previous certificates).

Keep it tight and professional. This is a drafting aid; the PM certifies the actual G702.";
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
	 * {@inheritDoc}
	 */
	public function run( array $form ) {
		list( $rows, $fieldnames ) = Tabular::load( $form );
		$period = isset( $form['period'] ) ? trim( (string) $form['period'] ) : '';

		$computed = $this->compute( $rows, $fieldnames, $form );

		$xlsx      = $this->build_xlsx( $computed, $period );
		$narrative = Anthropic::ask( $this->system_prompt(), $this->facts( $computed, $period ), 1500, $this->model() );
		$safe      = preg_replace( '/[\/\s]+/', '', $period ) ?: 'current';

		return new Tool_Result(
			$narrative,
			$xlsx,
			'G702-G703-payapp-' . $safe . '.xlsx',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
		);
	}

	/**
	 * Compute the G703 lines and G702 rollup.
	 *
	 * @param array $rows       Parsed rows.
	 * @param array $fieldnames Headers.
	 * @param array $form       Inputs.
	 * @return array
	 *
	 * @throws \RuntimeException When the scheduled-value column is missing.
	 */
	public function compute( $rows, $fieldnames, $form ) {
		list( $get, $map, $missing ) = Tabular::mapper( $fieldnames, $this->aliases );
		if ( ! isset( $map['scheduled'] ) ) {
			throw new \RuntimeException(
				esc_html__( "Couldn't find a 'Scheduled Value' column. Include columns like: Item No, Description, Scheduled Value, From Previous, This Period, Stored Materials.", 'aec-market' )
			);
		}

		$ret_pct  = Tabular::money( isset( $form['retainage_pct'] ) ? $form['retainage_pct'] : '' );
		$ret_frac = $ret_pct ? $ret_pct / 100.0 : 0.0;

		$lines = array();
		$tot   = array_fill_keys( array( 'scheduled', 'from_previous', 'this_period', 'stored', 'total', 'balance', 'retainage' ), 0.0 );
		$flags = array();

		$i = 0;
		foreach ( $rows as $r ) {
			++$i;
			$sv     = (float) ( Tabular::money( $get( $r, 'scheduled' ) ) ?? 0 );
			$prev   = (float) ( Tabular::money( $get( $r, 'from_previous' ) ) ?? 0 );
			$cur    = (float) ( Tabular::money( $get( $r, 'this_period' ) ) ?? 0 );
			$stored = (float) ( Tabular::money( $get( $r, 'stored' ) ) ?? 0 );
			$total  = $prev + $cur + $stored;
			$pct    = $sv ? $total / $sv : 0.0;
			$bal    = $sv - $total;
			$line_r = $ret_frac * $total;

			$item = '' !== $get( $r, 'item' ) ? $get( $r, 'item' ) : (string) $i;
			$desc = '' !== $get( $r, 'description' ) ? $get( $r, 'description' ) : '(no description)';

			if ( $sv && $total > $sv + 0.01 ) {
				$flags[] = sprintf( 'Line %s (%s): billed %s against a scheduled value of %s — over by %s (%d%%).', $item, $desc, $this->fmt( $total ), $this->fmt( $sv ), $this->fmt( $total - $sv ), round( $pct * 100 ) );
			}
			if ( $cur < 0 ) {
				$flags[] = sprintf( 'Line %s (%s): negative this-period amount %s.', $item, $desc, $this->fmt( $cur ) );
			}

			$lines[] = compact( 'item', 'desc', 'sv', 'prev', 'cur', 'stored', 'total', 'pct', 'bal' ) + array( 'retainage' => $line_r );

			$tot['scheduled']     += $sv;
			$tot['from_previous'] += $prev;
			$tot['this_period']   += $cur;
			$tot['stored']        += $stored;
			$tot['total']         += $total;
			$tot['balance']       += $bal;
			$tot['retainage']     += $line_r;
		}

		$sum_sv   = $tot['scheduled'];
		$net_co   = (float) ( Tabular::money( isset( $form['net_change_orders'] ) ? $form['net_change_orders'] : '' ) ?? 0 );
		$original = Tabular::money( isset( $form['original_contract'] ) ? $form['original_contract'] : '' );
		if ( null === $original ) {
			$original = $sum_sv - $net_co;
		}

		$contract_sum_to_date   = $original + $net_co;
		$total_completed_stored = $tot['total'];
		$ret_completed          = $ret_frac * ( $tot['from_previous'] + $tot['this_period'] );
		$ret_stored             = $ret_frac * $tot['stored'];
		$total_retainage        = $ret_completed + $ret_stored;
		$earned_less_retainage  = $total_completed_stored - $total_retainage;

		$prev_pay      = Tabular::money( isset( $form['previous_payments'] ) ? $form['previous_payments'] : '' );
		$approximated  = false;
		if ( null === $prev_pay ) {
			$prev_pay     = $tot['from_previous'] - $ret_frac * $tot['from_previous'];
			$approximated = true;
		}

		$current_due     = $earned_less_retainage - $prev_pay;
		$balance_to_fin  = $contract_sum_to_date - $earned_less_retainage;
		$pct_complete    = $contract_sum_to_date ? $total_completed_stored / $contract_sum_to_date : 0.0;

		if ( abs( $contract_sum_to_date - $sum_sv ) > 0.5 ) {
			$flags[] = sprintf( "Contract Sum to Date (%s) doesn't tie to the sum of scheduled values (%s). Confirm change orders are reflected in the line-item scheduled values.", $this->fmt( $contract_sum_to_date ), $this->fmt( $sum_sv ) );
		}
		if ( $current_due < 0 ) {
			$flags[] = sprintf( 'Current Payment Due is negative (%s). Check the previous-certificates figure.', $this->fmt( $current_due ) );
		}
		if ( $approximated ) {
			$flags[] = sprintf( 'Previous certificates for payment were not provided — the tool approximated them as %s (prior completed less retainage). Replace with your actual prior G702 line 6.', $this->fmt( $prev_pay ) );
		}
		if ( $missing ) {
			$flags[] = 'Columns not detected (treated as 0/blank): ' . implode( ', ', $missing ) . '.';
		}

		$g702 = array(
			'original_contract'                => $original,
			'net_change_orders'                => $net_co,
			'contract_sum_to_date'             => $contract_sum_to_date,
			'total_completed_stored'           => $total_completed_stored,
			'retainage_completed'              => $ret_completed,
			'retainage_stored'                 => $ret_stored,
			'total_retainage'                  => $total_retainage,
			'total_earned_less_retainage'      => $earned_less_retainage,
			'previous_payments'                => $prev_pay,
			'current_payment_due'              => $current_due,
			'balance_to_finish_incl_retainage' => $balance_to_fin,
			'pct_complete'                     => $pct_complete,
			'retainage_pct'                    => $ret_frac * 100,
		);

		return array( 'lines' => $lines, 'tot' => $tot, 'g702' => $g702, 'flags' => $flags );
	}

	/**
	 * Build the G703 + G702 workbook.
	 *
	 * @param array  $computed Compute output.
	 * @param string $period   Period label.
	 * @return string
	 */
	private function build_xlsx( $computed, $period ) {
		$lines = $computed['lines'];
		$tot   = $computed['tot'];
		$g702  = $computed['g702'];

		$wb  = new Xlsx_Writer();
		$g73 = $wb->add_sheet( 'G703' );
		$wb->add_row(
			$g73,
			array_map(
				array( Xlsx_Writer::class, 'header' ),
				array( 'Item No', 'Description of Work', 'Scheduled Value', 'From Previous Application', 'This Period', 'Materials Stored', 'Total Completed & Stored', '%', 'Balance to Finish', 'Retainage' )
			)
		);
		foreach ( $lines as $ln ) {
			$wb->add_row(
				$g73,
				array(
					Xlsx_Writer::text( $ln['item'] ),
					Xlsx_Writer::text( $ln['desc'] ),
					Xlsx_Writer::money( $ln['sv'] ),
					Xlsx_Writer::money( $ln['prev'] ),
					Xlsx_Writer::money( $ln['cur'] ),
					Xlsx_Writer::money( $ln['stored'] ),
					Xlsx_Writer::money( $ln['total'] ),
					Xlsx_Writer::percent( $ln['pct'] ),
					Xlsx_Writer::money( $ln['bal'] ),
					Xlsx_Writer::money( $ln['retainage'] ),
				)
			);
		}
		$wb->add_row(
			$g73,
			array(
				Xlsx_Writer::text( '' ),
				Xlsx_Writer::bold( 'TOTALS' ),
				Xlsx_Writer::money( $tot['scheduled'], true ),
				Xlsx_Writer::money( $tot['from_previous'], true ),
				Xlsx_Writer::money( $tot['this_period'], true ),
				Xlsx_Writer::money( $tot['stored'], true ),
				Xlsx_Writer::money( $tot['total'], true ),
				Xlsx_Writer::percent( $tot['scheduled'] ? $tot['total'] / $tot['scheduled'] : 0 ),
				Xlsx_Writer::money( $tot['balance'], true ),
				Xlsx_Writer::money( $tot['retainage'], true ),
			)
		);
		$wb->set_widths( $g73, array( 10, 34, 16, 18, 14, 14, 18, 8, 16, 12 ) );
		$wb->freeze_header( $g73 );

		$g72 = $wb->add_sheet( 'G702' );
		$wb->add_row( $g72, array( Xlsx_Writer::bold( 'APPLICATION AND CERTIFICATE FOR PAYMENT (G702)' ) ) );
		$wb->add_row( $g72, array( Xlsx_Writer::text( 'Period: ' . ( '' !== $period ? $period : 'TBD' ) ) ) );
		$wb->add_row( $g72, array( Xlsx_Writer::text( '' ) ) );

		$rp   = $g702['retainage_pct'];
		$rows = array(
			array( '1. Original Contract Sum', $g702['original_contract'], false ),
			array( '2. Net change by Change Orders', $g702['net_change_orders'], false ),
			array( '3. Contract Sum to Date (1 + 2)', $g702['contract_sum_to_date'], true ),
			array( '4. Total Completed & Stored to Date', $g702['total_completed_stored'], false ),
			array( sprintf( '5a. Retainage on completed work (%.1f%%)', $rp ), $g702['retainage_completed'], false ),
			array( sprintf( '5b. Retainage on stored material (%.1f%%)', $rp ), $g702['retainage_stored'], false ),
			array( '5. Total Retainage', $g702['total_retainage'], false ),
			array( '6. Total Earned Less Retainage (4 - 5)', $g702['total_earned_less_retainage'], true ),
			array( '7. Less Previous Certificates for Payment', $g702['previous_payments'], false ),
			array( '8. CURRENT PAYMENT DUE (6 - 7)', $g702['current_payment_due'], true ),
			array( '9. Balance to Finish Including Retainage (3 - 6)', $g702['balance_to_finish_incl_retainage'], false ),
		);
		foreach ( $rows as $row ) {
			list( $label, $val, $bold ) = $row;
			$wb->add_row(
				$g72,
				array(
					$bold ? Xlsx_Writer::bold( $label ) : Xlsx_Writer::text( $label ),
					Xlsx_Writer::money( $val, $bold ),
				)
			);
		}
		$wb->add_row( $g72, array( Xlsx_Writer::text( '% Complete' ), Xlsx_Writer::percent( $g702['pct_complete'] ) ) );
		$wb->set_widths( $g72, array( 46, 18 ) );

		return $wb->output();
	}

	/**
	 * Build the fact sheet for the model.
	 *
	 * @param array  $computed Compute output.
	 * @param string $period   Period label.
	 * @return string
	 */
	private function facts( $computed, $period ) {
		$lines = $computed['lines'];
		$g702  = $computed['g702'];
		$flags = $computed['flags'];

		usort(
			$lines,
			static function ( $a, $b ) {
				return $b['cur'] <=> $a['cur'];
			}
		);
		$top = array_slice( $lines, 0, 5 );

		$parts   = array();
		$parts[] = 'PERIOD: ' . ( '' !== $period ? $period : 'TBD' );
		$parts[] = 'CONTRACT SUM TO DATE: ' . $this->fmt( $g702['contract_sum_to_date'] );
		$parts[] = sprintf( 'TOTAL COMPLETED & STORED: %s (%.1f%% complete)', $this->fmt( $g702['total_completed_stored'] ), $g702['pct_complete'] * 100 );
		$parts[] = 'TOTAL RETAINAGE: ' . $this->fmt( $g702['total_retainage'] );
		$parts[] = 'TOTAL EARNED LESS RETAINAGE: ' . $this->fmt( $g702['total_earned_less_retainage'] );
		$parts[] = 'LESS PREVIOUS CERTIFICATES: ' . $this->fmt( $g702['previous_payments'] );
		$parts[] = 'CURRENT PAYMENT DUE: ' . $this->fmt( $g702['current_payment_due'] );
		$parts[] = 'BALANCE TO FINISH INCL RETAINAGE: ' . $this->fmt( $g702['balance_to_finish_incl_retainage'] );
		$parts[] = '';
		$parts[] = 'LARGEST THIS-PERIOD BILLINGS:';
		foreach ( $top as $l ) {
			if ( $l['cur'] ) {
				$parts[] = sprintf( '  - Line %s %s: %s this period (%d%% complete)', $l['item'], $l['desc'], $this->fmt( $l['cur'] ), round( $l['pct'] * 100 ) );
			}
		}
		$parts[] = '';
		$parts[] = 'DETECTED ANOMALIES:';
		if ( $flags ) {
			foreach ( $flags as $f ) {
				$parts[] = '  - ' . $f;
			}
		} else {
			$parts[] = '  (none)';
		}

		return implode( "\n", $parts );
	}
}
