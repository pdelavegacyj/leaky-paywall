<?php
/**
 * Stripe Payment Gateway Class
 *
 * @package     Leaky Paywall
 * @subpackage  Classes/Roles
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       4.0.0
*/

class Leaky_Paywall_Payment_Gateway_Stripe extends Leaky_Paywall_Payment_Gateway {

	private $secret_key;
	protected $publishable_key;

	/**
	 * Get things going
	 *
	 * @since  4.0.0
	 */
	public function init() {

		$settings = get_leaky_paywall_settings();

		$this->supports[]	= 'one-time';
		$this->supports[]	= 'recurring';
		// $this->supports[]	= 'fees';

		$this->test_mode	= 'off' === $settings['test_mode'] ? false : true;

		if ( $this->test_mode ) {

			$this->secret_key = isset( $settings['test_secret_key'] ) ? trim( $settings['test_secret_key'] ) : '';
			$this->publishable_key = isset( $settings['test_publishable_key'] ) ? trim( $settings['test_publishable_key'] ) : '';

		} else {

			$this->secret_key = isset( $settings['live_secret_key'] ) ? trim( $settings['live_secret_key'] ) : '';
			$this->publishable_key = isset( $settings['live_publishable_key'] ) ? trim( $settings['live_publishable_key'] ) : '';

		}

		if ( ! class_exists( 'Stripe' ) && ! class_exists( 'Stripe\Stripe') ) {
			require_once LEAKY_PAYWALL_PATH . 'include/stripe/init.php';
		}

	}

	/**
	 * Process registration
	 *
	 * @since 4.0.0
	 */
	public function process_signup() {

		// don't need a Stripe token anymore
		// if( empty( $_POST['stripeToken'] ) ) {
  //           leaky_paywall_errors()->add( 'missing_stripe_token', __( 'Error Processing Payment. If you are using an Ad Blocker, please disable it, refresh the page, and try again.', 'leaky-paywall' ), 'register' );
  //           return;
		// }

		\Stripe\Stripe::setApiKey( $this->secret_key );

		$cu = false;
		$paid   = false;
		$existing_customer = false;
		$subscription = '';
		
		$settings = get_leaky_paywall_settings();
		$mode = leaky_paywall_get_current_mode();
		$site = leaky_paywall_get_current_site();
		$level = get_leaky_paywall_subscription_level( $this->level_id );

		try {

			if ( is_user_logged_in() && !is_admin() ) {
				//Update the existing user
				$user_id = get_current_user_id();
				$subscriber_id = get_user_meta( $user_id, '_issuem_leaky_paywall_' . $mode . '_subscriber_id' . $site, true );

				if ( strpos( $subscriber_id, 'cus_' ) === false ) {
					$subscriber_id = false;
				}
				
			}

			if ( !empty( $subscriber_id ) ) {
				$cu = \Stripe\Customer::retrieve( $subscriber_id );
			}

			if ( empty( $cu ) ) {
				if ( $user = get_user_by( 'email', $this->email ) ) {
					try {

						if ( get_user_meta( $user_id, '_issuem_leaky_paywall_' . $mode . '_payment_gateway' . $site, true ) == 'stripe' ) {
							$subscriber_id = get_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_subscriber_id' . $site, true );
						}
						
						if ( !empty( $subscriber_id ) ) {
							$cu = \Stripe\Customer::retrieve( $subscriber_id );
						} else {
							throw new Exception( __( 'Unable to find valid Stripe customer ID.', 'leaky-paywall' ) );
						}
					}
					catch( Exception $e ) {
						$cu = false;
					}
				}
			}

			if ( !empty( $cu ) ) {
				if ( property_exists( $cu, 'deleted' ) && true === $cu->deleted ) {
					$cu = array();
				} else {
					$existing_customer = true;
				}
			}

			$customer_array = array(
				'name'		  => $this->first_name . ' ' . $this->last_name,
				'email'       => $this->email,
				// 'source'      => $_POST['stripeToken'],
				'description' => $this->level_name
			);

			$customer_array = apply_filters( 'leaky_paywall_process_stripe_payment_customer_array', $customer_array );

			// create new stripe plan if this is a recurring level but does not have a plan created yet
			if ( 'on' === $this->recurring && empty( $this->plan_id ) ) {

				$plan_args = array(
					'stripe_price'	=> number_format( $this->level_price, 2, '', '' ),
					'currency'	=> leaky_paywall_get_currency(),
					'secret_key'	=> $this->secret_key
				);

				$stripe_plan = leaky_paywall_create_stripe_plan( $level, $this->level_id, $plan_args );
				$this->plan_id = $stripe_plan->id;

			}

			// recurring subscription
			if ( !empty( $this->recurring ) && 'on' === $this->recurring && !empty( $this->plan_id ) ) {

				if ( !empty( $cu ) ) {
					$subscriptions = $cu->subscriptions->all( array('limit' => '1') );

					// use credit card submitted in form
					$source = $cu->sources->create( array( 'source' => $_POST['stripeToken'] ) );
					$cu->default_source = $source->id;
					$cu->save();

					// updating a current subscription
					if ( !empty( $subscriptions->data ) ) {
						foreach( $subscriptions->data as $subscription ) {
							
							$sub = $cu->subscriptions->retrieve( $subscription->id );
							$sub->plan = $this->plan_id;
							do_action( 'leaky_paywall_before_update_stripe_subscription', $cu, $sub );
							$sub->save();

							do_action( 'leaky_paywall_after_update_stripe_subscription', $cu, $sub );
							
						}
					} else {
						$subscription = $cu->subscriptions->create( array( 'plan' => $this->plan_id ) );
					}
					
				} else {

					$cu = \Stripe\Customer::create( $customer_array );

					do_action( 'leaky_paywall_after_create_recurring_customer', $cu );

					if ( $cu->id ) {
						$subscription_array = array(
							'customer'	=> $cu->id,
							'items' => array(
								array(
									'plan' => $this->plan_id
								),
							)
						);

						$subscription = \Stripe\Subscription::create( apply_filters( 'leaky_paywall_stripe_subscription_args', $subscription_array ) );

						if ( $subscription->status == 'incomplete' ) {
							leaky_paywall_errors()->add( 'stripe_error', __( 'Error Processing Payment. ', 'leaky-paywall' ) . 'Payment incomplete.', 'register' );
							return;
						}

					}
					
				}

			} else {

				// one time payment for new customer

				// This is all handled before this point now. What about user data that is invalid? Probably need a multi-step form
				
				/*

				$source_id = '';

				// Create a Customer
				if ( empty( $cu ) ) {
					$cu = \Stripe\Customer::create( $customer_array );
					$source_id = $cu->default_source;
				} else {
					$source = $cu->sources->create( array( 'source' => $_POST['stripeToken'] ) );
					$source_id = $source->id;
				}
			
				$charge_array = array(
					'customer'    => $cu->id,
					'amount'      => number_format( $this->amount, 2, '', '' ),
					'currency'    => apply_filters( 'leaky_paywall_stripe_currency', strtolower( $this->currency ) ),
					'description' => $this->level_name,
					'source' 	  => $source_id
				);

				$charge = \Stripe\Charge::create( apply_filters( 'leaky_paywall_process_stripe_payment_charge_array', $charge_array ) );
				
				*/
				
				if ( empty( $cu ) ) {
					$cu = \Stripe\Customer::create( $customer_array );
				}
			
			}

		} catch ( Exception $e ) {

			leaky_paywall_errors()->add( 'stripe_error', __( 'Error Processing Payment. ', 'leaky-paywall' ) . $e->getMessage(), 'register' );
            return;

		}

		$customer_id = $cu->id;

		if ( !$customer_id ) {
			wp_die( __( 'An error occurred, please contact the site administrator: ', 'leaky-paywall' ) . get_bloginfo( 'admin_email' ), __( 'Error', 'leaky-paywall' ), array( 'response' => '401' ) );
		}

		// add customer to paymentIntent
		$payment_intent_id = $_POST['payment-intent-id'];

		$payment_intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
		$payment_intent->customer = $customer_id;
		$payment_intent->save();

		// attach payment method to the customer
		// https://stripe.com/docs/payments/payment-intents/migration
		// $payment_method = \Stripe\PaymentMethod::retrieve('{{PAYMENT_METHOD_ID}}');
		// $payment_method->attach(['customer' => $customer_id]);

		$gateway_data = array(
			'level_id'			=> $this->level_id,
			'subscriber_id' 	=> $customer_id,
			'subscriber_email' 	=> $this->email,
			'existing_customer' => $existing_customer,
			'price' 			=> $this->level_price,
			'description' 		=> $this->level_name,
			'payment_gateway' 	=> 'stripe',
			'payment_status' 	=> 'deactivated', // will activate with payment_intent.succeeded webhook 
			'interval' 			=> $this->length_unit,
			'interval_count' 	=> $this->length,
			'site' 				=> !empty( $level['site'] ) ? $level['site'] : '',
			'plan' 				=> $this->plan_id,
			'recurring'			=> $this->recurring,
			'currency'			=> $this->currency,
		);

		do_action( 'leaky_paywall_stripe_signup', $gateway_data );

		return apply_filters( 'leaky_paywall_stripe_gateway_data', $gateway_data, $this, $cu, $subscription );

	}

	/**
	 * Process incoming webhooks
	 *
	 * @since 4.0.0
	 */
	public function process_webhooks() {

		if( ! isset( $_GET['listener'] ) || strtolower( $_GET['listener'] ) != 'stripe' ) {
			return;
		}

		$body = @file_get_contents('php://input');
		$stripe_event = json_decode( $body );
		$settings = get_leaky_paywall_settings();

		if ( $stripe_event->livemode == false ) {
			$mode = 'test';
		} else {
			$mode = 'live';
		}

		if ( isset( $stripe_event->type ) ) {
		    
		    $stripe_object = $stripe_event->data->object;

		    leaky_paywall_log( $stripe_object, 'stripe webhook - ' . $stripe_event->type );
		
		    if ( !empty( $stripe_object->customer ) ) {
		        $user = get_leaky_paywall_subscriber_by_subscriber_id( $stripe_object->customer, $mode );
		    }
		
		    if ( !empty( $user ) ) {
		        
		        if ( is_multisite_premium() ) {
		            if ( $site_id = get_leaky_paywall_subscribers_site_id_by_subscriber_id( $stripe_object->customer ) ) {
		                $site = '_' . $site_id;
		            }
		        } else {
		        	$site = '';
		        }
		
		        //https://stripe.com/docs/api#event_types
		        switch( $stripe_event->type ) {
		
		            case 'charge.succeeded' :
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'active' );
		                $transaction_id = leaky_paywall_get_transaction_id_from_email( $user->user_email );
		                leaky_paywall_set_payment_transaction_id( $transaction_id, $stripe_object->id );
		                break;
		            case 'charge.failed' :
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'deactivated' );
		                do_action( 'leaky_paywall_failed_payment', $user );
		                break;
		            case 'charge.refunded' :
		                if ( $stripe_object->refunded )
		                    update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'deactivated' );
		                else
		                    update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'deactivated' );
		                break;
		            case 'charge.dispute.created' :
		            case 'charge.dispute.updated' :
		            case 'charge.dispute.closed' :
		            case 'customer.created' :
		            case 'customer.updated' :
		            case 'customer.source.created' :
		            case 'invoice.created' :
		            case 'invoice.updated' :
		                break;
		            case 'customer.deleted' :
		                    update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'canceled' );
		                break;
		                
		            case 'invoice.payment_succeeded' :
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'active' );
		                break;
		                
		            case 'invoice.payment_failed' :
		                    update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'deactivated' );
		                    do_action( 'leaky_paywall_failed_payment', $user );
		                break;
		            
		            case 'customer.subscription.updated' :
		                $expires = date_i18n( 'Y-m-d 23:59:59', $stripe_object->current_period_end );
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_expires' . $site, $expires );
		                break;
		                
		            case 'customer.subscription.created' :
		            	$expires = date_i18n( 'Y-m-d 23:59:59', $stripe_object->current_period_end );
		            	update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_expires' . $site, $expires );
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'active' );
		                break;
		                
		            case 'customer.subscription.deleted' :
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'canceled' );
		                break;

		            case 'payment_intent.canceled' :
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'canceled' );
		                break;

		            case 'payment_intent.succeeded' :
		                update_user_meta( $user->ID, '_issuem_leaky_paywall_' . $mode . '_payment_status' . $site, 'active' );
		                break;
		               
		            default:
		            	break;
		
		        };

		        // create an action for each event fired by stripe
		        $action = str_replace( '.', '_', $stripe_event->type );
		        do_action( 'leaky_paywall_stripe_' . $action, $user, $stripe_object );
		
		    }
		        
		}

	}

	/**
	 * Add credit card fields
	 *
	 * @since 4.0.0
	 */
	public function fields( $level_id ) {

		$settings = get_leaky_paywall_settings();

		$level_id = is_numeric( $level_id ) ? $level_id : esc_html( $_GET['level_id'] );

		// $use_stripe_elements = true;

		if ( 'yes' == $settings['enable_stripe_elements'] ) {
			$content = $this->stripe_elements( $level_id );
			return $content;
		}
		
		
		// if ( $use_stripe_elements ) {
		// 	$content = $this->stripe_elements( $level_id );
		// 	return $content;
		// }

		$stripe_plan = '';
		$level = get_leaky_paywall_subscription_level( $level_id );

		if ( $level['price'] == 0 ) {
			return;
		}

		$plan_args = array(
			'stripe_price'	=> number_format( $level['price'], 2, '', '' ),
			'currency'		=> leaky_paywall_get_currency(),
			'secret_key'	=> $this->secret_key
		);

		if ( isset( $level['recurring'] ) && 'on' == $level['recurring'] ) {
			$stripe_plan = leaky_paywall_get_stripe_plan( $level, $level_id, $plan_args );
		}

		ob_start();
		?>

			<input type="hidden" name="plan_id" value="<?php echo $stripe_plan ? $stripe_plan->id : ''; ?>"/>

			<script>

			var leaky_paywall_script_options;
			var leaky_paywall_processing;
			var leaky_paywall_stripe_processing = false;

			  // This identifies your website in the createToken call below
			  Stripe.setPublishableKey('<?php echo $this->publishable_key; ?>');

			  function stripeResponseHandler(status, response) {

			  	if (response.error) {
			  		// re-enable the submit button
			  		jQuery('#leaky-paywall-payment-form #leaky-paywall-submit').prop("disabled", false ).text('<?php _e( 'Submit', 'leaky-paywall' ) ?>');

			  		jQuery('#leaky-paywall-submit').before('<div class="leaky-paywall-message error"><p class="leaky-paywall-error"><span>' + response.error.message + '</span></p></div>' );

			  		leaky_paywall_stripe_processing = false;
			  		leaky_paywall_processing = false;

			  	} else {

			  		var form$ = jQuery('#leaky-paywall-payment-form');
			  		var token = response['id'];

			  		form$.append('<input type="hidden" name="stripeToken" value="' + token + '" />');

			  		form$.get(0).submit();

			  	}
			  }

			  jQuery(document).ready(function($) {

			  	$('#leaky-paywall-payment-form').on('submit', function(e) {

			  		var method = $('#leaky-paywall-payment-form').find( 'input[name="payment_method"]:checked' ).val();

			  		if ( method != 'stripe' ) {
			  			return;
			  		}


 
			  		if ( ! leaky_paywall_stripe_processing ) {

			  			leaky_paywall_stripe_processing = true;

			  			// get the price
			  			$('input[name="stripe_price"]').val();

			  			// disabl the submit button to prevent repeated clicks
			  			$('#leaky-paywall-payment-form #leaky-paywall-submit').prop('disabled', true ).text('<?php _e( 'Processing... Please Wait', 'leaky-paywall' ) ?>');

			  			// create Stripe token
			  			Stripe.createToken({
			  				number: $('.card-num').val(),
			  				name: $('.card-name').val(),
			  				cvc: $('.cvc').val(),
			  				exp_month: $('.exp-month').val(),
			  				exp_year: $('.exp-year').val(),
			  				address_zip: $('.card-zip').val(),
			  			}, stripeResponseHandler);

			  			return false;
			  		}
			  	});
			  });


			</script>

			<div class="leaky-paywall-payment-method-container">

				<input id="payment_method_stripe" class="input-radio" name="payment_method" value="stripe" checked="checked" data-order_button_text="" type="radio">

				<label for="payment_method_stripe"> <?php _e( 'Credit Card', 'leaky-paywall' ); ?> <img width="150" src="<?php echo LEAKY_PAYWALL_URL; ?>images/credit_card_logos_5.gif"></label>

			</div>
			
		<?php 
		leaky_paywall_card_form();
		return ob_get_clean();
	}

	public function stripe_elements( $level_id ) 
	{

		$stripe_plan = '';
		$level = get_leaky_paywall_subscription_level( $level_id );

		if ( $level['price'] == 0 ) {
			return;
		}

		$settings = get_leaky_paywall_settings();

		$stripe_price = number_format( $level['price'], 2, '', '' );

		$plan_args = array(
			'stripe_price'	=> $stripe_price,
			'currency'		=> leaky_paywall_get_currency(),
			'secret_key'	=> $this->secret_key
		);

		if ( isset( $level['recurring'] ) && 'on' == $level['recurring'] ) {
			$stripe_plan = leaky_paywall_get_stripe_plan( $level, $level_id, $plan_args );
		}

		\Stripe\Stripe::setApiKey( $this->secret_key );

		$intent = \Stripe\PaymentIntent::create([
	      'amount' => $stripe_price,
	      'currency' => leaky_paywall_get_currency(),
	      'setup_future_usage' => 'off_session',
	      // Verify your integration in this guide by including this parameter
	     // 'metadata' => ['integration_check' => 'accept_a_payment'],
	    ]);

		ob_start();
		?>

		

		<div class="leaky-paywall-payment-method-container">

			<input id="payment_method_stripe" class="input-radio" name="payment_method" value="stripe" checked="checked" data-order_button_text="" type="radio">

			<label for="payment_method_stripe"> <?php _e( 'Credit Card', 'leaky-paywall' ); ?> <img width="150" src="<?php echo LEAKY_PAYWALL_URL; ?>images/credit_card_logos_5.gif"></label>

		</div>

		<div class="leaky-paywall-card-details">

			<input type="hidden" name="plan_id" value="<?php echo $stripe_plan ? $stripe_plan->id : ''; ?>"/>
			<input type="hidden" id="payment-intent-client" value="<?php echo $intent->client_secret; ?>">
			<input type="hidden" id="payment-intent-id" name="payment-intent-id" value="<?php echo $intent->id; ?>">

			<?php if ( 'yes' == $settings['enable_apple_pay'] ) { ?>
				<div id="payment-request-button">
				  <!-- A Stripe Element will be inserted here. -->
				</div>
			<?php } ?>
			
			
			  <div class="form-row">
			    <label for="card-element">
			      <?php _e( 'Credit or debit card', 'leaky-paywall' ); ?>
			    </label>
			    <div id="card-element">
			      <!-- A Stripe Element will be inserted here. -->
			    </div>

			    <!-- Used to display form errors. -->
			    <div id="card-errors" role="alert"></div>
			    <div id="lp-card-errors"></div>
			  </div>

		</div>

		  <script>

		  	( function( $ )  {

				$(document).ready( function() {
					
				  	var stripe = Stripe('<?php echo $this->publishable_key; ?>');

				  	<?php if ( 'yes' == $settings['enable_apple_pay'] ) { ?>
					  	var paymentRequest = stripe.paymentRequest({
					  	  country: 'US',
					  	  currency: '<?php echo strtolower( leaky_paywall_get_currency() ); ?>',
					  	  total: {
					  	    label: '<?php echo $level['label']; ?>',
					  	    amount: <?php echo $level['price'] * 100; ?>,
					  	  },
					  	  requestPayerName: true,
					  	  requestPayerEmail: true,
					  	});
					<?php } ?>

				  	var elements = stripe.elements();
				  	var clientSecret = $('#payment-intent-client').val();
				  	

				  	<?php if ( 'yes' == $settings['enable_apple_pay'] ) { ?>
					  	var prButton = elements.create('paymentRequestButton', {
					  	  paymentRequest: paymentRequest,
					  	});
				  <?php } ?>

				  	var style = {
				  	  base: {
				  	    color: '#32325d',
				  	    lineHeight: '18px',
				  	    fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
				  	    fontSmoothing: 'antialiased',
				  	    fontSize: '16px',
				  	    '::placeholder': {
				  	      color: '#aab7c4'
				  	    }
				  	  },
				  	  invalid: {
				  	    color: '#fa755a',
				  	    iconColor: '#fa755a'
				  	  }
				  	};

				  	var card = elements.create('card', {style: style});

				  	card.mount('#card-element');

				  	<?php if ( 'yes' == $settings['enable_apple_pay'] ) { ?>
					  	// Check the availability of the Payment Request API first.
					  	paymentRequest.canMakePayment().then(function(result) {
					  	  if (result) {
					  	    prButton.mount('#payment-request-button');
					  	  } else {
					  	    document.getElementById('payment-request-button').style.display = 'none';
					  	  }
					  	});

					  	paymentRequest.on('token', function(ev) {
					  	  // Send the token to your server to charge it!
					  	  fetch('/', {
					  	    method: 'POST',
					  	    body: JSON.stringify({token: ev.token.id}),
					  	    headers: {'content-type': 'application/json'},
					  	  })
					  	  .then(function(response) {
					  	    if (response.ok) {
					  	      // Report to the browser that the payment was successful, prompting
					  	      // it to close the browser payment interface.
					  	      console.log('success');
					  	
					  	      ev.complete('success');
					  	      stripeTokenHandler(ev.token);
					  	    } else {
					  	      // Report to the browser that the payment failed, prompting it to
					  	      // re-show the payment interface, or show an error message and close
					  	      // the payment interface.
					  	      console.log('fail');
					  	      ev.complete('fail');
					  	    }
					  	  });
					  	});
					 <?php } ?>


				  	// Handle real-time validation errors from the card Element.
				  	card.on('change', function(event) {
					  var displayError = document.getElementById('card-errors');
					  if (event.error) {
					    displayError.textContent = event.error.message;
					  } else {
					    displayError.textContent = '';
					  }
					});

				  	// Handle form submission.
				  	var form = document.getElementById('leaky-paywall-payment-form');

				  	form.addEventListener('submit', function(event) {

				  		var method = $('#leaky-paywall-payment-form').find( 'input[name="payment_method"]:checked' ).val();

				  		if ( method != 'stripe' ) {
				  			return;
				  		}

				  	  event.preventDefault();

				  	  var subButton = document.getElementById('leaky-paywall-submit');
				  	  var firstName = $('input[name="first_name"]').val();
				  		var lastName = $('input[name="last_name"]').val();

				  	  subButton.disabled = true;
				  	  subButton.innerHTML = '<?php echo __( 'Processing... Please Wait', 'leaky-paywall' ) ?>';

				  	 // old stripe token code here
				  	   
				  	   stripe.confirmCardPayment(clientSecret, {
				  	     payment_method: {
				  	       card: card,
				  	       billing_details: {
				  	         name: firstName + ' ' + lastName
				  	       }
				  	     }
				  	   }).then(function(result) {
				  	     if (result.error) {
				  	       // Show error to your customer (e.g., insufficient funds)
				  	       console.log(result.error.message);
				  	       $('#lp-card-errors').html('<p>' + result.error.message + '</p>');
				  	     } else {
				  	       // The payment has been processed!
				  	       if (result.paymentIntent.status === 'succeeded') {
				  	       	console.log('success');

				  	       	var form$ = jQuery('#leaky-paywall-payment-form');
				  	   		
				  	   		form$.get(0).submit();
				  	         // Show a success message to your customer
				  	      //   window.location.replace("http://conveners.test/");
				  	         // There's a risk of the customer closing the window before callback
				  	         // execution. Set up a webhook or plugin to listen for the
				  	         // payment_intent.succeeded event that handles any business critical
				  	         // post-payment actions.
				  	       }
				  	     }
				  	   });
				  	  });
				  	// });

				  	// Submit the form with the token ID.
				  	// old stripe submit handler

				});

				
			})( jQuery );

		  </script>

		  <style>
		  .StripeElement {
		    background-color: white;
		    height: auto;
		    padding: 10px 12px;
		    border-radius: 4px;
		    border: 1px solid transparent;
		    box-shadow: 0 1px 3px 0 #e6ebf1;
		    -webkit-transition: box-shadow 150ms ease;
		    transition: box-shadow 150ms ease;
		  }

		  .StripeElement--focus {
		    box-shadow: 0 1px 3px 0 #cfd7df;
		  }

		  .StripeElement--invalid {
		    border-color: #fa755a;
		  }

		  .StripeElement--webkit-autofill {
		    background-color: #fefde5 !important;
		  }
		</style>

		 <?php 

		 return ob_get_clean();
	}

	/**
	 * Validate additional fields during registration submission
	 *
	 * @since 4.0.0
	 */
	public function validate_fields() {

		if ( empty( $_POST['card_number'] ) ) {
			leaky_paywall_errors()->add( 'missing_card_number', __( 'The card number you entered is invalid', 'issuem-leaky-paywall' ), 'register' );
		}

	}


	/**
	 * Load Stripe JS. Need to load it on every page for Stripe Radar rules.
	 *
	 * @since  4.0.0
	 */
	public function scripts() {

		wp_enqueue_script( 'stripe', 'https://js.stripe.com/v3/', array( 'jquery' ) );

		// if ( is_home() || is_front_page() || is_archive() ) {
		// 	return;
		// }

		// $settings = get_leaky_paywall_settings();

		// if ( is_page( $settings['page_for_subscription'] ) || is_page( $settings['page_for_register'] ) || is_page( $settings['page_for_profile'] ) ) {
			
		// }
		
	}

}
