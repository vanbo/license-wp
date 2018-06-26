<?php

namespace Never5\LicenseWP\WooCommerce;

class Order {

	/**
	 * Setup hooks and filters
	 */
	public function setup() {

		// Display keys in order edit screen
		add_action( 'woocommerce_order_actions_end', array( $this, 'display_keys' ) );

		// Hook into WooCommerce order completed status
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_completed' ) );

		// Delete license related data on order delete
		add_action( 'delete_post', array( $this, 'order_delete' ) );
	}

	/**
	 * Dislay lincense keys
	 *
	 * @param int $order_id
	 */
	public function display_keys( $order_id ) {
		if ( get_post_meta( $order_id, 'has_api_product_license_keys', true ) ) {
			?>
			<li class="wide">
				<a href="<?php echo admin_url( 'admin.php?page=license_wp_licenses&order_id=' . $order_id ); ?>"><?php _e( 'View license keys &rarr;', 'license-wp' ); ?></a>
			</li>
			<?php
		}
	}

	/**
	 * Generate codes
	 *
	 * @param int $order_id
	 */
	public function order_completed( $order_id ) {

		// Only continue of this order doesn't have license keys yet
		if ( get_post_meta( $order_id, 'has_api_product_license_keys', true ) ) {
			return;
		}

		// Create \WC_Order
		$order   = new \WC_Order( $order_id );
		$has_key = false;

		// Check for global subscription renewal
		$is_subscription_renewal = false;
		foreach ( $order->get_meta_data() as $meta ) {
			if ( $meta->key == '_subscription_renewal' ) {
				$is_subscription_renewal = true;

				$subscription = new \WC_Subscription( $meta->value );

				if ( $subscription ) {

					// Get parent order id
					$parent_order_id = $subscription->get_parent_id();

					// Fetch license of parent order
					$licenses = license_wp()->service( 'license_manager' )->get_licenses_by_order( $parent_order_id );

					if ( ! empty( $licenses ) ) {
						$license = array_shift( $licenses );

						// Set renewing key
						$_renewing_key = $license->get_key();
					}
				}
			}
		}

		// Loop items
		if ( sizeof( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item ) {

				/**
				 * @var \WC_Order_Item_Product $item
				 * @var \WC_Product            $product
				 */
				$product = $item->get_product();

				// Fetch if it's an API license product
				if ( $product->is_type( 'variation' ) ) {
					$is_api_product = ( 'yes' === get_post_meta( $product->get_parent_id(), '_is_api_product_license', true ) );
				} else {
					$is_api_product = ( 'yes' === get_post_meta( $product->get_id(), '_is_api_product_license', true ) );
				}

				// Check if this is an API license product
				if ( $is_api_product ) {

					// Get activation limit
					if ( ! $product->get_id() || ( ! $activation_limit = get_post_meta( $product->get_id(), '_license_activation_limit', true ) ) ) {
						$activation_limit = get_post_meta( $product->get_id(), '_license_activation_limit', true );

						if ( empty( $activation_limit ) && $product->is_type( 'variation' ) ) {
							$activation_limit = get_post_meta( $product->get_parent_id(), '_license_activation_limit', true );
						}
					}

					// Get expiry date
					$expiry_modify_string = "";

					$license_expiry_amount = get_post_meta( $product->get_id(), '_license_expiry_amount', true );
					$license_expiry_type   = get_post_meta( $product->get_id(), '_license_expiry_type', true );

					if ( empty( $license_expiry_amount ) && $product->is_type( 'variation' ) ) {
						$license_expiry_amount = get_post_meta( $product->get_parent_id(), '_license_expiry_amount', true );
					}

					if ( empty( $license_expiry_type ) && $product->is_type( 'variation' ) ) {
						$license_expiry_type = get_post_meta( $product->get_parent_id(), '_license_expiry_type', true );
					}

					if ( ! empty( $license_expiry_amount ) && 0 != $license_expiry_amount ) {
						$expiry_modify_string = "+" . $license_expiry_amount . " ";
						switch ( $license_expiry_type ) {
							case 'years':
								$expiry_modify_string .= "years";
								break;
							case 'months':
								$expiry_modify_string .= "months";
								break;
							case 'days':
							default:
								$expiry_modify_string .= "days";
								break;
						}
					}

					// Search for upgrade key
					$_upgrading_key = false;
					foreach ( $item['item_meta'] as $meta_key => $meta_value ) {
						if ( $meta_key == '_upgrading_key' ) {
							$_upgrading_key = $meta_value[0];
						}
					}

					// Make $_upgrading_key filterable
					$_upgrading_key = apply_filters( 'lwp_order_upgrading_key', $_upgrading_key, $item, $order );

					// Search for renewal key
					$_renewing_key = false;
					if ( ! $is_subscription_renewal ) {
						// search for renewal key
						$_renewing_key = ! empty( $item['item_meta']['_renewing_key'] ) ? $item['item_meta']['_renewing_key'] : false;
					}

					// Check on renewal
					if ( $_renewing_key ) {

						// Get license
						/** @var \Never5\LicenseWP\License\License $license */
						$license = license_wp()->service( 'license_factory' )->make( $_renewing_key );

						// Set new expiration date
						if ( ! empty( $expiry_modify_string ) ) {
							$renew_datetime = ( ! $license->is_expired() ) ? $license->get_date_expires() : new \DateTime();
							$license->set_date_expires( $renew_datetime->setTime( 0, 0, 0 )->modify( $expiry_modify_string ) );
						}

						// Set new order id for license, store old order id with new order
						update_post_meta( $order_id, 'original_order_id', $license->get_order_id() );
						$license->set_order_id( $order_id );

						// Store license
						license_wp()->service( 'license_repository' )->persist( $license );
					} else if ( $_upgrading_key ) {

						// Get license
						/** @var \Never5\LicenseWP\License\License $license */
						$license = license_wp()->service( 'license_factory' )->make( $_upgrading_key );

						// Set new expiration date
						if ( apply_filters( 'lwp_upgrade_update_date_expires', true, $license, $order, $item ) ) {
							if ( ! empty( $expiry_modify_string ) ) {
								$current_datetime = new \DateTime();
								$current_datetime->setTime( 0, 0, 0 )->modify( $expiry_modify_string );
								$license->set_date_expires( apply_filters( 'lwp_upgrade_date_expires', $current_datetime, $license, $order, $item ) );
							}
						}

						// Set new activation limit
						if ( ! empty( $activation_limit ) ) {
							$license->set_activation_limit( $activation_limit );
						}

						// Set new product id
						$license->set_product_id( $product->get_id() );

						// Set new order id for license, store old order id with new order
						if ( apply_filters( 'lwp_upgrade_update_order_id', true, $license, $order, $item ) ) {
							update_post_meta( $order_id, 'original_order_id', $license->get_order_id() );
							$license->set_order_id( $order_id );
						}

						// Store license
						license_wp()->service( 'license_repository' )->persist( $license );
					} else { // No renewal, no upgrade, new key

						// Generate new keys
						for ( $i = 0; $i < absint( $item['qty'] ); $i ++ ) {

							// Create license
							/** @var \Never5\LicenseWP\License\License $license */
							$license = license_wp()->service( 'license_factory' )->make();

							// Set license data, key is generated when persisting license
							$license->set_order_id( $order_id );
							$license->set_activation_email( $order->get_billing_email() );
							$license->set_user_id( $order->get_customer_id() );
							$license->set_product_id( $product->get_id() );
							$license->set_activation_limit( $activation_limit );

							// Set date created
							$date_created = new \DateTime();
							$license->set_date_created( $date_created->setTime( 0, 0, 0 ) );

							// set correct expiry days
							if ( ! empty( $expiry_modify_string ) ) {
								$exp_date = new \DateTime();
								$license->set_date_expires( $exp_date->setTime( 0, 0, 0 )->modify( $expiry_modify_string ) );
							}

							// store license
							license_wp()->service( 'license_repository' )->persist( $license );
						}
					}

					$has_key = true;
				}
			}
		}

		// set post meta if we created at least 1 key
		if ( $has_key ) {
			update_post_meta( $order_id, 'has_api_product_license_keys', 1 );
		}
	}

	/**
	 * On delete post
	 *
	 * @param int $order_id
	 */
	public function order_delete( $order_id ) {
		// check if allowed
		if ( ! current_user_can( 'delete_posts' ) ) {
			return;
		}

		// check id
		if ( $order_id > 0 ) {

			// check post type
			$post_type = get_post_type( $order_id );

			// only continue on WC shop order
			if ( 'shop_order' === $post_type ) {
				license_wp()->service( 'license_manager' )->remove_license_data_by_order( $order_id );
			}
		}
	}
}
