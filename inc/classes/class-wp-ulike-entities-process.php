<?php
/**
 * WP ULike Process Class
 * // @echo HEADER
 */

// no direct access allowed
if ( ! defined('ABSPATH') ) {
    die();
}

if ( ! class_exists( 'wp_ulike_entities_process' ) ) {

	class wp_ulike_entities_process {

		protected $wpdb;
		protected $currentStatus = 'like';
		protected $isUserLoggedIn;
		protected $prevStatus;
		protected $currentIP;
		protected $currentUser;
		protected $typeSettings;
		protected $itemType;
		protected $itemMethod;

		/**
		 * Constructor
		 */
		function __construct( $atts = array() ){
			global $wpdb;

			// Defining default attributes
			$default_atts = array(
				'user_id'     => NULL,
				'user_ip'     => NULL,
				'item_type'   => 'post',
				'item_method' => 'by_username'
			);
			$parsed_args = wp_parse_args( $atts, $default_atts );

			$this->wpdb = $wpdb;
			$this->setItemType( $parsed_args['item_type'] );
			$this->setCurrentIP( $parsed_args['user_ip'] );
			$this->setIsUserLoggedIn( $parsed_args['user_id'] );
			$this->setCurrentUser( $parsed_args['user_id'] );
			$this->setItemMethod( $parsed_args['item_method'] );

			// Set type settings
			$this->setTypeSettings();
		}

		/**
		 * Set current user IP
		 *
		 * @return void
		 */
		protected function setCurrentIP( $user_ip ){
			$this->currentIP = $user_ip === NULL ? wp_ulike_get_user_ip() : $user_ip;
		}

		/**
		 * Set current user IP
		 *
		 * @return void
		 */
		protected function setItemType( $item_type ){
			$this->itemType = $item_type;
		}

		/**
		 * Set current user IP
		 *
		 * @return void
		 */
		protected function setItemMethod( $item_method ){
			$this->itemMethod = $item_method;
		}

		/**
		 * Set current user IP
		 *
		 * @return void
		 */
		protected function setTypeSettings(){
			$this->typeSettings = new wp_ulike_setting_type( $this->itemType );
		}

		/**
		 * Set is user logged in status
		 *
		 * @return void
		 */
		protected function setIsUserLoggedIn( $user_id ){
			$this->isUserLoggedIn = $user_id === NULL ? is_user_logged_in() : true;
		}

		/**
		 * Set current user ID
		 *
		 * @return void
		 */
		protected function setCurrentUser( $user_id ){
			if( $user_id === NULL ){
				$this->currentUser = $this->isUserLoggedIn ? get_current_user_id() : wp_ulike_generate_user_id( $this->currentIP );
			} else {
				$this->currentUser = $user_id;
			}
		}

		/**
		 * Get current user id
		 *
		 * @return string
		 */
		public function getCurrentUser(){
			return $this->currentUser;
		}

		/**
		 * Get user previous status
		 *
		 * @return string
		 */
		public function getPrevStatus(){
			return $this->prevStatus;
		}

		/**
		 * Get user current status
		 *
		 * @return string
		 */
		public function getCurrentStatus(){
			return $this->currentStatus;
		}

		/**
		 * Get data info
		 *
		 * @return array
		 */
		public function getSettings(){
			return $this->typeSettings;
		}


		/**
		 * Update current status
		 *
		 * @param string $factor
		 * @param boolean $keep_status
		 * @return void
		 */
		public function setCurrentStatus( $factor = 'up', $keep_status = false, $force_status = false ){
			if( $force_status ){
				$this->currentStatus = $force_status;
				return;
			}

			if( $factor === 'down' ){
				$this->currentStatus = $this->prevStatus !== 'dislike' || $keep_status ? 'dislike' : 'undislike';
			} else {
				$this->currentStatus = $this->prevStatus !== 'like' || $keep_status ? 'like' : 'unlike';
			}
		}

		/**
		 * Set user previous status
		 *
		 * @param string $item_id
		 * @return void
		 */
		public function setPrevStatus( $item_id ){
			$meta_key  = sanitize_key( $this->itemType . '_status' );
			$user_info = wp_ulike_get_meta_data( $this->currentUser, 'user', $meta_key, true );

			if( empty( $user_info ) || ! isset( $user_info[$item_id] ) ){
				$query  = sprintf( '
						SELECT `status`
						FROM %s
						WHERE `%s` = \'%s\'
						AND `user_id` = \'%s\'
						ORDER BY id DESC LIMIT 1
					',
					esc_sql( $this->wpdb->prefix . $this->typeSettings->getTableName() ),
					esc_sql( $this->typeSettings->getColumnName() ),
					esc_sql( $item_id ),
					esc_sql( $this->currentUser )
				);

				// Get results
				$user_status = $this->wpdb->get_var( stripslashes( $query ) );

				// Check user info value
				$user_info = empty( $user_info ) ? array() : $user_info;

				if( $user_status !== NULL || $this->isUserLoggedIn ){
					$user_info[$item_id] =  $this->isUserLoggedIn && $user_status === NULL ? NULL : $user_status;
					wp_ulike_update_meta_data( $this->currentUser, 'user', $meta_key, $user_info );
				}
			} elseif( empty( $user_info[$item_id] ) ) {
				$this->prevStatus = false;
				return;
			}

			$this->prevStatus = isset( $user_info[ $item_id ] ) ? $user_info[ $item_id ] : NULL;
		}

		/**
		 * Check permission access
		 *
		 * @param array $args
		 * @return boolean
		 */
		public static function hasPermission( $args ){
			switch ( $args['method'] ) {
				case 'by_cookie':
					return ! isset( $_COOKIE[ $args['type'] . $args['id'] ] );

				default:
					return true;
			}
		}

		/**
		 * Check distinct status by logging method
		 *
		 * @return boolean
		 */
		public function isDistinct(){
			return wp_ulike_setting_repo::isDistinct( $this->itemMethod );
		}

		/**
		 * Inset log data
		 *
		 * @param integer $item_id
		 * @return integer|false
		 */
		public function insertData( $item_id ){
			return $this->wpdb->insert(
				$this->wpdb->prefix . $this->typeSettings->getTableName(),
				array(
					$this->typeSettings->getColumnName() => esc_sql( $item_id ),
					'date_time' => current_time( 'mysql' ),
					'ip'        => $this->maybeAnonymiseIp( $this->currentIP ),
					'user_id'   => esc_sql( $this->currentUser ),
					'status'    => esc_sql( $this->currentStatus )
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}

		/**
		 * Anonymise IP address if option enabled.
		 *
		 * @param string $ip
		 * @return string
		 */
		protected function maybeAnonymiseIp( $ip ){
			// Check anonymise enable
			if( wp_ulike_get_option( 'enable_anonymise_ip' ) ){
				if ( strpos( $ip, "." ) == true ) {
					$ip = preg_replace('~[0-9]+$~', '0', $ip );
				} else {
					$ip = preg_replace('~[0-9]*:[0-9]+$~', '0000:0000', $ip );
				}
			}

			return esc_sql( $ip );
		}

		/**
		 * Update log data
		 *
		 * @param integer $item_id
		 * @return integer|false
		 */
		public function updateData( $item_id ){
			return $this->wpdb->update(
				$this->wpdb->prefix . $this->typeSettings->getTableName(),
				array(
					'status' => esc_sql( $this->currentStatus )
				),
				array( $this->typeSettings->getColumnName() => $item_id, 'user_id' => $this->currentUser )
			);
		}

		/**
		 * Update and return counter value
		 *
		 * @param integer $item_id
		 * @return integer
		 */
		public function updateCounterMeta( $item_id ){
			// Get current value
			$value = wp_ulike_get_counter_value( $item_id, $this->itemType, $this->currentStatus, $this->isDistinct() );

			// Remove 'un' prefix from status.
			$status  = ltrim( $this->currentStatus, 'un');

			// Update meta value
			if( ! empty( $value ) || is_numeric( $value ) ){
				$value  = strpos( $this->currentStatus, 'un') === false ? $value + 1 : $value - 1;
			}
			wp_ulike_update_meta_counter_value( $item_id, max( $value, 0 ), $this->itemType, $status, $this->isDistinct() );

			// Decrease reverse meta value
			if( $this->isDistinct() && $this->prevStatus ){
				// Check user conditions
				if( ltrim( $this->prevStatus, 'un') !== $status &&
					strpos( $this->currentStatus, 'un') === false &&
					strpos( $this->prevStatus, 'un') === false ){
					// Get reverse key
					$reverse_key = strpos( $status, 'dis') === false ? 'dislike' : 'like';
					// Get reverse counter value
					$reverse_val = wp_ulike_meta_counter_value( $item_id, $this->itemType, $reverse_key, $this->isDistinct() );
					// Update if reverse value exist
					if( ! empty( $reverse_val ) || is_numeric( $reverse_val ) ){
						wp_ulike_update_meta_counter_value( $item_id, max( $reverse_val - 1, 0 ), $this->itemType, $reverse_key, $this->isDistinct() );
					}
				}
			}

			return $value;
		}

		/**
		 * Update user meta status
		 *
		 * @param integer $item_id
		 * @return void
		 */
		public function updateUserMetaStatus( $item_id ){
			// Update object cache (memcached issue)
			$meta_key  = sanitize_key( $this->itemType . '_status' );
			$user_info = wp_ulike_get_meta_data( $this->currentUser, 'user', $meta_key, true );

			if( empty( $user_info ) ){
				$user_info = array( $item_id => $this->currentStatus );
			} else {
				$user_info[$item_id] = $this->currentStatus;
			}

			// Update meta value
			wp_ulike_update_meta_data( $this->currentUser, 'user', $meta_key, $user_info );
		}

		/**
		 * Update likers meta list
		 *
		 * @param integer $item_id
		 * @return void
		 */
		public function updateLikerMetaList( $item_id ){
			// Update likers list
			$get_likers = wp_ulike_get_meta_data( $item_id, $this->itemType, 'likers_list', true );
			if( ! empty( $get_likers ) ){
				$get_user   = get_userdata( $this->currentUser );
				$is_updated = false;
				if( $get_user ){
					if( in_array( $get_user->ID, $get_likers ) ){
						if( strpos( $this->currentStatus, 'un') !== false ){
							$get_likers = array_diff( $get_likers, array( $get_user->ID ) );
							$is_updated = true;
						}
					} else {
						if( strpos( $this->currentStatus, 'un') === false ){
							array_push( $get_likers, $get_user->ID );
							$is_updated = true;
						}
					}
					// If array list has been changed, then update meta data.
					if( $is_updated ){
						wp_ulike_update_meta_data( $item_id, $this->itemType, 'likers_list', $get_likers );
					}
				}
			}
		}

		/**
		 * Update stats meta data
		 *
		 * @param integer $item_id
		 * @return void
		 */
		public function updateStatsMetaData( $item_id ){
			// Update total stats
			if( ( ! $this->prevStatus || ! $this->isDistinct() ) && strpos( $this->currentStatus, 'un') === false ){
				// update all logs period
				$this->wpdb->query( "
						UPDATE `{$this->wpdb->prefix}ulike_meta`
						SET `meta_value` = (`meta_value` + 1)
						WHERE `meta_group` = 'statistics' AND `meta_key` = 'count_logs_period_all'
				" );
				$table = $this->typeSettings->getTableName();
				$this->wpdb->query( "
						UPDATE `{$this->wpdb->prefix}ulike_meta`
						SET `meta_value` = (`meta_value` + 1)
						WHERE `meta_group` = 'statistics' AND `meta_key` = 'count_logs_for_{$table}_table_in_all_daterange'
				" );
			}
			// Delete object cache
			if( wp_ulike_is_cache_exist() ){
				wp_cache_delete( 'calculate_new_votes', WP_ULIKE_SLUG );
				wp_cache_delete( 'count_logs_period_all', WP_ULIKE_SLUG );
				wp_cache_delete( 1, 'wp_ulike_statistics_meta' );
			}
		}

		/**
		 * Update meta data
		 *
		 * @param integer $item_id
		 * @return void
		 */
		public function updateMetaData( $item_id ){
			// Update user status
			$this->updateUserMetaStatus( $item_id );
			// Update likers list
			$this->updateLikerMetaList( $item_id );
			// Update stats meta data
			$this->updateStatsMetaData( $item_id );
		}

	}

}