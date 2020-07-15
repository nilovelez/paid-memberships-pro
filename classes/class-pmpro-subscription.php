<?php

class PMPro_Subscription {

	function __construct( $morder = null ) {
		if ( ! empty( $morder ) ) {
			$this->get_subscription_by_order( $morder );
		} else {
			$this->get_empty_subscription();
		}
	}

	function __get( $key ) {
		if ( isset( $this->$key ) ) {
			$value = $this->$key;
		} else {
			$value = '';
		}		
		return $value;
	}

	function get_empty_subscription() {
		$this->id                          = '';
		$this->mu_id                       = '';
		$this->gateway                     = '';
		$this->gateway_environment         = '';
		$this->subscription_transaction_id = '';
		$this->status                      = 'active';
		$this->startdate                   = '';
		$this->enddate                     = '';
		$this->next_payment_date           = '';

		return $this;
	}

	function get_subscription_for_user( $user_id = null, $membership_id = null ) {
		global $current_user;
		// Get user_id if none passed.
		if ( empty( $user_id ) ) {
			$user_id = $current_user->ID;
		}
		// If we don't have a valid user, return.
		if ( empty( $user_id ) ) {
			return false;
		}
		// Get membership_id if none passed.
		if ( empty( $membership_id ) ) {
			$membership_level = pmpro_getMembershipLevelForUser( $user_id );
			$membership_id    = $membership_level->id;
		}
		// If we don't have a valid membership level, return.
		if ( empty( $membership_id ) ) {
			return false;
		}
		// Get subscription from order.
		$morder = new MemberOrder();
		$morder->getLastMemberOrder( $user_id, 'success', $membership_id );
		$this->get_subscription_by_order( $morder );
	}

	function get_subscription_by_order( $morder ) {
		// Get order object if ID passed.
		if ( is_numeric( $morder ) ) {
			$morder = new MemberOrder( $morder );
		}
		// Check that we have a valid order.
		if (
			! is_a( $morder, 'MemberOrder' ) ||
			empty( $morder->subscription_transaction_id ) ||
			empty( $morder->gateway ) ||
			empty( $morder->gateway_environment )
		) {
			return false;
		}
		// Get the subscription.
		return $this->get_subscription( $morder->subscription_transaction_id, $morder->gateway, $morder->gateway_environment );
	}

	/**
	 * Function to populate a pmpro_subscription object from a subscription_transaction_id,
	 * gateway, and gateway_environment.
	 */
	function get_subscription( $subscription_transaction_id, $gateway, $gateway_environment ) {
		global $wpdb;
		// Get the discount code object.
		$subscription_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * 
				FROM $wpdb->pmpro_subscriptions
				WHERE subscription_transaction_id = '%s'
				AND gateway = '%s'
				AND gateway_environment = '%s'",
				$subscription_transaction_id,
				$gateway,
				$gateway_environment
			),
			OBJECT
		);

		if ( ! empty( $subscription_data ) ) {
			$this->id                          = $subscription_data->id;
			$this->mu_id                       = $subscription_data->mu_id;
			$this->gateway                     = $subscription_data->gateway;
			$this->gateway_environment         = $subscription_data->gateway_environment;  
			$this->subscription_transaction_id = $subscription_data->subscription_transaction_id;
			$this->status                      = $subscription_data->status;
			$this->startdate                   = $subscription_data->startdate;
			$this->enddate                     = $subscription_data->enddate;
			$this->next_payment_date           = $subscription_data->next_payment_date;
		} else {
			return null;
		}

		return $this;
	}

	static function build_subscription_from_order( $morder ) {
		global $wpdb;
		if ( ! is_a( $morder, 'MemberOrder' ) ) {
			return false;
		}
		$subscription                              = new PMPro_Subscription();
		$subscription->gateway                     = $morder->gateway;
		$subscription->gateway_environment         = $morder->gateway_environment;
		$subscription->subscription_transaction_id = $morder->subscription_transaction_id;
		$subscription->startdate                   = current_time( 'Y-m-d H:i:s' );

		// Get next payment date.
		if ( ! empty( $morder->ProfileStartDate ) ) {
			$subscription->next_payment_date = $morder->ProfileStartDate;
		} else {
			// Get next payment date by querying gateway.
			$gateway_object = $this->get_gateway_object();
			if ( is_object( $gateway_object ) ) {
				$subscription->next_payment_date = $gateway_object->get_next_payment_date( $subscription );
			}
		}

		$subscription->save();
		return $subscription;
	}

	function get_last_order() {
		$morder = new MemberOrder();
		$morder->getLastMemberOrderBySubscriptionTransactionID( $this->subscription_transaction_id );
		return $morder;
	}

	function link_membership_user( $user_id, $membership_id ) {
		global $wpdb;
		$this->mu_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id 
				FROM $wpdb->pmpro_memberships_users
				WHERE user_id = '%d'
				AND membership_id = '%d'
				AND status = 'active'
				ORDER BY startdate DESC LIMIT 1",
				intval( $user_id ),
				intval( $membership_id )
			)
		);
		$this->save();
	}

	static function subscription_exists_for_order( $morder ) {
		// Get order object if ID passed.
		if ( is_numeric( $morder ) ) {
			$morder = new MemberOrder( $morder );
		}
		// Check that we have a valid order.
		if (
			! is_a( $morder, 'MemberOrder' ) ||
			empty( $morder->subscription_transaction_id ) ||
			empty( $morder->gateway ) ||
			empty( $morder->gateway_environment )
		) {
			return false;
		}
		// Get the subscription.
		return self::subscription_exists( $morder->subscription_transaction_id, $morder->gateway, $morder->gateway_environment );
	}

	static function subscription_exists( $subscription_transaction_id, $gateway, $gateway_environment ) {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) 
				FROM $wpdb->pmpro_subscriptions
				WHERE subscription_transaction_id = '%s'
				AND gateway = '%s'
				AND gateway_environment = '%s'",
				$subscription_transaction_id,
				$gateway,
				$gateway_environment
			)
		);
		return '0' !== $count;
	}

	/**
	 * Save or update a subscription.
	 */
	function save() {
		global $wpdb;

		if ( empty( $this->id ) ) {
			$before_action = 'pmpro_create_subscription';
			$after_action  = 'pmpro_created_subscription';
		} else {
			$before_action = 'pmpro_update_subscription';
			$after_action  = 'pmpro_updated_subscription';
		}

		do_action( $before_action, $this );

		$wpdb->replace(
			$wpdb->pmpro_subscriptions,
			array(
				'id'                         => $this->id,
				'mu_id'                      => $this->mu_id,
				'gateway'                    => $this->gateway,
				'gateway_environment'        => $this->gateway_environment,
				'subscription_transaction_id'=> $this->subscription_transaction_id,
				'status'                     => $this->status,
				'startdate'                  => $this->startdate,
				'enddate'                    => $this->enddate,
				'next_payment_date'          => $this->next_payment_date,
			),
			array(
				'%d',		//id
				'%d',		//mu_id
				'%s',		//gateway
				'%s',		//gateway_environment
				'%s',		//subscription_transaction_id
				'%s',		//status
				'%s',		//startdate
				'%s',		//enddate
				'%s',		//next_payment_date
			)
		);

		if ( $wpdb->insert_id ) {
			$this->id = $wpdb->insert_id;
		}

		do_action( $after_action, $this );
	}

	function get_gateway_object() {
		$classname = 'PMProGateway';	// Default test gateway.
		if ( ! empty( $this->gateway ) && $this->gateway != 'free' ) {
			$classname .= '_' . $this->gateway;	// Adding the gateway suffix.
		}

		if ( class_exists( $classname ) && isset ( $this->gateway ) ) {
			return new $classname( $this->gateway );
		} else {
			return null;
		}
	}

	function cancel() {
		// Cancel the gateway subscription first.
		$gateway_object = $this->get_gateway_object();
		if ( is_object( $gateway_object ) ) {
			// TODO: Update all of the gateways to take a subscription instead of an order.
			$result = $gateway_object->cancel( $this );
		} else {
			$result = false;
		}

		$this->status = 'cancelled';
		if ( $result == false ) {
			// TODO: Maybe have a new status here?
			// Can we check with the gateway to see if it was successful?
			$this->status = 'cancelled';

			// Notify the admin.
			$pmproemail = new PMProEmail();
			$pmproemail->template      = 'subscription_cancel_error';
			$pmproemail->data          = array( 'body' => '<p>' . sprintf( __( 'There was an error canceling the subscription for user with ID=%s. You will want to check your payment gateway to see if their subscription is still active.', 'paid-memberships-pro' ), strval( $this->user_id ) ) . '</p><p>Error: ' . $this->error . '</p>' );
			$pmproemail->data['body'] .= '<p>' . __( 'User Email', 'paid-memberships-pro' ) . ': ' . $order_user->user_email . '</p>';
			$pmproemail->data['body'] .= '<p>' . __( 'Username', 'paid-memberships-pro' ) . ': ' . $order_user->user_login . '</p>';
			$pmproemail->data['body'] .= '<p>' . __( 'User Display Name', 'paid-memberships-pro' ) . ': ' . $order_user->display_name . '</p>';
			$pmproemail->data['body'] .= '<p>' . __( 'Order', 'paid-memberships-pro' ) . ': ' . $this->code . '</p>';
			$pmproemail->data['body'] .= '<p>' . __( 'Gateway', 'paid-memberships-pro' ) . ': ' . $this->gateway . '</p>';
			$pmproemail->data['body'] .= '<p>' . __( 'Subscription Transaction ID', 'paid-memberships-pro' ) . ': ' . $this->subscription_transaction_id . '</p>';
			$pmproemail->data['body'] .= '<hr />';
			$pmproemail->data['body'] .= '<p>' . __( 'Edit User', 'paid-memberships-pro' ) . ': ' . esc_url( add_query_arg( 'user_id', $this->user_id, self_admin_url( 'user-edit.php' ) ) ) . '</p>';
			$pmproemail->data['body'] .= '<p>' . __( 'Edit Order', 'paid-memberships-pro' ) . ': ' . esc_url( add_query_arg( array( 'page' => 'pmpro-orders', 'order' => $this->id ), admin_url( 'admin.php' ) ) ) . '</p>';
			$pmproemail->sendEmail( get_bloginfo( 'admin_email' ) );
		} else {
			// Remove billing numbers in pmpro_memberships_users if the membership is still active.
			$sql_query = "UPDATE $wpdb->pmpro_memberships_users SET initial_payment = 0, billing_amount = 0, cycle_number = 0 WHERE id = '" . $this->mu_id . "'";
			$wpdb->query( $sql_query );
			$this->status  = 'cancelled';
			$this->enddate = current_time( 'Y-m-d H:i:s' );
			$this->save();
		}
		return $result;
	}

} // end of class