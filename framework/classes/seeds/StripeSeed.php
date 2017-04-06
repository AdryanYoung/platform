<?php
/**
 * The StripeSeed class speaks to the Stripe API.
 *
 * @package platform.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * Copyright (c) 2016, CASH Music
 * Licensed under the GNU Lesser General Public License version 3.
 * See http://www.gnu.org/licenses/lgpl-3.0.html
 *
 **/

namespace CASHMusic\Seeds;

use CASHMusic\Core\CASHConnection;
use CASHMusic\Core\CASHRequest;
use CASHMusic\Core\CASHSystem;
use CASHMusic\Core\SeedBase;
use CASHMusic\Admin\AdminHelper;

use Exception;
use AdamPaterson\OAuth2\Client\Provider\Stripe as StripeOAuth;

use Stripe\Account;
use Stripe\BalanceTransaction as BalanceTransaction;
use Stripe\Charge;
use Stripe\Error as StripeError;
use Stripe\Event;
use Stripe\Plan as Plan;
use Stripe\Stripe;
use Stripe\Subscription as Subscription;
use Stripe\Token;


class StripeSeed extends SeedBase
{
    protected $client_id, $client_secret, $error_message;
    public $publishable_key, $redirects;

    /**
     * StripeSeed constructor.
     * @param $user_id
     * @param $connection_id
     */
    public function __construct($user_id, $connection_id)
    {
        $this->settings_type = 'com.stripe';
        $this->user_id = $user_id;
        $this->connection_id = $connection_id;
        $this->redirects = false;

        if ($this->getCASHConnection()) {

            $settings_request = new CASHRequest(
                array(
                    'cash_request_type' => 'system',
                    'cash_action' => 'getsettings',
                    'type' => 'payment_defaults',
                    'user_id' => $user_id
                )
            );

            $connections = CASHSystem::getSystemSettings('system_connections');
            if (isset($connections['com.stripe'])) {
               $this->client_id = $connections['com.stripe']['client_id'];
               $this->client_secret = $connections['com.stripe']['client_secret'];
               $this->publishable_key = $connections['com.stripe']['publishable_key'];
            }

            $this->access_token = $this->settings->getSetting('access_token');
            $this->stripe_account_id = $this->settings->getSetting('stripe_account_id');

            if (CASH_DEBUG) {
               error_log(
                  'Initiated StripeSeed with: '
                  . '$this->client_id='            . (string)$this->client_id
                  . ', $this->client_secret='      . (string)$this->client_secret
                  . ', $this->publishable_key='    . (string)$this->publishable_key
                  . ', $this->access_token='       . (string)$this->access_token
                  . ', $this->stripe_account_id='  . (string)$this->stripe_account_id
               );
            }

            Stripe::setApiKey($this->access_token);
        } else {
            $this->error_message = 'could not get connection settings';
        }
    }

    /**
     * @param bool $data
     * @return string
     */
    public static function getRedirectMarkup($data = false)
    {
        $connections = CASHSystem::getSystemSettings('system_connections');
        if (isset($connections['com.stripe'])) {

            $redirect_uri = CASH_ADMIN_URL . '/settings/connections/add/com.stripe/finalize';

            $client = new StripeOAuth(
                array(
                    'clientId'          => $connections['com.stripe']['client_id'],
                    'clientSecret'      => $connections['com.stripe']['client_secret'],
                    'redirectUri'       => $redirect_uri
                )
            );

            $auth_url = $client->getAuthorizationUrl([
                'scope' => ['read_write'] // array or string
            ]);

            $return_markup = '<h4>Stripe</h4>'
                . '<p>This will redirect you to a secure login at Stripe and bring you right back. Note that you\'ll need a CASH page or secure site (https) to sell using Stripe. <a href="https://stripe.com/docs/security/ssl" target="_blank">Read more.</a></p>'
                . '<br /><br /><a href="' . $auth_url . '&redirect_uri=' . $redirect_uri.'" class="button">Connect with Stripe</a>';
            return $return_markup;
        } else {
            return 'Please add default stripe api credentials.';
        }
    }

    /**
     * This method is used during the charge process. It is used after receiving token generated from the Stripe Checkout Javascript.
     * It will send the token to Stripe to exchange for its information. Such information will be used throughout the charge process
     * (such as, create new user).
     *
     * This happens before the actual charge occurs.
     * @return bool|Token
     */
    public function getTokenInformation()
    {

        if ($this->token) {

            Stripe::setApiKey($this->access_token);
            $tokenInfo = Token::retrieve($this->token);
            if (!$tokenInfo) {
                $this->setErrorMessage('getTokenInformation failed: ' . $this->getErrorMessage());
                return false;
            } else {
                return $tokenInfo;
            }
        } else {
            $this->setErrorMessage("Token is Missing!");
            return false;
        }
    }

    /**
     * handleRedirectReturn
     * Handles redirect from API Auth for service
     * @param bool|false $data
     * @return string
     */
    public static function handleRedirectReturn($data = false)
    {

        $admin_helper = new AdminHelper();

        if (isset($data['code'])) {
            $connections = CASHSystem::getSystemSettings('system_connections');
            if (isset($connections['com.stripe'])) {
                //exchange the returned code for user credentials.
                $credentials = StripeSeed::getOAuthCredentials($data['code'],
                    $connections['com.stripe']['client_id'],
                    $connections['com.stripe']['client_secret']);

                if (isset($credentials['refresh_token'])) {
//                    //get the user information from the returned credentials.
//                    $user_info = StripeSeed::getUserInfo($credentials['access']);
                    //create new connection and add it to the database.

                    $new_connection = new CASHConnection($admin_helper->getPersistentData('cash_effective_user'));


                    $result = $new_connection->setSettings(
                        $credentials['stripe_user_id'] . " (Stripe)",
                        'com.stripe',
                        array(
                            'access_token' => $credentials['access_token'],
                            'publishable_key' => $credentials['stripe_publishable_key'],
                            'stripe_account_id' => $credentials['stripe_user_id']
                        )
                    );

                    if ($result) {
                        return array(
         						'id' => $result,
         						'name' => $credentials['stripe_user_id'] . ' (Stripe)',
         						'type' => 'com.stripe'
         					);
                    } else {

                        return [
                            'error' => [
                                'message' => 'Error. Could not save connection.',
                                'uri' => '/settings/connections/'
                            ]
                        ];
                    }
                } else {

                    return [
                        'error' => [
                            'message' => 'There was an error with the default Stripe app credentials',
                            'uri' => false
                        ]
                    ];

                }
            } else {
                return [
                    'error' => [
                        'message' => 'Please add default stripe app credentials.',
                        'uri' => false
                    ]
                ];
            }
        } else {
            return [
                'error' => [
                    'message' => 'There was an error. (session) Please try again.',
                    'uri' => false
                ]
            ];
        }
    }
    /**
     *
     * This method is used to exchange the returned code from Stripe with Stripe again to get the user credentials during
     * the authentication process.
     *
     * Exchange an authorization code for OAuth 2.0 credentials.
     *
     * @param String $authorization_code Authorization code to exchange for OAuth 2.0 credentials.
     * @param $client_id
     * @param $client_secret
     * @return String Json representation of the OAuth 2.0 credentials.
     */
    public static function getOAuthCredentials($authorization_code, $client_id, $client_secret)
    {
        try {
            $client = new StripeOAuth(
                array(
                'clientId'          => $client_id,
                'clientSecret'      => $client_secret
                )
            );

            $token = $client->getAccessToken('authorization_code', array(
                'code' => $authorization_code
            )
            );

            $token_values = $token->getValues();

            if (!empty($token_values)) {
                return array(
                    'access_token' => $token->access_token,
                    'refresh_token' => $token->refresh_token,
                    'stripe_publishable_key' => $token->stripe_publishable_key,
                    'stripe_user_id' => $token->stripe_user_id
                );
            }

            return false;

        } catch (Exception $e) {
            return false;
        }
    }


    /**
     * This method makes use of Stripe library in getting the user information from the returned credentials during
     * the authentication process.
     *
     * @param $credentials
     * @return Stripe\Account
     */
    public static function getUserInfo($credentials)
    {
        //require_once(CASH_PLATFORM_ROOT.'/lib/stripe/lib/Stripe.php');
        Stripe::setApiKey($credentials);

        $user_info = Account::retrieve();
        return $user_info;
    }

    /**
     * @param $msg
     */
    protected function setErrorMessage($msg)
    {
        $this->error_message = $msg;
        if (CASH_DEBUG) {
          error_log($this->error_message);
       }
    }

    /**
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->error_message;
    }

    /**
     * We don't need this for Stripe, since the checkout.js library handles it. Let's leave it for consistency across seeds, though
     *
     * @return bool
     */
    public function preparePayment() {
        return false;
    }

    /**
     * Fired from finalizeRedirectedPayment, in CommercePlant. Sends the actual charge and seedToken to the Stripe API—
     * this is really where almost everything happens for StripeSeed charges.
     *
     * @param $total_price
     * @param $description
     * @param $token
     * @param $email_address
     * @param $customer_name
     * @param $shipping_info
     * @return array|bool
     */
    public function doPayment($total_price, $description, $token, $email_address=false, $customer_name=false, $currency='usd') {

      if (CASH_DEBUG) {
         error_log(
            'Called StripeSeed::doPayment with: '
            . '$total_price='       . (string)$total_price
            . ', $description='     . (string)$description
            . ', $token='           . (string)$token
            . ', $email_address='   . (string)$email_address
            . ', $customer_name='   . (string)$customer_name
            . ', $currency='        . (string)$currency
         );
      }

    if (!empty($token)) {

        try {
            Stripe::setApiKey($this->access_token);

            if (!$payment_results = Charge::create(
                array(
                    "amount" => ($total_price * 100),
                    "currency" => $currency,
                    "source" => $token, // obtained with Stripe.js
                    "description" => $description
                ),
                array(
                   "stripe_account" => $this->stripe_account_id
                ) // stripe connect, charge goes to oauth user instead of cash
            )) {
                $this->setErrorMessage("In StripeSeed::doPayment. Stripe payment failed.");
                return false;
            }
            } catch (StripeError\Card $e) {
               $this->setErrorMessage("In StripeSeed::doPayment. " . $e->getMessage());
               return false;
            } catch (StripeError\InvalidRequest $e) {
               $this->setErrorMessage("In StripeSeed::doPayment. " . $e->getMessage());
               return false;
            } catch (StripeError\Authentication $e) {
               $this->setErrorMessage("In StripeSeed::doPayment. " . $e->getMessage());
               return false;
            } catch (StripeError\ApiConnection $e) {
               $this->setErrorMessage("In StripeSeed::doPayment. " . $e->getMessage());
               return false;
            } catch (StripeError\Base $e) {
               $this->setErrorMessage("In StripeSeed::doPayment. " . $e->getMessage());
               return false;
            } catch (Exception $e) {
               $this->setErrorMessage("In StripeSeed::doPayment. There was an issue with your Stripe API request. Exception: " . json_encode($e));
               return false;
            }


        } else {
            $this->setErrorMessage("In StripeSeed::doPayment. No Stripe token found.");
            return false;
        }

        // check if Stripe charge was successful

        if ($payment_results->status == "succeeded") {

            // look up the transaction fees taken off the top, for record
            $transaction_fees = BalanceTransaction::retrieve($payment_results->balance_transaction,
                array("stripe_account" => $this->stripe_account_id));
            // we can actually use the BalanceTransaction::retrieve method as verification that the charge has been placed
            if (!$transaction_fees) {
                error_log("Balance transaction failed, is this a valid charge?");
                $this->setErrorMessage("In StripeSeed::doPayment. Balance transaction failed, is this a valid charge?");
                return false;
            }

            $full_name = explode(' ', $customer_name, 2);
            // nested array for data received, standard across seeds
            $order_details = array(
                'transaction_description' => '',
                'customer_email' => $email_address,
                'customer_first_name' => $full_name[0],
                'customer_last_name' => $full_name[1],
                'customer_name' => $customer_name,

                'customer_phone' => '',
                'transaction_date' => $payment_results->created,
                'transaction_id' => $payment_results->id,
                'sale_id' => $payment_results->id,
                'items' => array(),
                'total' => number_format(($payment_results->amount / 100),2,'.', ''),
                'other_charges' => array(),
                'transaction_fees' => number_format(($transaction_fees->fee / 100),2,'.', ''),
                'refund_url' => $payment_results->refunds->url,
                'status' => "complete"
            );

            return array('total' => number_format(($payment_results->amount / 100),2,'.', ''),
                'customer_email' => $email_address,
                'customer_first_name' => $full_name[0],
                'customer_last_name' => $full_name[1],
                'customer_name' => $customer_name,

                'timestamp' => $payment_results->created,
                'transaction_id' => $payment_results->id,
                'service_transaction_id' => $payment_results->id,
                'service_charge_id' => $payment_results->balance_transaction,
                'service_fee' => number_format(($transaction_fees->fee / 100),2,'.', ''),
                'order_details' => $order_details
            );
        } else {

            $this->setErrorMessage("In StripeSeed::doPayment. Error with Stripe payment.");
            return false;
        }

    }


    /**
     * Fired from cancelOrder, in CommercePlant. Sends charge token to the Stripe API with our client secret in order to do full refund.
     *
     * @param $sale_id
     * @param int $refund_amount
     * @param string $currency_id
     * @return bool|\Stripe\Refund
     */
    public function refundPayment($sale_id, $refund_amount = 0, $currency_id = 'USD')
    {

        // try to contact the stripe API for refund, or fail gracefully
        try {
            Stripe::setApiKey($this->access_token);

            $refund_response = \Stripe\Refund::create(array(
                "charge" => $sale_id
            ),array("stripe_account" => $this->stripe_account_id));
        } catch (StripeError\RateLimit $e) {
            // Too many requests made to the API too quickly
            $body = $e->getJsonBody();
            $this->setErrorMessage("In StripeSeed::refundPayment. Stripe API rate limit exceeded: " . $body['error']['message']);
            return false;

        } catch (StripeError\InvalidRequest $e) {
            // Invalid parameters were supplied to Stripe's API
            $body = $e->getJsonBody();
            $this->setErrorMessage("In StripeSeed::refundPayment. Invalid Stripe refund request: " . $body['error']['message']);
            return false;

        } catch (StripeError\Authentication $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            $body = $e->getJsonBody();
            $this->setErrorMessage("In StripeSeed::refundPayment. Could not authenticate Stripe: " . $body['error']['message']);
            return false;

        } catch (StripeError\ApiConnection $e) {
            // Network communication with Stripe failed
            $body = $e->getJsonBody();
            $this->setErrorMessage("In StripeSeed::refundPayment. Could not communicate with Stripe API: " . $body['error']['message']);
            return false;

        } catch (StripeError\Base $e) {
            // Display a very generic error to the user, and maybe send
            // yourself an email
            $body = $e->getJsonBody();
            $this->setErrorMessage("In StripeSeed::refundPayment. General Stripe error: " . $body['error']['message']);
            return false;

        } catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            $body = $e->getJsonBody();
            $this->setErrorMessage("In StripeSeed::refundPayment. Something went wrong: " . $body['error']['message']);
            return false;

        }

        // let's make sure that the object returned is a successful refund object
        if ($refund_response->object == "refund") {
            return $refund_response;
        } else {
            $this->setErrorMessage("In StripeSeed::refundPayment. Something went wrong while issuing this refund.");
            return false;
        }
    }

    /**
     * @param bool $limit
     * @return bool|\Stripe\Collection
     */
    public function getAllSubscriptionPlans($limit=false) {
        try {
            Stripe::setApiKey($this->access_token);
            if ($limit) {
                $plans = Plan::all([
                    "limit" => $limit
                ]);
            } else {
                $plans = Plan::all();
            }

        } catch(Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        //TODO: we'll need to actually parse these, potentially
        //this is fine for now, though
        return $plans;
    }

    /**
     * @param $plan_id
     * @return bool|\Stripe\Plan
     */
    public function getSubscriptionPlan($plan_id) {
        try {
            Stripe::setApiKey($this->access_token);
            $plan = Plan::retrieve($plan_id);
        } catch(Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        if (empty($plan)) return false;

        return $plan;
    }

    /**
     * @param $name
     * @param int $amount (in cents)
     * @param string $interval (day|week|month|year)
     * @param string $currency
     * @return bool
     */
    public function createSubscriptionPlan($name, $sku, $amount=1, $interval="month", $currency="usd") {

        try {
            Stripe::setApiKey($this->access_token);
            $plan = Plan::create(array(
                    "amount" => $amount,
                    "interval" => $interval,
                    "name" => $name,
                    "currency" => $currency,
                    "id" => $sku)
            );
        } catch(Exception $e) {
                if (CASH_DEBUG) {
                    error_log(
                        "StripeSeed->createSubscriptionPlan error: \n".
                        $e->getMessage()
                    );
                }

            //TODO: if plan exists we should return it maybe

            return false;
        }

        return $sku;
    }

    /**
     * updateSubscriptionPlan
     *
     * This is minimal: for security reasons you cannot change amount, interval after a plan has
     * been created.
     *
     * @param string $plan_id
     * @param bool $name
     * @param bool $currency
     * @return bool
     */
    public function updateSubscriptionPlan($plan_id, $name=false, $currency=false) {
        // did we even get passed any values to update?
        if (empty($name)) return false;

        // otherwise--first we need to retrieve the plan by id
        Stripe::setApiKey($this->access_token);

        if (!$plan = $this->getSubscriptionPlan($plan_id)) return false;

        // if we've made it this far, the plan exists and we can go ahead and update it

        if ($name) $plan->name = $name;
        if ($currency) $plan->currency = $currency;

        try {
            $plan->save();
        } catch(Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        // thumbs up!
        return true;
    }

    /**
     * @param string $plan_id
     * @return bool
     */

    public function deleteSubscriptionPlan($plan_id) {

        // get the plan by id
        if (!$plan = $this->getSubscriptionPlan($plan_id)) return false;

        try {
            $plan->delete();
        } catch (Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        return true;
    }

    public function getAllSubscriptionsForPlan($plan_id, $limit=10) {
        try {
            Stripe::setApiKey($this->access_token);
            $subscriptions = Subscription::all([
                'plan' => $plan_id,
                'limit' => $limit
            ]);

        } catch (Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        return $subscriptions;
    }

    /**
     * @param $subscription_id
     * @return bool
     */

    public function getSubscription($subscription_id) {

        try {
            Stripe::setApiKey($this->access_token);
            $subscription = Subscription::retrieve($subscription_id);

        } catch (Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        return $subscription;
    }

    private function createCustomer($email, $token) {

        /*
        we need to check currency to make sure it's not fractional for these
        BIF: Burundian Franc
        CLP: Chilean Peso
        DJF: Djiboutian Franc
        GNF: Guinean Franc
        JPY: Japanese Yen
        KMF: Comorian Franc
        KRW: South Korean Won
        MGA: Malagasy Ariary
        PYG: Paraguayan Guaraní
        RWF: Rwandan Franc
        VND: Vietnamese Đồng
        VUV: Vanuatu Vatu
        XAF: Central African Cfa Franc
        XOF: West African Cfa Franc
        XPF: Cfp Franc
         */
        if (CASH_DEBUG) {
            error_log(
                "customer creation start "
            );
        }
        try {
            Stripe::setApiKey($this->access_token);
            $customer = \Stripe\Customer::create(array(
                "email" => $email,
                "source" => $token
            ));
        } catch (Exception $e) {
            return false;
        }

        return $customer->id;
    }

    /**
     * @param string $token
     * @param string $plan_id
     * @param string $email
     * @param int $quantity
     * @return bool
     */

    public function createSubscription($token, $plan_id, $email, $quantity=1) {
        if (!$customer = $this->createCustomer($email, $token)) {
            return false;
        }

        try {
            Stripe::setApiKey($this->access_token);
            $subscription = Subscription::create(array(
                "customer" => $customer, // obtained from Stripe.js
                "plan" => $plan_id,
                "quantity" => $quantity
            ));

        } catch (Exception $e) {
            //if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            //}

            return false;
        }

        return $subscription;
    }

    /**
     * Pretty simplistic---I believe we can only update plans here. They can be automatically pro-rated.
     *
     * @param $subscription_id
     * @param $plan_id
     * @param bool $prorate
     * @param int $quantity
     * @return bool
     */
    public function updateSubscription($subscription_id, $plan_id, $prorate=true, $quantity=1) {

        // first we need to retrieve the subscription by id
        if (!$subscription = $this->getSubscription($subscription_id)) return false;

        try {
            $subscription->plan = $plan_id;
            $subscription->prorate = $prorate;
            $subscription->quantity = $quantity;

            $subscription->save();

        } catch (Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        return $subscription;
    }

    /**
     * @param $subscription_id
     * @return bool
     */
    public function cancelSubscription($subscription_id) {
        // first we need to retrieve the subscription by id
        if (!$subscription = $this->getSubscription($subscription_id)) {

            error_log("not getting anything back");
            return false;
        }

        try {

            $subscription->cancel();

        } catch (Exception $e) {
            if (CASH_DEBUG) {
                error_log(
                    print_r($e->getMessage())
                );
            }

            return false;
        }

        return $subscription;
    }

    public function webhookTransaction($url, $events=array()) {
        $_params = array("url" => $url, "events" => $events);

        Stripe::setApiKey($this->access_token);

        // Retrieve the request's body and parse it as JSON
        $input = @file_get_contents("php://input");
        $event_json = json_decode($input);

        // Verify the event by fetching it from Stripe
        $event = Event::retrieve($event_json->id);


        return $this->api->call('webhooks/transaction', $_params);
    }


} // END class
