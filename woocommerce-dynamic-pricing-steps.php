<?php

/*
 * Plugin Name: WooCommerce Dynamic Pricing Steps
 * Plugin URI: https://github.com/lucasstark/dynamic-pricing-blocks/
 * Description: WooCommerce Dynamic Pricing Blocks let's you create custom pricing steps for products.  You will need to hard code your product id(s) and adjustment amount in the plugin.
 * Version: 1.0.0
 * Author: Lucas Stark
 * Author URI: https://elementstark.com
 * Requires at least: 3.3
 * Tested up to: 4.9.7
 * Text Domain: woocommerce-dynamic-pricing-steps
 * Domain Path: /i18n/languages/
 * Copyright: © 2009-2018 Lucas Stark.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * WC requires at least: 3.0.0
 * WC tested up to: 3.4.3
 */


/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

if ( is_woocommerce_active() ) {

	class Dynamic_Pricing_Steps {

		/**
		 * @var Dynamic_Pricing_Steps
		 */
		private static $instance;

		public static function register() {
			if ( self::$instance == null ) {
				self::$instance = new Dynamic_Pricing_Steps();
			}
		}

		private $_cart_setup = false;

		private $_products_to_exclude;

		private $_block_size;

		private $_price_adjustment;

		protected function __construct() {

			add_filter( 'woocommerce_get_cart_item_from_session', array(
				$this,
				'on_woocommerce_get_cart_item_from_session'
			), 10, 3 );


			add_action( 'woocommerce_cart_loaded_from_session', array(
				$this,
				'on_cart_loaded_from_session'
			), 0, 1 );


			add_filter( 'woocommerce_product_get_price', array( $this, 'on_get_price' ), 10, 2 );
			add_filter( 'woocommerce_cart_item_price', array( $this, 'on_get_cart_item_price' ), 10, 2 );

			$this->_products_to_exclude   = array();
			$this->_products_to_exclude[] = 5192;
			$this->_products_to_exclude[] = 5193;



			$this->_block_size           = 3;
			$this->_price_adjustment     = 10.00;

		}

		/**
		 * Records the cart item key on the product so we can reference it in the future.
		 *
		 * @param $cart_item
		 * @param $cart_item_values
		 * @param $cart_item_key
		 *
		 * @return mixed
		 */
		public function on_woocommerce_get_cart_item_from_session( $cart_item, $cart_item_values, $cart_item_key ) {
			$cart_item['data']->cart_item_key = $cart_item_key;

			return $cart_item;
		}

		/**
		 * Setup the adjustments on the cart.
		 *
		 * @param WC_Cart $cart
		 */
		public function on_cart_loaded_from_session( $cart ) {
			$this->setup_cart( $cart );
		}


		/**
		 * @param WC_Cart $cart
		 */
		public function setup_cart( $cart ) {
			if ( $this->_cart_setup ) {
				return;
			}

			if ( $cart && $cart->get_cart_contents_count() ) {

				$product_count = 0;
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					unset( WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_product_price'] );

					$product    = $cart_item['data'];
					$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
					if ( !in_array( $product_id, $this->_products_to_exclude ) ) {
						$product_count += $cart_item['quantity'];
					}
				}

				if (empty($product_count)) {
					$this->_cart_setup = true;
					return;
				}


				$applied   = 0;
				$remaining = floor(($product_count / $this->_block_size)) * $this->_block_size;
				if ( $product_count && $product_count >= $this->_block_size ) {
					foreach ( $cart->get_cart() as $cart_item_key => &$cart_item ) {
						$product = $cart_item['data'];
						$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
						if ( in_array( $product_id, $this->_products_to_exclude ) ) {
							continue;
						}

						$quantity = $cart_item['quantity'];
						$price    = $product->get_price( 'edit' );
						$adjusted = $price - $this->_price_adjustment;

						if ( $quantity == $remaining ) {
							WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_product_price'] = $adjusted;
							$remaining                                                               = 0;

						} elseif ( $quantity < $remaining ) {
							WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_product_price'] = $adjusted;
							$remaining                                                               = $remaining - $quantity;
						} elseif ( $quantity > $remaining ) {

							$full_price_quantity = $quantity - $remaining;

							$full_price_total                                                        = $price * $full_price_quantity;
							$adjusted_total                                                          = $adjusted * $remaining;
							$grand_total                                                             = $full_price_total + $adjusted_total;
							$unit_price                                                              = wc_cart_round_discount( $grand_total / $quantity, 4 );
							WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_product_price'] = $unit_price;

						}

						if ( empty( $remaining ) ) {
							break;
						}
					}
				}
			}

			$this->_cart_setup = true;


		}

		/**
		 * Finally everything is all set we can get the product price for cart items.
		 *
		 * @param $price
		 * @param $product
		 */
		public function on_get_price( $price, $product ) {

			if ( isset( $product->cart_item_key ) ) {
				//We know this is for a product in the cart.

				$cart_item = WC()->cart->get_cart_item( $product->cart_item_key );

				if ( $cart_item && isset( $cart_item['es_adjusted_product_price'] ) ) {
					//found our cart item, and adjusted quantities.

					$price = $cart_item['es_adjusted_product_price'];

				}


			}

			return $price;

		}


		//Format the price as a sale price.
		public function on_get_cart_item_price( $html, $cart_item ) {
			$result_html = false;

			if ( isset( $cart_item['data']->cart_item_key ) ) {


				if ( isset( $cart_item['es_adjusted_quantities'] ) && ! empty( $cart_item['es_adjusted_quantities'] ) ) {
					$result_html = '';

					$block_html = '';
					$amounts    = array();
					foreach ( $cart_item['es_adjusted_quantities'] as $adjusted_price ) {
						if ( ! isset( $amounts[ $adjusted_price ] ) ) {
							$amounts[ $adjusted_price ] = 0;
						}
						$amounts[ $adjusted_price ] = $amounts[ $adjusted_price ] + 1;
					}

					foreach ( $amounts as $amount => $quantity ) {
						$result_html .= wc_price( $amount ) . ' x ' . $quantity;
						$result_html .= '<br />';
					}

					$remaining = $cart_item['quantity'] - count( $cart_item['es_adjusted_quantities'] );
					if ( $remaining ) {
						$result_html .= wc_price( $cart_item['data']->get_price( 'edit' ) ) . ' x ' . $remaining;
					}

				}


			}


			return $result_html ? $result_html : $html;

		}
	}

	Dynamic_Pricing_Steps::register();
}
