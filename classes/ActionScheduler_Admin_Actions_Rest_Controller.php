<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_REST_CRUD_Controller' ) ) {
	return;
}

/**
 * REST API Actions controller class.
 *
 * Class ActionScheduler_Actions_Rest_Controller
 */
class ActionScheduler_Admin_Actions_Rest_Controller extends WC_REST_CRUD_Controller {
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'asa/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'actions';

	/**
	 * Rest controller instance.
	 *
	 * @var ActionScheduler_Actions_Rest_Controller
	  */
	private static $instance = NULL;

	/**
	 * Array of seconds for common time periods, like week or month, alongside an internationalised string representation, i.e. "Day" or "Days"
	 *
	 * @var array
	 */
	private static $time_periods;

	/**
	 * Create an instance.
	 */
	public static function factory() {
		if ( null === self::$instance ) {
			self::$instance = new ActionScheduler_Admin_Actions_Rest_Controller();
		}

		return self::$instance;
	}

	function __construct() {
		self::$time_periods = [
			[
				'seconds' => YEAR_IN_SECONDS,
				'names'   => _n_noop( '%s year', '%s years', 'action-scheduler-admin' ),
			],
			[
				'seconds' => MONTH_IN_SECONDS,
				'names'   => _n_noop( '%s month', '%s months', 'action-scheduler-admin' ),
			],
			[
				'seconds' => WEEK_IN_SECONDS,
				'names'   => _n_noop( '%s week', '%s weeks', 'action-scheduler-admin' ),
			],
			[
				'seconds' => DAY_IN_SECONDS,
				'names'   => _n_noop( '%s day', '%s days', 'action-scheduler-admin' ),
			],
			[
				'seconds' => HOUR_IN_SECONDS,
				'names'   => _n_noop( '%s hour', '%s hours', 'action-scheduler-admin' ),
			],
			[
				'seconds' => MINUTE_IN_SECONDS,
				'names'   => _n_noop( '%s minute', '%s minutes', 'action-scheduler-admin' ),
			],
			[
				'seconds' => 1,
				'names'   => _n_noop( '%s second', '%s seconds', 'action-scheduler-admin' ),
			],
		];
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'  => WP_REST_Server::READABLE,
					'callback' => [ self::$instance, 'get_actions' ],
				],
			]
		);
	}

	/**
	 * Maps query arguments from the REST request.
	 *
	 * @param  WP_REST_Request $request Request array.
	 * @return array
	 */
	protected function prepare_actions_query( $request ) {
		$args = [
			'offset'   => $request[ 'offset' ],
			'per_page' => $request[ 'page' ],
			'group'    => $request[ 'group' ],
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'orderby'  => strtolower( $request[ 'orderby' ] ),
		];

		$args[ 'orderby' ] = in_array( $args[ 'orderby' ], [ 'hook', 'group' ], true ) ? $args[ 'orderby' ] : '';

		return $args;
	}

	/**
	 * Return a list of actions matching the query args. Scheduled date is date used for sorting.
	 *
	 * @return array Of WP_Error or WP_REST_Response.
	 */
	public function get_actions( $request ) {
		$args    = $this->prepare_actions_query( $request );
		$actions = as_get_scheduled_actions( $args );
		$data    = [];

		try {
			$timezone = new DateTimeZone( get_option( 'timezone_string' ) );
		} catch ( Exception $e ) {
			$timezone = false;
		}

		foreach ( $actions as $action_id => $action ) {
			// Display schedule date in more human friendly WP configured time zone.
			$next = $action->get_schedule()->next();
			if ( $timezone ) {
				$next->setTimezone( $timezone );
			}

			$schedule         = new ActionScheduler_SimpleSchedule( $next );
			$schedule_display = $this->get_schedule_display( $schedule );
			$parameters       = [];

			foreach ( $action->get_args() as $key => $value ) {
				$parameters[] = sprintf( '%s => %s', $key, $value );
			}

			$action_data = [
				'id'             => $action_id,
				'hook'           => $action->get_hook(),
				'group'          => $action->get_group(),
				'timestamp'      => $schedule_display['timestamp'],
				'scheduled'      => $schedule_display['date'],
				'schedule_delta' => $schedule_display['delta'],
				'parameters'     => $parameters,
				'recurrence'     => $this->get_recurrence( $action ),
			];

			$key          = ( ! empty( $args[ 'orderby' ] ) ? $action_data[ $args[ 'orderby' ] ] . '_' : '' ) . $next->getTimestamp();
			$data[ $key ] = $action_data;
		}

		if ( 'DESC' === strtoupper( $request['order'] ) ) {
			krsort( $data );
		} else {
			ksort( $data );
		}

		// Reset the array keys to prevent downstream key sorting.
		return rest_ensure_response( array_values( $data ) );
	}

	/**
	 * Convert an interval of seconds into a two part human friendly string.
	 *
	 * The WordPress human_time_diff() function only calculates the time difference to one degree, meaning
	 * even if an action is 1 day and 11 hours away, it will display "1 day". This function goes one step
	 * further to display two degrees of accuracy.
	 *
	 * Inspired by the Crontrol::interval() function by Edward Dale: https://wordpress.org/plugins/wp-crontrol/
	 *
	 * @param int $interval A interval in seconds.
	 * @param int $periods_to_include Depth of time periods to include, e.g. for an interval of 70, and $periods_to_include of 2, both minutes and seconds would be included. With a value of 1, only minutes would be included.
	 * @return string A human friendly string representation of the interval.
	 */
	private static function human_interval( $interval, $periods_to_include = 2 ) {

		if ( $interval <= 0 ) {
			return __( 'Now!', 'action-scheduler-admin' );
		}

		$output = '';

		for ( $time_period_index = 0, $periods_included = 0, $seconds_remaining = $interval; $time_period_index < count( self::$time_periods ) && $seconds_remaining > 0 && $periods_included < $periods_to_include; $time_period_index++ ) {

			$periods_in_interval = floor( $seconds_remaining / self::$time_periods[ $time_period_index ]['seconds'] );

			if ( $periods_in_interval > 0 ) {
				if ( ! empty( $output ) ) {
					$output .= ' ';
				}
				$output .= sprintf( _n( self::$time_periods[ $time_period_index ]['names'][0], self::$time_periods[ $time_period_index ]['names'][1], $periods_in_interval, 'action-scheduler-admin' ), $periods_in_interval );
				$seconds_remaining -= $periods_in_interval * self::$time_periods[ $time_period_index ]['seconds'];
				$periods_included++;
			}
		}

		return $output;
	}

	/**
	 * Returns the recurrence of an action or 'Non-repeating'. The output is human readable.
	 *
	 * @param ActionScheduler_Action $action
	 *
	 * @return string
	 */
	protected function get_recurrence( $action ) {
		$recurrence = $action->get_schedule();
		if ( $recurrence->is_recurring() ) {
			if ( method_exists( $recurrence, 'interval_in_seconds' ) ) {
				return sprintf( __( 'Every %s', 'action-scheduler-admin' ), self::human_interval( $recurrence->interval_in_seconds() ) );
			}

			if ( method_exists( $recurrence, 'get_recurrence' ) ) {
				return sprintf( __( 'Cron %s', 'action-scheduler-admin' ), $recurrence->get_recurrence() );
			}
		}

		return __( 'Non-repeating', 'action-scheduler-admin' );
	}

	/**
	 * Get the scheduled date in a human friendly format.
	 *
	 * @param ActionScheduler_Schedule $schedule
	 * @return array
	 */
	protected function get_schedule_display( ActionScheduler_Schedule $schedule ) {

		$schedule_display = [];

		if ( ! $schedule->next() ) {
			return $schedule_display;
		}

		$schedule_display['timestamp'] = $schedule->next()->getTimestamp();
		$schedule_display['date']      = $schedule->next()->format( 'Y-m-d H:i:s O' );

		if ( gmdate( 'U' ) > $schedule_display['timestamp'] ) {
			$schedule_display['delta'] = sprintf( __( '(%s ago)', 'action-scheduler-admin' ), self::human_interval( gmdate( 'U' ) - $schedule_display['timestamp'] ) );
		} else {
			$schedule_display['delta'] = sprintf( __( '(%s)', 'action-scheduler-admin' ), self::human_interval( $schedule_display['timestamp'] - gmdate( 'U' ) ) );
		}

		return $schedule_display;
	}
}
