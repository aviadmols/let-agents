<?php

namespace Rega\Storefront;

use Rega\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Sends Rega the store's past orders once, so it can learn what sells together from before it
 * was installed.
 *
 * Each order has exactly the shape OrderReporter sends at checkout — a keyed hash of the order
 * ID, totals, product IDs with quantities and line totals — without the visitor ID, which a
 * past order never had. Names, emails, phones, addresses, payment details, notes and coupons are
 * never read. An order Rega already has (the same hash) is not stored twice.
 *
 * Paid orders (processing, completed) from the last MONTHS months, PER_PAGE at a time, each page
 * in its own Action Scheduler job, so a store with years of orders never blocks a request.
 * It starts by itself once the store is connected, and the settings page can start it again.
 */
final class OrderHistory {

	public const ACTION = 'rega_order_history_page';

	public const OPTION = 'rega_order_history';

	public const MONTHS = 24;

	private const PER_PAGE = 50;

	private const STATUSES = array( 'wc-processing', 'wc-completed' );

	/** A page that could not be delivered is tried again this many times, a few minutes apart. */
	private const MAX_TRIES = 5;

	public static function register(): void {
		add_action( self::ACTION, array( self::class, 'send_page' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'start_once' ) );
	}

	/**
	 * What the settings page shows.
	 *
	 * @return array{status: string, expected: int, sent: int, started_at: int, finished_at: int}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );

		return array(
			'status'      => is_array( $state ) && isset( $state['status'] ) ? (string) $state['status'] : 'not_started',
			'expected'    => is_array( $state ) ? (int) ( $state['expected'] ?? 0 ) : 0,
			'sent'        => is_array( $state ) ? (int) ( $state['sent'] ?? 0 ) : 0,
			'started_at'  => is_array( $state ) ? (int) ( $state['started_at'] ?? 0 ) : 0,
			'finished_at' => is_array( $state ) ? (int) ( $state['finished_at'] ?? 0 ) : 0,
		);
	}

	/** The first time an admin opens WordPress with the store connected. */
	public static function start_once(): void {
		if ( 'not_started' === self::state()['status'] ) {
			self::start();
		}
	}

	/** From the start, again: an order Rega already has is not stored twice. */
	public static function start(): bool {
		if ( ! self::can_send() ) {
			return false;
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array(), 'rega' );
		}

		$first = self::query( 1, 1 );

		update_option(
			self::OPTION,
			array(
				'status'     => 'running',
				'expected'   => (int) $first->total,
				'sent'       => 0,
				'started_at' => time(),
			),
			false
		);

		self::schedule( 1, 0 );

		return true;
	}

	/**
	 * One page of orders, from the Action Scheduler job.
	 *
	 * @param int|string $page
	 * @param int|string $tries
	 */
	public static function send_page( $page, $tries = 0 ): void {
		$page  = max( 1, (int) $page );
		$tries = (int) $tries;
		$state = get_option( self::OPTION, array() );

		if ( ! is_array( $state ) || 'running' !== ( $state['status'] ?? '' ) || ! self::can_send() ) {
			return;
		}

		$result = self::query( $page, self::PER_PAGE );
		$orders = array();

		foreach ( $result->orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$payload = OrderReporter::payload( $order, null );

				if ( array() !== $payload['items'] ) {
					unset( $payload['vid'] );
					$orders[] = $payload;
				}
			}
		}

		$last = $page >= (int) $result->max_num_pages;
		$sent = RegaApi::post(
			'orders/history',
			array(
				'orders'   => $orders,
				'first'    => 1 === $page,
				'last'     => $last,
				'expected' => (int) ( $state['expected'] ?? 0 ),
			)
		);

		if ( ! $sent ) {
			if ( $tries + 1 >= self::MAX_TRIES ) {
				$state['status'] = 'failed';
				update_option( self::OPTION, $state, false );

				return;
			}

			self::schedule( $page, $tries + 1, 5 * MINUTE_IN_SECONDS );

			return;
		}

		$state['sent'] = (int) ( $state['sent'] ?? 0 ) + count( $orders );

		if ( $last ) {
			$state['status']      = 'done';
			$state['finished_at'] = time();
		}

		update_option( self::OPTION, $state, false );

		if ( ! $last ) {
			self::schedule( $page + 1, 0 );
		}
	}

	private static function can_send(): bool {
		return function_exists( 'wc_get_orders' ) && function_exists( 'as_enqueue_async_action' )
			&& 'off' !== Settings::widget_mode() && null !== SiteKeys::site();
	}

	private static function schedule( int $page, int $tries, int $delay = 0 ): void {
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::ACTION, array( $page, $tries ), 'rega' );

			return;
		}

		as_enqueue_async_action( self::ACTION, array( $page, $tries ), 'rega' );
	}

	/**
	 * Oldest first, so a page means the same orders however long the sending takes: new orders
	 * land on the last page, and OrderReporter reports them anyway.
	 *
	 * @return object{orders: list<\WC_Order>, total: int, max_num_pages: int}
	 */
	private static function query( int $page, int $per_page ): object {
		$result = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::STATUSES,
				'date_created' => '>=' . strtotime( '-' . self::MONTHS . ' months' ),
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'limit'        => $per_page,
				'paged'        => $page,
				'paginate'     => true,
			)
		);

		return is_object( $result ) ? $result : (object) array( 'orders' => array(), 'total' => 0, 'max_num_pages' => 0 );
	}
}
