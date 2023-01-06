<?php
/**
 * Line diff for the review screen.
 *
 * @package ContentRelay
 */

namespace ContentRelay\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Unified-style line diff with context, bounded in time and size so a very long page cannot stall a request.
 *
 * Common leading and trailing lines are trimmed first; the rest is compared with an LCS table only when it is small
 * enough (`$max_cells`), otherwise it is shown as removed-then-added.
 */
final class Line_Diff {

	/**
	 * Largest LCS table (old lines × new lines) computed.
	 */
	public const MAX_CELLS = 250000;

	/**
	 * Unchanged lines shown around each change.
	 */
	public const CONTEXT = 2;

	/**
	 * Most lines returned across all hunks.
	 */
	public const MAX_LINES = 400;

	/**
	 * Longest line returned, in bytes; longer lines are cut.
	 */
	public const MAX_LINE_BYTES = 2000;

	/**
	 * Bytes shown before the first difference when a long changed line is cut.
	 */
	private const FOCUS_LEAD = 200;

	/**
	 * Diffs two texts.
	 *
	 * @param string $current   Current text.
	 * @param string $incoming  Incoming text.
	 * @param int    $max_cells Largest LCS table computed.
	 * @return array{changed: bool, added: int, removed: int, truncated: bool, hunks: array<int, array{old_start: int, new_start: int, lines: array<int, array{0: string, 1: string}>}>}
	 */
	public static function diff( string $current, string $incoming, int $max_cells = self::MAX_CELLS ): array {
		$result = array(
			'changed'   => false,
			'added'     => 0,
			'removed'   => 0,
			'truncated' => false,
			'hunks'     => array(),
		);
		if ( $current === $incoming ) {
			return $result;
		}

		$ops   = self::operations( explode( "\n", $current ), explode( "\n", $incoming ), $max_cells );
		$last  = count( $ops ) - 1;
		$focus = self::focus( $ops );

		$changes = array();
		foreach ( $ops as $index => $op ) {
			if ( ' ' !== $op[0] ) {
				$changes[] = $index;
				if ( '+' === $op[0] ) {
					++$result['added'];
				} else {
					++$result['removed'];
				}
			}
		}
		$result['changed'] = true;

		$keep = array();
		foreach ( $changes as $index ) {
			$to = min( $last, $index + self::CONTEXT );
			for ( $k = max( 0, $index - self::CONTEXT ); $k <= $to; $k++ ) {
				$keep[ $k ] = true;
			}
		}
		ksort( $keep );

		$emitted  = 0;
		$previous = -2;
		$hunk     = null;
		foreach ( array_keys( $keep ) as $index ) {
			if ( $emitted >= self::MAX_LINES ) {
				$result['truncated'] = true;
				break;
			}

			$op = $ops[ $index ];
			if ( $index !== $previous + 1 || null === $hunk ) {
				if ( null !== $hunk ) {
					$result['hunks'][] = $hunk;
				}
				$hunk = array(
					'old_start' => $op[2],
					'new_start' => $op[3],
					'lines'     => array(),
				);
			}

			$text = $op[1];
			if ( strlen( $text ) > self::MAX_LINE_BYTES ) {
				// Cut around the first difference with the line this one replaces, so a change deep inside a long
				// block-comment line stays visible.
				$start               = max( 0, min( ( $focus[ $index ] ?? 0 ) - self::FOCUS_LEAD, strlen( $text ) - self::MAX_LINE_BYTES ) );
				$text                = ( $start > 0 ? '…' : '' ) . substr( $text, $start, self::MAX_LINE_BYTES ) . ( $start + self::MAX_LINE_BYTES < strlen( $op[1] ) ? '…' : '' );
				$result['truncated'] = true;
			}
			$hunk['lines'][] = array( $op[0], $text );
			$previous        = $index;
			++$emitted;
		}
		if ( null !== $hunk ) {
			$result['hunks'][] = $hunk;
		}

		return $result;
	}

	/**
	 * Byte offset of the first difference for each changed line that replaces another line.
	 *
	 * A run of removed lines directly followed by added lines is paired line by line.
	 *
	 * @param array<int, array{0: string, 1: string, 2: int, 3: int}> $ops Edit script.
	 * @return array<int, int> Op index => offset.
	 */
	private static function focus( array $ops ): array {
		$focus = array();
		$count = count( $ops );
		$index = 0;
		while ( $index < $count ) {
			if ( '-' !== $ops[ $index ][0] ) {
				++$index;
				continue;
			}

			$removed = $index;
			while ( $index < $count && '-' === $ops[ $index ][0] ) {
				++$index;
			}
			$added = $index;
			while ( $index < $count && '+' === $ops[ $index ][0] ) {
				++$index;
			}

			$pairs = min( $added - $removed, $index - $added );
			for ( $k = 0; $k < $pairs; $k++ ) {
				$offset                 = strspn( $ops[ $removed + $k ][1] ^ $ops[ $added + $k ][1], "\0" );
				$focus[ $removed + $k ] = $offset;
				$focus[ $added + $k ]   = $offset;
			}
		}

		return $focus;
	}

	/**
	 * Edit script between two line lists.
	 *
	 * @param string[] $a         Old lines.
	 * @param string[] $b         New lines.
	 * @param int      $max_cells Largest LCS table computed.
	 * @return array<int, array{0: string, 1: string, 2: int, 3: int}> Op (` `, `-`, `+`), text, old line number, new line number.
	 */
	private static function operations( array $a, array $b, int $max_cells ): array {
		$n      = count( $a );
		$m      = count( $b );
		$prefix = 0;
		while ( $prefix < $n && $prefix < $m && $a[ $prefix ] === $b[ $prefix ] ) {
			++$prefix;
		}
		$suffix = 0;
		while ( $suffix < $n - $prefix && $suffix < $m - $prefix && $a[ $n - 1 - $suffix ] === $b[ $m - 1 - $suffix ] ) {
			++$suffix;
		}

		$middle_a = array_slice( $a, $prefix, $n - $prefix - $suffix );
		$middle_b = array_slice( $b, $prefix, $m - $prefix - $suffix );

		$script = array();
		for ( $i = 0; $i < $prefix; $i++ ) {
			$script[] = array( ' ', $a[ $i ] );
		}
		foreach ( self::middle( $middle_a, $middle_b, $max_cells ) as $op ) {
			$script[] = $op;
		}
		for ( $i = $n - $suffix; $i < $n; $i++ ) {
			$script[] = array( ' ', $a[ $i ] );
		}

		$ops    = array();
		$old_no = 1;
		$new_no = 1;
		foreach ( $script as $op ) {
			$ops[] = array( $op[0], $op[1], $old_no, $new_no );
			if ( '+' !== $op[0] ) {
				++$old_no;
			}
			if ( '-' !== $op[0] ) {
				++$new_no;
			}
		}

		return $ops;
	}
}
