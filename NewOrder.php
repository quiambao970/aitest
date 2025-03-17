<?php
//>things that happen
//>receive invoice json
//>if invoice is $0 skip?
//>calculate next plan, affect accordingly. trialdate, coupon, prorate, 
//>Modify subscription item. 
//>Update sales tax paid
//>Ordernumbers total count?
//>get shipping address from card
//>insert order
//>insert order history
//>insert adventure ticket
//>return response code
//sleep(5);

header('Status: 200');

use Stripe\Charge;
use Stripe\Customer;
use Stripe\Stripe;
//use SnackCrate\Classes\SCKlaviyoHelper;

include_once 'klaviyo-helper-new.php';
include_once 'inventree.php';



// @note - this is to bypass the troll bridge in the keys file; this DOES NOT actually set the cookie on a users browser
$_COOKIE['snackcrate_development'] = 'true';

require_once dirname(__DIR__, 2) . "/keys.php";
require_once __DIR__ . "/../../../wp-load.php";
try {

    // in the event of an error this will cause a json output
    $_SERVER["CONTENT_TYPE"] = 'application/json';


    ini_set("log_errors", 1);
    ini_set('display_errors', 1);
    ini_set("error_log", "php-error.log");
    ini_set('max_execution_time', 120);
    set_time_limit(120);

    function inventoryUpdate($country, $plan)
    {
        global $dbh, $directus;

        $drink = stripos($plan, 'W') !== false ? 1 : 0;

        $stmt = $directus->prepare('SELECT * FROM plans WHERE plan_id=:plan_id');
        $stmt->bindParam(':plan_id', $plan);
        $stmt->execute();
        $dbPlan = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;

        if ($dbPlan && $dbPlan['fulfill_as_base'] != "") {
            $fulfill = $dbPlan['fulfill_as_base'];
        } else {
            $fulfill = $plan;
        }

        $fulfill = str_replace('W', '', $fulfill);

        $stmt = $dbh->prepare('SELECT * FROM `Inventory_orders` WHERE `country`= :country AND `size` = :size LIMIT 1');
        $stmt->bindParam(':country', $country);
        $stmt->bindParam(':size', $fulfill);
        $stmt->execute();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;
        if ($item) {

            $stmt = $dbh->prepare('UPDATE Inventory_orders SET `placed` = (`placed`-1) WHERE `country`=:country AND `size`=:size ');
            $stmt->bindParam(':country', $country);
            $stmt->bindParam(':size', $fulfill);
            $stmt->execute();
            $stmt = null;
            if ($item['notice_threshold'] == $item['placed'] - 1) {
                mail('8507379056@tmomail.net', $fulfill . ' - ' . $country . ' Stock Notice', 'This is a notice for ' . $fulfill . ' - ' . $country);
            }

        } else {

            $stmt = $dbh->prepare('INSERT INTO Inventory_orders (`placed`,`country`,`size`)VALUES (-1,:country,:size)');
            $stmt->bindParam(':country', $country);
            $stmt->bindParam(':size', $fulfill);
            $stmt->execute();
            $stmt = null;

        }

        //>do again if drink
        if ($drink) {
            $fulfill = NULL;
            $country = stripos($plan, 'W') !== false ? $country . "-Drink" : $country;

            $stmt = $dbh->prepare('SELECT * FROM `Inventory_orders` WHERE `country`= :country LIMIT 1');
            $stmt->bindParam(':country', $country);
            $stmt->execute();
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = null;
            if ($item) {
                $stmt = $dbh->prepare('UPDATE Inventory_orders SET `placed` = (`placed`-1) WHERE `country`=:country');
                $stmt->bindParam(':country', $country);
                $stmt->execute();
                $stmt = null;
            } else {
                $stmt = $dbh->prepare('INSERT INTO Inventory_orders (`placed`,`country`)VALUES (-1,:country)');
                $stmt->bindParam(':country', $country);
                $stmt->execute();
                $stmt = null;
            }
        }
    }

    function createToken($id)
    {
        global $dbh;
        $token = bin2hex(openssl_random_pseudo_bytes(8));
        
        $stmt2 = $dbh->prepare('UPDATE `Users` SET `cardToken` = :token WHERE `customer_id` = :id');
        $stmt2->bindParam(':id', $id);
        $stmt2->bindParam(':token', $token);
        $stmt2->execute();
        $stmt2 = null;
        
        return $token;
    }

    function slack($message, $channel)
    {
        $ch = curl_init("https://slack.com/api/chat.postMessage");
        $data = http_build_query([
            "token" => $_ENV['slack_api_token'],
            "channel" => $channel, 
            "text" => $message,
            "username" => "InventreeBot",
        ]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result;
    }

    try {

        Stripe::setApiKey($_ENV['stripe_api_key_live']);

        $servername = $_ENV['cr_db_host'];
        $username = $_ENV['cr_db_username'];
        $password = $_ENV['cr_db_password'];
        $dbname = $_ENV['cr_db_dbname'];


        $dbh = new PDO('mysql:dbname=' . $dbname . ';host=' . $servername . ';charset=utf8', $username, $password);
        $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $directus = new PDO('mysql:dbname=snackcratedb;host=' . $_ENV['cr_db_host'] . ';charset=utf8', $_ENV['cr_db_username'], $_ENV['cr_db_password']);
        $directus->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dbcb = new PDO('mysql:dbname=' . $_ENV['cbwp_db_dbname'] . ';host=' . $_ENV['cbwp_db_host'] . ';charset=utf8', $_ENV['cbwp_db_username'], $_ENV['cbwp_db_password']);
        $dbcb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $input = file_get_contents("php://input");
        $event_json = json_decode($input);

        $stmt = $dbh->prepare('INSERT INTO `UserActions` (`type`,`post`) VALUES ("NewOrderHit",:post)');
        $stmt->bindParam(':post', $input);
        $stmt->execute();
        $stmt = null;

        set_time_limit(120);

        //>load plans
        $stmt = $dbh->prepare('SELECT * FROM `SignupPlans`');
        $stmt->execute();
        $signupPlans = array();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $signupPlans[$row['plan_id']] = $row;
        }
        $stmt = null;

        $added_current_month = false;

        $invoice = $event_json->data->object;

        $customer_id = $invoice->customer;

        $subscription_id = $invoice->subscription;

        $invoiceid = $invoice->id;

        $chargeID = $invoice->charge;

        $amount = $invoice->amount_due / 100; // amount comes in as amount in cents, so we need to convert to dollars
        $amount_paid = $invoice->amount_paid;

        $customerobject = Customer::retrieve($customer_id);

        $email = $customerobject->email;

        if (empty($email)) {
            $stmt = $dbh->prepare("SELECT email FROM Users WHERE customer_ID = :customer_id");
            $stmt->bindParam(":customer_id", $customer_id);
            $stmt->execute();
            $email = $stmt->fetch(PDO::FETCH_COLUMN);
            $stmt = null;
        }

        $stmt = $dbh->prepare('SELECT * FROM `Users` WHERE `customer_ID` = :customer_id');
        $stmt->bindParam(':customer_id', $customer_id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;

        if (!empty($chargeID)) {
            $charge_object = Charge::retrieve($chargeID);
            $card_year = $charge_object->card->exp_year;
            $card_month = $charge_object->card->exp_month;
            $current_year = date('Y');
            $current_month = date('n');

            if (
                (
                    $current_month == 12 &&
                    (
                        $card_year == $current_year + 1 &&
                        $card_month == 1
                    )
                )
                ||
                (
                    $card_year == $current_year &&
                    $card_month == $current_month + 1
                )
            ) {
                if($user) {
                    if($user['cardToken'] == '') {
                        $token = createToken($user['customer_ID']);
                        $payment_url = 'https://snackcrate.com/update-card/?token='.$token;
                    } else {
                        $payment_url = 'https://snackcrate.com/update-card/?token='.$user['cardToken'];
                    }

                    SCKlaviyoHelper::getInstance()->sendEvent(
                        'Upcoming Card Expiration',
                        $email,
                        array(),
                        array(
                            'url' => $payment_url,
                            'last4' => $charge_object->card->last4,
                            'exp_month' => $charge_object->card->exp_month,
                            'exp_year' => $charge_object->card->exp_year,
                        )
                    );
                }
            }
        }



        $main_subscription_item = current(
            array_filter(
                $invoice->lines->data,
                function ($sub_item) {
                    return strpos($sub_item->plan->id ?? '', 'Snack') !== false;
                }
            )
        );


        if (empty($main_subscription_item)) {
            $invoiceplan = $invoice->lines->data[0]->plan->id;
        } else {
            $invoiceplan = $main_subscription_item->plan->id;
        }

        set_time_limit(120);

        $stripe_subscription_item_id = $main_subscription_item->subscription_item; // setting this variable here in case this is a multi month sub that needs updated/canceled...

        $tax = $invoice->tax;
        $total = $invoice->total;
        $subtotal = $invoice->subtotal;
        $firstcountry = $customerobject->metadata->First_Country;

        if($firstcountry == 'Mystery||Double Destination Deal') {
            $firstcountry = 'Mystery';
        }

         $county = (isset($customerobject->metadata->Tax_County)) ? $customerobject->metadata->Tax_County : false;
        $signupMethod = (isset($customerobject->metadata->Signup_Method)) ? strtolower($customerobject->metadata->Signup_Method) : false;
        $verification = (isset($customerobject->metadata->Address_Verified)) ? strtolower($customerobject->metadata->Address_Verified) : "";
        $signature = (isset($customerobject->metadata->Signature_Confirmation)) ? strtolower($customerobject->metadata->Signature_Confirmation) : "";

        if ($subtotal == 0) {
            http_response_code(200);
            echo 'Zero Total';
            exit();
            return;
        }

        if ($invoiceplan == "shopsub") {
            http_response_code(200);
            echo 'Dont add shopsub';
            exit();
            return;
        }

        $stmt = $dbh->prepare('SELECT * FROM `Orders` WHERE `Payment_ID` = :id');
        $stmt->bindParam(':id', $invoiceid);
        $stmt->execute();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;

        if ($item) {
            $stmt = $dbh->prepare('INSERT INTO `UserActions` (`type`,`customer_id`)VALUES ("Duplicate Order Webhook",:id)');
            $stmt->bindParam(':id', $customer_id);
            $stmt->execute();
            $stmt = null;

            http_response_code(200);
            echo 'Order Already Placed';
            exit();
            return;
        }

        set_time_limit(120);

        $stmt = $dbh->prepare("SELECT * FROM Subscriptions WHERE subscription_id = :subscription_id");
        $stmt->bindParam(':subscription_id', $subscription_id);
        $stmt->execute();
        $subscription_data = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;
    
        
        $is_birthday_month = false;
        $is_US_Citizen = false;
        $is_awarded_birthday_gift = false;
        if($subscription_data && !empty($subscription_data['birthday'])) { 
            $birthday = date($subscription_data['birthday']);
            //check if this birthday is in this month
            $sub_country = $subscription_data['country'];
            if($birthday !== NULL) {
                $birthday_month = date('n', strtotime($birthday));
            }
            else {
                $birthday_month = 0;
            }
            $current_month = date('n');
            
            if($current_month == $birthday_month) {
                $is_birthday_month = true;
            }

            if($sub_country == 'US' || $sub_country == 'United States of America') {
                $is_US_Citizen = true;
            }

            $birthday_gift_date = date($subscription_data['birthday_gift_date']);
            if($birthday_gift_date !== NULL){
                   
                $gift_year = date('Y', strtotime($birthday_gift_date));
                $current_year = date('Y');
                if($gift_year == $current_year) {
                    $is_awarded_birthday_gift = true;
                }
            }
        }

        if ($email == null && strpos(strtolower($invoiceplan), 'free') === false) {
            $stmt = $dbh->prepare('INSERT INTO `UserActions` (`type`,`customer_id`,`var1`)VALUES ("NewOrder Missing Email",:id,"start")');
            $stmt->bindParam(':id', $customer_id);
            $stmt->execute();
            $stmt = null;
            mail("help@snackcrate.com", "Missing Stripe Email " . $customer_id, "This customer does not have an email on their Stripe account. Their order was not placed. " . $customer_id);
        }

        $stmt = $dbh->prepare('INSERT INTO `UserActions` (`type`,`customer_id`,`var1`,`post`)VALUES ("Order Webhook Track",:id,"start",:post)');
        $stmt->bindParam(':id', $customer_id);
        $stmt->bindParam(':post', $input);
        $stmt->execute();
        $stmt = null;
        $db_id = $dbh->lastInsertId();

        // update paymentfailure table if necessary
        $stmt = $dbh->prepare("DELETE FROM `PaymentFailure` WHERE invoice = :invoice");
        $stmt->bindParam(':invoice', $invoiceid);
        $stmt->execute();
        $stmt = null;


        $stmt = $dbh->prepare('SELECT * FROM `customers` WHERE `id`=:id');
        $stmt->bindParam(':id', $customer_id);
        $stmt->execute();
        $db_customer = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;
        $months_left = 0; //> related to having starter plans be non-commitment.

        if (!$db_customer) {
            print 'No DB Customer Found';
            $message = 'No DB Customer Found ' . $customer_id;
            $stmt = $dbh->prepare('INSERT INTO `error_log` (`message`)VALUES (:message)');
            $stmt->bindParam(':message', $message);
            $stmt->execute();
            $stmt = null;
        } //probably email support with details.}

        set_time_limit(120);




        /*
        if($db_customer && $db_customer['monthsleft'] > 0)
        {
            $stmt = $dbh->prepare('UPDATE `customers` SET `monthsleft` = monthsleft-1 WHERE id = :id');
            $stmt->bindParam(':id', $db_customer['id']);
            $stmt->execute();
            $stmt = null;
            $db_customer['monthsleft']--;
            $months_left = $db_customer['monthsleft'];
        }
        */


        try {
            //>give new plan
            //>apply new coupon
            if ($signupMethod !== false) {
                switch ($signupMethod) {
                    case "starter":
                        $upgradePlan = $signupPlans[$signupPlans[$invoiceplan]['commitment_id']]['plan_id'];

                        if ($months_left <= 0) {
                            $upgradePlan = $signupPlans[$signupPlans[$invoiceplan]['monthly_id']]['plan_id'];
                        }
                        $customerobject->metadata['Signup_Method'] = NULL;

                        $upcominginvoice = \Stripe\Invoice::upcoming(array("customer" => $customer_id));
                        $upcomingplan = $customerobject->subscriptions->data[0]->id;
                        $date = $upcominginvoice->date;

                        $planname = $customerobject->subscriptions->data[0]->items->data[0]->plan->id;
                        $plan = $customerobject->subscriptions->data[0]->id;

                        $subscription = \Stripe\Subscription::retrieve(
                            $upcomingplan,
                            array(
                                'stripe_version' => '2020-08-27'
                            )
                        );
                        $subscription->plan = $upgradePlan;
                        $subscription->trial_end = $date;
                        $subscription->coupon = '5PercentOff';
                        $subscription->prorate = false;
                        $subscription->save();
                        break;

                    case "mini":
                        $upgradePlan = $signupPlans[$signupPlans[$invoiceplan]['commitment_id']]['plan_id'];

                        if ($invoiceplan == "8Snack") {
                            $upgradePlan = "8Snack";
                        } elseif ($invoiceplan == "8SnackW") {
                            $upgradePlan = "8SnackW";
                        }

                        $customerobject->metadata['Signup_Method'] = NULL;

                        $upcominginvoice = \Stripe\Invoice::upcoming(array("customer" => $customer_id));
                        $upcomingplan = $customerobject->subscriptions->data[0]->id;
                        $date = $upcominginvoice->date;

                        $planname = $customerobject->subscriptions->data[0]->items->data[0]->plan->id;
                        $plan = $customerobject->subscriptions->data[0]->id;

                        $subscription = \Stripe\Subscription::retrieve(
                            $upcomingplan,
                            array(
                                'stripe_version' => '2020-08-27'
                            )
                        );
                        $subscription->plan = $upgradePlan;
                        $subscription->trial_end = $date;
                        $subscription->coupon = '5PercentOff';
                        $subscription->prorate = false;
                        $subscription->save();
                        break;

                    case "office":
                        $upgradePlan = "BusinessFull";

                        $customerobject->metadata['Signup_Method'] = NULL;

                        $upcominginvoice = \Stripe\Invoice::upcoming(array("customer" => $customer_id));

                        $upcomingplan = $customerobject->subscriptions->data[0]->id;

                        $date = $upcominginvoice->date;

                        $planname = $customerobject->subscriptions->data[0]->items->data[0]->plan->id;

                        $plan = $customerobject->subscriptions->data[0]->id;

                        $subscription = \Stripe\Subscription::retrieve(
                            $upcomingplan,
                            array(
                                'stripe_version' => '2020-08-27'
                            )
                        );

                        $subscription->plan = $upgradePlan;

                        $subscription->trial_end = $date;

                        $subscription->prorate = false;

                        $subscription->save();

                        break;

                    case "startermonthly":
                        $upgradePlan = $signupPlans[$signupPlans[$invoiceplan]['monthly_id']]['plan_id'];
                        $customerobject->metadata['Signup_Method'] = NULL;

                        $upcominginvoice = \Stripe\Invoice::upcoming(array("customer" => $customer_id));
                        $upcomingplan = $customerobject->subscriptions->data[0]->id;
                        $date = $upcominginvoice->date;

                        $planname = $customerobject->subscriptions->data[0]->items->data[0]->plan->id;
                        $plan = $customerobject->subscriptions->data[0]->id;

                        $subscription = \Stripe\Subscription::retrieve(
                            $upcomingplan,
                            array(
                                'stripe_version' => '2020-08-27'
                            )
                        );
                        $subscription->plan = $upgradePlan;
                        $subscription->trial_end = $date;
                        $subscription->coupon = '5PercentOff';
                        $subscription->prorate = false;
                        $subscription->save();
                        break;

                    case "claimgift":
                        $customerobject->metadata['Signup_Method'] = NULL;
                        $firstcountry = "Mystery";
                        break;

                    case "onboard":
                        $customerobject->metadata['Signup_Method'] = NULL;
                        break;

                    default:
                        $customerobject->metadata['Signup_Method'] = NULL;
                        break;
                }
            }
        } catch (Exception $e) {
            // carry on
        }



        set_time_limit(120);

        if ($firstcountry != "Current") {
            $customerobject->metadata['First_Country'] = "Current";
        }
        $customerobject->save();


        $name = "";
        $addressline1 = "";
        $addressline2 = "";
        $city = "";
        $state = "";
        $zip = "";
        $country = "";

        if (strpos(strtolower($invoiceplan), 'gift') !== false) {
            $gift_subscription = \Stripe\Subscription::retrieve(
                $subscription_id,
                array(
                    'stripe_version' => '2020-08-27'
                )
            );

            if (!empty($gift_subscription->metadata->giftcode)) // new way
            {
                $giftcode = $gift_subscription->metadata->giftcode;

                /*
                $firstcountry = $gift_subscription->metadata->First_Country;
                if( $gift_subscription->metadata->{'First_Country'} != 'Current' )
                {
                    $gift_subscription->metadata->{'First_Country'} = 'Current';
                    $gift_subscription->save();
                }
                */

                $stmt = $dbh->prepare('SELECT * FROM GiftCertificates where giftcode = :code');
                $stmt->bindParam(':code', $giftcode);
                $stmt->execute();
                $giftInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = null;

                if ($giftInfo) {
                    $name = htmlspecialchars($giftInfo["ShippingName"]);
                    $addressline1 = htmlspecialchars($giftInfo["address"]);
                    $addressline2 = "";
                    $city = htmlspecialchars($giftInfo["city"]);
                    $state = htmlspecialchars($giftInfo["state"]);
                    $zip = htmlspecialchars($giftInfo["zip"]);
                    $country = htmlspecialchars($giftInfo["country"]);
                }

                $stmt = $dbh->prepare('UPDATE GiftCertificates SET numofmonths = numofmonths - 1 WHERE giftcode = :code');
                $stmt->bindParam(':code', $giftcode);
                $stmt->execute();
                $stmt = null;

                if (($giftInfo['numofmonths'] - 1) <= 0 && $gift_subscription->status != 'canceled') {
                    $gift_subscription->cancel();

                    $stmt = $dbh->prepare("UPDATE Subscriptions SET is_active = 0 WHERE subscription_id = :subscription_id");
                    $stmt->bindParam(":subscription_id", $subscription_id);
                    $stmt->execute();
                    $stmt = null;
                }
            } else // old way
            {
                $giftcode = $customerobject->description;

                $stmt = $dbh->prepare('SELECT * FROM GiftCertificates where giftcode = :code');
                $stmt->bindParam(':code', $giftcode);
                $stmt->execute();
                $giftInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = null;

                if ($giftInfo) {
                    $name = htmlspecialchars($giftInfo["ShippingName"]);
                    $addressline1 = htmlspecialchars($giftInfo["address"]);
                    $addressline2 = "";
                    $city = htmlspecialchars($giftInfo["city"]);
                    $state = htmlspecialchars($giftInfo["state"]);
                    $zip = htmlspecialchars($giftInfo["zip"]);
                    $country = htmlspecialchars($giftInfo["country"]);
                }

                $stmt = $dbh->prepare('UPDATE GiftCertificates SET numofmonths=numofmonths -1 WHERE giftcode=:code');
                $stmt->bindParam(':code', $giftcode);
                $stmt->execute();
                $stmt = null;

                if (($giftInfo['numofmonths'] - 1) <= 0) {
                    $upcomingplan = $customerobject->subscriptions->data[0]->id;
                    $subscription = \Stripe\Subscription::retrieve(
                        $upcomingplan,
                        array(
                            'stripe_version' => '2020-08-27'
                        )
                    );
                    $subscription->cancel();
					

                    $stmt = $dbh->prepare("UPDATE Subscriptions SET is_active=0 WHERE subscription_id=:subscription_id");
                    $stmt->bindParam(":subscription_id", $subscription_id);
                    $stmt->execute();
                    $stmt = null;    
                }
            }

        } else {

            $stmt = $dbh->prepare('SELECT * FROM Subscriptions where subscription_id = :subscription_id');
            $stmt->bindParam(":subscription_id", $subscription_id);
            $stmt->execute();
            $subscription_row = $stmt->fetch(PDO::FETCH_OBJ);
            $stmt = null;

            $subscription = \Stripe\Subscription::retrieve(
                $subscription_id,
                array(
                    'stripe_version' => '2020-08-27'
                )
            );

             

            if (!empty($subscription) && !empty($subscription->metadata["ordered_current_month"]) && $subscription->metadata["ordered_current_month"] == 1) {
                $added_current_month = true;
                $subscription->metadata["ordered_current_month"] = 0;
                $subscription->save();

                $firstcountry = 'Current';
            }

            if ($subscription_row) // new way
            {
                $name = htmlspecialchars($subscription_row->shipping_name);
                $addressline1 = htmlspecialchars($subscription_row->address);
                $addressline2 = htmlspecialchars($subscription_row->suite);
                $city = htmlspecialchars($subscription_row->city);
                $state = htmlspecialchars($subscription_row->state);
                $zip = htmlspecialchars($subscription_row->zip);
                $country = htmlspecialchars($subscription_row->country);
            } elseif (!empty($subscription->metadata->shipping_name)) // check metadata in case subscription hasn't gone into our table at this point in the process
            {
                $name = htmlspecialchars($subscription->metadata->shipping_name);
                $addressline1 = htmlspecialchars($subscription->metadata->address);
                $addressline2 = empty($subscription->metadata->suite) ? '' : htmlspecialchars($subscription->metadata->suite);
                $city = htmlspecialchars($subscription->metadata->city);
                $state = htmlspecialchars($subscription->metadata->state);
                $zip = htmlspecialchars($subscription->metadata->zip);
                $country = htmlspecialchars($subscription->metadata->country);
            } else // old way fallback
            {
                $charge_obj = Charge::retrieve($invoice->charge);
                $customerinfo = $charge_obj->source;

                $name = htmlspecialchars($customerinfo->name);
                $addressline1 = htmlspecialchars($customerinfo->address_line1);
                $addressline2 = empty($customerinfo->address_line2) ? '' : htmlspecialchars($customerinfo->address_line2);
                $city = htmlspecialchars($customerinfo->address_city);
                $state = htmlspecialchars($customerinfo->address_state);
                $zip = htmlspecialchars($customerinfo->address_zip);
                $country = htmlspecialchars($customerinfo->address_country);
            }

            try {
                // clear out address metadata as it is only for 1 time use
                if (!empty($subscription->metadata->shipping_name)) {
                    $subscription->metadata->shipping_name = NULL;
                    $subscription->metadata->address = NULL;
                    $subscription->metadata->suite = NULL;
                    $subscription->metadata->city = NULL;
                    $subscription->metadata->state = NULL;
                    $subscription->metadata->zip = NULL;
                    $subscription->metadata->country = NULL;

                    $subscription->save();
                }

                if (!empty($subscription->metadata->Pause_Status) && $subscription->metadata->Pause_Status == "Paused") {
                    // pause interval has ended if an invoice has been paid
                    $subscription->metadata->Pause_Status = NULL;
                    $subscription->save();
                }
            } catch (Exception $e) {
                // carry on
            }
        }



        try {
            inventoryUpdate($firstcountry, $invoiceplan);
        } catch (Exception $e) {
            //mail('adamraschig@gmail.com', 'inventory error', serialize($e));
        }

        $date = date("Y-m-d H:i:s");
        $verification = 'NO';
        $signature = '';

        $stmt = $directus->prepare("SELECT base_plan, drink, end_plan FROM plan_funnel WHERE (end_plan = :plan OR one_month_plan = :plan OR six_month_plan = :plan OR twelve_month_plan = :plan)");
        $stmt->bindParam(":plan", $invoiceplan);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_OBJ);

        if ($data) {
            $invoiceplan = $data->end_plan;
           // if ($data->drink == 1) $invoiceplan .= 'W';
        } else {
            switch ($invoiceplan) {
                case "4Snack2021":
                case "4SnackGift":
                case "4SnackGiftNew":
                case "4SnackGift2021":
                case "4Snack6New":
                case "4Snack6":
                case "4Snack12":
                case "4SnackCA":
                case "4SnackCA2021":
                case "4SnackCA6New":
                case "4SnackUK":
                case "4SnackUK2021":
                case "4SnackAU":
                case "4SnackNZ":
                case "4SnackIE":
                case "4SnackIE2021":
                case "4SnackZA":
                case "4SnackUK6New":
                case "4SnackNZ6New":
                case "4SnackMT":
                case "4SnackMT2021":
                case "4Snack0423":
                case "4Snack12mo":
                case "4Snack6mo":
                case "4Snack1mo":
                case "4Snack12moGift":
                case "4SnackUK1mo":
                case "4SnackUK12mo":
                case "4SnackUK6mo":
                case "4Snack2024":
                case "4Snack12mo2024TR":
                case "4SnackCA12mo":
                case "4Snack6mo2024TR":
                case "price_1Pl1jOHA97qUbSokopYBYxLV":
                case "price_1PYaiaHA97qUbSoko74cqP5r":
        
                    $invoiceplan = "4Snack";
                    break;

                case "4SnackW2021":
                case "4SnackWGift2021":
                case "4SnackW6New":
                case "4SnackW12":
                case "4SnackW6":
                case "4SnackWGift":
                case "4SnackWGiftNEW":
                case "4SnackWCA":
                case "4SnackWCA2021":
                case "4SnackWUK":
                case "4SnackWUK2021":
                case "4SnackWAU":
                case "4SnackWIE":
                case "4SnackWIE2021":
                case "4SnackWNZ":
                case "4SnackWMT":
                case "4SnackWMT2021":
                case "4SnackW0423":
                case "4SnackW6mo":
                case "4SnackW1mo":
                case "4SnackW12mo":
                case "4SnackWUK1mo":
                case "4SnackW12moGift":
                case "4SnackWUK12mo":
                case "4SnackWUK6mo":

                    $invoiceplan = "4SnackW";
                    break;

                case "4SnackSC":
        
                    $invoiceplan = "4SnackSC";
                    break;

                case "8Snack2021":
                case "8SnackGift2021":
                case "8Snack6New":
                case "8SnackGift":
                case "8SnackGiftNEW":
                case "8SnackCA6New":
                case "8SnackCA":
                case "8SnackCA2021":
                case "8SnackUK6New":
                case "8SnackUK":
                case "8SnackUK2021":
                case "8SnackNZ6New":
                case "8SnackNZ":
                case "8SnackAU6New":
                case "8SnackAU":
                case "8SnackZA6New":
                case "8SnackZA":
                case "8SnackIE":
                case "8SnackIE2021":
                case "8SnackIE6New":
                case "8Snack12":
                case "8SnackMT":
                case "8SnackMT2021":
                case "8SnackCA6mo":
                case "8SnackCA12mo":
                case "8Snack0423":
                case "8Snack1mo_2024":
                case "8Snack6mo_2024":
                case "8Snack12mo_2024":
                case "8Snack12mo2023":
                case "8Snack12moPriceTest":
                case "8Snack6mo2023":
                case "8Snack6moPriceTest":
                case "8Snack1mo2023":
                case "8Snack1moPriceTest":
                case "8Snack12mo_2024":
                case "8Snack6mo_2024":
                case "8Snack12mo":
                case "8Snack12mo_Spring2024":
                case "8Snack6mo":
                case "8Snack12mo_2024Gift":
                case "8Snack12mo_2024TRR":
                case "8Snack12mo_Spring2024TRR":
                case "8Snack12mo_SUM2024TRR":
                case "8Snack12moGift":
                case "8Snack1mo":
                case "8Snack1mo_SUM2024":
                case "8Snack1mo_SUM20241":
                case "8Snack3mo_2024Gift":
                case "8Snack6":
                case "8Snack6mo_2024TRR":
                case "8Snack6mo_SUM2024TRR":
                case "8SnackCA12mo2023":
                case "8SnackCA1mo2023":
                case "8SnackCA6mo2023":
                case "8SnackUK12mo":
                case "8SnackUK1mo":
                case "8SnackUK6mo":
                case "8Snack1mo_2024SUMGift":
                case "8Snack3mo_2024Gift":
                case "8Snack6mo_2024Gift":
                case "8Snack12mo_Spring2024Gift":
                case "8Snack1moTRRSUMGift":
                case "8Snack_3MO_TRGIFT":
                case "8Snack6moTRRGift":
                case "8Snack12moTRRSpring2024Gift":
                case "8Snack3mo_NewGift":
                case "8Snack3mo_NewGiftTRR":
                case "8SnackCold":
                case "price_1OnAYMHA97qUbSok0oC2PVrO":
                case "price_1Os8bwHA97qUbSoknResy3MM":
                case "price_1OWREyHA97qUbSokp0ciewgW":
                case "8Snack1mo_New":
                case "8Snack1mo_NewGift":
                case "8Snack1mo_NewTR":
                case "8Snack1mo_NewGiftTRR":


                    $invoiceplan = "8Snack";
                    break;


                case "8SnackW2021":
                case "8SnackWGift":
                case "8SnackWGiftNEW":
                case "8SnackWGift2021":
                case "8SnackWCA6New":
                case "8SnackWCA":
                case "8SnackWCA2021":
                case "8SnackWUK6New":
                case "8SnackWUK":
                case "8SnackWUK2021":
                case "8SnackWAU":
                case "8SnackWIE2021":
                case "8SnackWIE":
                case "8SnackWNZ":
                case "8SnackW12":
                case "8SnackW6New":
                case "8SnackWMT":
                case "8SnackWMT2021":
                case "8SnackWCA6mo":
                case "8SnackWCA12mo":
                case "8SnackW0423":
                case "8SnackW12mo2023":
                case "8SnackW6mo2023":
                case "8SnackW12moTest":
                case "8SnackW1mo2023":
                case "8SnackW6moTest":
                case "8SnackW12moPriceTest":
                case "8SnackW1moTest":
                case "8SnackW6moPriceTest":
                case "8SnackW12mo":
                case "8SnackWCA12mo2023":
                case "8SnackWCA1mo2023":
                case "8SnackWCA6mo2023":
                case "8SnackWUK12mo":
                case "8SnackWUK1mo":
                case "8SnackWUK6mo":
                case "8SnackW1mo":
                case "8SnackW6mo":
                case "8SnackWCold":

                    $invoiceplan = "8SnackW";
                    break;
                case "8SnackW1mo_NewGift":
                case "8SnackW3mo_NewGift":
                case "8SnackW6mo_NewGift":
                case "8SnackW12mo_May2024Gift":
                case "8SnackW1mo_NewGiftTRR":
                case "8SnackW3mo_NewGiftTRR":
                case "8SnackW6mo_NewGiftTRR":
                case "8SnackW12mo_NewGiftTRR":
                case "8SnackW1mo_2024":
                case "8SnackW6mo_2024":
                case "8SnackW12mo_2024":
                case "8SnackW6mo_24":
                case "8SnackW12Mo_24":
                case "8SnackW1mo_May2024SUMGift":
                case "8SnackW_3MO_GIFT":
                case "8SnackW6mo_May2024Gift":
                case "8SnackW1moSUM2024TRRGift":
                case "8SnackW_3MO_TRGIFT":
                case "8SnackW6moMay2024TRRGift":
                case "8SnackW12moTRRMay2024Gift":
                case "8SnackW12mo_2024TRR":
                case "8SnackW12Mo_May2024":
                case "8SnackW12mo_Spring2024Gift":
                case "8SnackW12Mo_Spring24":
                case "8SnackW12mo_SUM2024TRR":
                case "8SnackW1mo_May2024":
                case "8SnackW1mo_SUM2024":
                case "8SnackW6mo_2024TRR":
                case "8SnackW6mo_May2024":
                case "8SnackW6mo_SUM2024TRR":
                case "8SnackW1mo_New":
                case "8SnackW1mo_NewGift":
                case "8SnackW1mo_NewTR":
                case "8SnackW1mo_NewGiftTRR":
                    
        
        
        

                    $invoiceplan = "8SnackSC";
                    break;

                case "8SnackPlus":
                case "8SnackU12mo_2024":
                case "8SnackU1mo_2024":
                
                    $invoiceplan = "8SnackU";
                        break;
                    

                case "16SnackGift":
                case "16SnackGiftNEW":
                case "16SnackAU":
                case "16SnackCA":
                case "16SnackIE":
                case "16SnackNZ":
                case "16SnackUK":
                case "16Snack6New":
                case "16SnackMT":
                case "16SnackCA6mo":
                case "16SnackCA12mo":
                case "16Snack12mo2023":
                case "16Snack12moTest":
                case "16Snack6mo2023":
                case "16Snack12moPriceTest":
                case "16Snack6moTest":
                case "16Snack1moTest":
                case "16Snack12":
                case "16Snack12mo":
                case "16Snack12mo2024":
                case "16Snack12moGift":
                case "16Snack1mo":
                case "16Snack1mo2024":
                case "16Snack6":
                case "16Snack6mo":
                case "16Snack6mo2024":
                case "16SnackCA12mo2023":
                case "16SnackCA1mo2023":
                case "16SnackCA6mo2023":
                case "16SnackCold":                    
                    $invoiceplan = "16Snack";
                    break;

                case "16SnackWGift":
                case "16SnackWGiftNEW":
                case "16SnackWAU":
                case "16SnackWCA":
                case "16SnackWIE":
                case "16SnackWNZ":
                case "16SnackWUK":
                case "16SnackW6New":
                case "16SnackWMT":
                case "16SnackWCA6mo":
                case "16SnackWCA12mo":
                case "16SnackW0423":
                case "16SnackW0423b":
                case "16SnackW12mo":
                case "16SnackW12":
                case "16SnackW12m":
                case "16SnackW12mo2023":
                case "16SnackW12moTest":
                case "16SnackW6mo2023":
                case "16SnackW1moTest":
                case "16SnackW6moTest":
                case "16SnackW12moPriceTest":
                case "16SnackW12MO_ULTIMATE":
                case "16SnackW6MO_ULTIMATE":
                case "16SnackW1MO_ULTIMATE":
                case "16SnackW1mo_2024SUMGift":
                case "16SnackW_3MO_GIFT":
                case "16SnackW6mo_2024Gift":
                case "16SnackW12mo_Spring2024Gift":
                case "16SnackW1moTRRSUMGift":
                case "16SnackW_3MO_TR_GIFT":
                case "16SnackW6moTRRGift":
                case "16SnackW12moTRRSpring2024Gift":
                case "16SnackW12mo2024":
                case "16SnackW12mo2024TR":
                case "16SnackW12moGift":
                case "16SnackW12moTRRSUM2024":
                case "16SnackW12mo_2024Gift":
                case "16SnackW12MO_ULTIMATESpring2024":
                case "16SnackW1MO_ULTIMATELTESUM2024":
                case "16SnackW12MO_ULTIMATESpring202":
                case "16SnackW12MO_ULTIMATESUM":
                case "16SnackW1mo":
                case "16SnackW1mo2024":
                case "16SnackW1MO_ULTIMATESUM":
                case "16SnackW1MO_ULTIMATESUM2024":
                case "16SnackW3mo_NewGift":
                case "16SnackW3mo_NewGiftTRR":
                case "16SnackW6mo":
                case "16SnackW6mo2024":
                case "16SnackW6moSUMTRR":
                case "16SnackW6moTRR":
                case "16SnackW6MO_ULTIMATESUM":
                case "16SnackWCA12mo2023":
                case "16SnackWCA1mo2023":
                case "16SnackWCold":
                case "16SnackWUK12mo":
                case "16SnackWUK1mo":
                case "16SnackWUK6mo":
                case "price_1QKPamHA97qUbSokaCsKFIIS":
                case "price_1PBkaIHA97qUbSokwUUcfKWW":
                case "16SnackW1moN2024":
                case "16SnackW1mo_NewGift":
                case "16SnackW1moN2024TR":
                case "16SnackW1mo_NewGiftTRR":
                case "16SnackW1moN2024TR":

                    $invoiceplan = "16SnackW";
                    break;

                case "16SnackW1mo_2024":
                case "16SnackW6mo_2024":
                case "16SnackW12mo_2024":
                case "16SnackW12MO_24":
                case "16SnackW6MO_24":
                case "16SnackW1MO_24":

                    $invoiceplan = "8SnackU";
                    break;

                case "8Snack6NewCold":
                case "8SnackCold2021":
                    
                    $invoiceplan = "8SnackCold";
                    break;

                case "8SnackWCold2021":
                case "8SnackW6NewCold":
                   
                    $invoiceplan = "8SnackWCold";
                    break;

                case "Cadbury":
                    $invoiceplan = "8SnackW";
                    $firstcountry = "Cadbury";
                    break;

                case "CadburyCold":
                    $invoiceplan = "8Snack";
                    $firstcountry = "CadburyCold";
                    break;

                case "kitkat":
                    $invoiceplan = "4SnackW";
                    $firstcountry = "KitKat";
                    break;

                case "Holiday":
                    $invoiceplan = "8SnackW";
                    $firstcountry = "Holiday";
                    break;

                case "Halloween":
                case "Halloween1":
                    $invoiceplan = "8SnackW";
                    $firstcountry = "Halloween";
                    break;

                default:
                    $invoiceplan = $invoiceplan;
            }
        }

        $plan_to_insert = $invoiceplan;
        if (!empty($bundle_items)) {
            $plan_to_insert .= implode("", array_unique($bundle_items));

            $stmt = $dbh->prepare("UPDATE Subscriptions SET bundles = NULL WHERE subscription_id = :subscription_id");
            $stmt->bindParam(":subscription_id", $subscription_id);
            $stmt->execute();
            $stmt = null;
        }

        $sap_doc_num = null;
        $is_mystery = 0;
        // SAP SALES ORDER INTEGRATION
        //include($_SERVER['DOCUMENT_ROOT'].'/wp-content/themes/snackcrate/assets/includes/CreateReserveInvoiceSAP.php');
        ////

        $stmt = $dbh->prepare('SELECT * FROM `Users` WHERE `email`=:id');
        $stmt->bindParam(':id', $email);
        $stmt->execute();
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null;
        if ($item && $item['Flavor'] != "") {
            $flavor = $item['Flavor'];
        } else {
            $flavor = "Mix";
        }

        try {
            //check if in db
            $stmt = $dbh->prepare("SELECT id FROM Subscriptions WHERE subscription_id = :subscription_id");
            $stmt->bindParam(':subscription_id', $subscription_id);
            $stmt->execute();
            $db_sub_id = $stmt->fetch(PDO::FETCH_COLUMN);
            $stmt = null;

            //
            if (!$db_sub_id && $firstcountry == 'Current') {
                $first_name = trim(substr($name, 0, strpos($name, ' ')));
                $last_name = trim(substr($name, strpos($name, ' ')));
                $stmt = $dbh->prepare('INSERT INTO Subscriptions 
                (customer_id, subscription_id, is_active, first_name, last_name, shipping_name, address, suite, city, state, zip, country, plan, onboarded_with) 
                VALUES (:customer_id, :subscription_id, 1, :first_name, :last_name, :shipping_name, :address, :suite, :city, :state, :zip, :country, :plan, :onboarded_with)');
                $stmt->bindParam(':customer_id', $customer_id);
                $stmt->bindParam(':subscription_id', $subscription_id);
                $stmt->bindParam(':first_name', $first_name);
                $stmt->bindParam(':last_name', $last_name);
                $stmt->bindParam(':shipping_name', $name);
                $stmt->bindParam(':address', $addressline1);
                $stmt->bindParam(':suite', $addressline2);
                $stmt->bindParam(':city', $city);
                $stmt->bindParam(':state', $state);
                $stmt->bindParam(':zip', $zip);
                $stmt->bindParam(':country', $country);
                $stmt->bindParam(':plan', $invoiceplan);
                $onBoardedWith = __FILE__ . '.' . __LINE__;
                $stmt->bindParam(':onboarded_with', $onBoardedWith);
                $stmt->execute();
                $stmt = null;
            }
        } catch (Exception $e) {
            // don't break for this
        }

        $updatedFirstCountry = $firstcountry;
        if($is_birthday_month == true && $is_US_Citizen == true && strpos($plan_to_insert, "4Snack") === false && $is_awarded_birthday_gift == false){
            $updatedFirstCountry = $updatedFirstCountry . "||Birthday";

            //Update subscription table
            $stmt = $dbh->prepare('UPDATE Subscriptions SET birthday_gift_date=:current_date WHERE subscription_id=:subscription_id');
            $current_date = date('Y-m-d');
            $stmt->bindParam(':current_date', $current_date);
            $stmt->bindParam(':subscription_id', $subscription_id);
            $stmt->execute();
            $stmt = null;
        }

        //compose purchased
        //get item code from availablecountries by candybar_page_id
        $item_code = 36254;
        if(strpos($firstcountry, "||") !== false) {
            $baseCountry = explode("||", $firstcountry)[0];
            $promo_item = explode("||", $firstcountry)[1];
        }
        else {
            $baseCountry = $firstcountry;
            $promo_item = '';
        }
        if($baseCountry != 'Current'){
            $post = get_page_by_title( $baseCountry, OBJECT, 'countries-main' );
            $item_code = get_post_meta( $post->ID, 'general_candybar_id', true );

            $stmt2 = $dbh->prepare('SELECT promo_item_id FROM `Subscriptions` WHERE `subscription_id` = :subscription_id');
            $stmt2->bindParam(':subscription_id', $subscription_id);
            $stmt2->execute();
            $promo_item_id = $stmt2->fetch(PDO::FETCH_COLUMN);
            $stmt2 = null;

            if($promo_item_id != NULL && $promo_item_id != 0) {
                $directus = new PDO('mysql:dbname=snackcratedb;host=' . $_ENV['cr_db_host'] . ';charset=utf8', $_ENV['cr_db_username'], $_ENV['cr_db_password']);
                $directus->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $stmt = $directus->prepare("SELECT * FROM promotions WHERE id = :id");
                $stmt->bindParam(':id', $promo_item_id);
                $stmt->execute();
                $offer = $stmt->fetch(PDO::FETCH_OBJ);
                $stmt = null;
            }
        }       

        if($item_code == null || $item_code == ''){
            $item_code = 36254;
        }


        if(!empty($offer) && !empty($offer->candybar_item_id)){
            if(in_array($offer->candybar_item_id, [18199, 38268, 387316])) {
                // Override the Orders.First_Country value
                $updatedFirstCountry = $baseCountry;
                // Override the Orders.purchased value
                $purchased = serialize([
                    $item_code => [
                        $plan_to_insert => 1
                    ],
                ]);

                if(!empty($offer->crate_size)) {
                    $candybar_purchased = serialize([
                        $offer->candybar_item_id => [
                            $offer->crate_size => 1
                        ]
                    ]);
                } else {
                    $candybar_purchased = serialize([
                        $offer->candybar_item_id => 1
                    ]);
                }

                $getAddressIdStmt = $dbh->prepare("SELECT *
                    FROM `Address` 
                    WHERE address_1 = :address_1 AND
                        city = :city AND
                        zipcode = :zip AND
                        customer_id = :customer_id");
                $getAddressIdStmt->bindParam(":address_1", $addressline1);
                $getAddressIdStmt->bindParam(":city", $city);
                $getAddressIdStmt->bindParam(":zip", $zip);
                $getAddressIdStmt->bindParam(":customer_id", $customer_id);
                $getAddressIdStmt->execute();

                $add_address = $getAddressIdStmt->fetch(PDO::FETCH_ASSOC);
                $getAddressIdStmt = null;

                if(isset($add_address['id']) && !empty($add_address['id'])) {
                    $address_id = $add_address['id'];
                } else {
                    // Create an address for candybar_orders.address_id              
                    $stmt2 = $dbh->prepare("INSERT INTO `Address`
                                    (shipping_name, address_1, address_2, city, state, country, zipcode, customer_id, is_default)
                                    VALUES (:shipping_name, :address_1, :address_2, :city, :state, :country, :zipcode, :customer_id, 1)");
                    $stmt2->bindParam(":shipping_name", $name);
                    $stmt2->bindParam(":address_1", $addressline1);
                    $stmt2->bindParam(":address_2", $addressline2);
                    $stmt2->bindParam(":city", $city);
                    $stmt2->bindParam(":state", $state);
                    $stmt2->bindParam(":country", $country);
                    $stmt2->bindParam(":zipcode", $zip);
                    $stmt2->bindParam(":customer_id", $customer_id);
                    $stmt2->execute();

                    $address_id = $dbh->lastInsertId();
                    $stmt2 = null;
                }

                // Create the candybar_order record
                $in_main_table = 0;
		        $is_addon = 1;
		        $hidden = 1;
                             
                $stmt = $dbh->prepare('SELECT * FROM `Users` WHERE `customer_ID` = :customer_id');
                $stmt->bindParam(':customer_id', $customer_id);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt = null;
        
                if (strpos($invoiceid, 'in_') === 0) {
                    $payment_id = 'free_' . substr($invoiceid, 3);
                } 
                $stmt = $dbh->prepare('INSERT INTO `candybar_order` (id, `user_id`, purchased, cost, tax, is_guest, shipping_address, status, payment_id, in_main_table, preorder_date, is_addon, hidden) 
                VALUES (NULL, :user_id, :purchased, 0, 0, 0, :shipping_address, "processing", :payment_id, :in_main_table, :preorder_date, :is_addon, :hidden)');
                $stmt->bindParam(':user_id', $user['id']);
                $stmt->bindParam(':purchased', $candybar_purchased);
                $stmt->bindParam(':shipping_address', $address_id);
                $stmt->bindParam(':payment_id', $payment_id);
                $stmt->bindValue(':preorder_date', $offer->preorder_date);
                $stmt->bindValue(':in_main_table', $in_main_table);
        		$stmt->bindValue(':is_addon', $is_addon);
        		$stmt->bindValue(':hidden', $hidden);
                $stmt->execute();

                $candybar_order_id = $dbh->lastInsertId();
                $stmt = null;
            } elseif (!empty($offer->crate_size)) {
                $purchased = serialize(array(
                    $item_code => array(
                        $plan_to_insert => 1
                    ),
                    $offer->candybar_item_id => array(
                        $offer->crate_size => 1
                    )
                ));
            } else {
                $purchased = serialize(array(
                    $item_code => array(
                        $plan_to_insert => 1
                    ),
                    $offer->candybar_item_id => 1
                ));
            }
        }
        else {
            $purchased = serialize( array(
                $item_code => array(
                    $plan_to_insert => 1
                )
            ) );
        }

        if(!empty($offer) && $offer->offer_code == 'doublefirstbox') {
            $purchased = serialize( array(
                25637 => array(
                    $plan_to_insert => 1
                )
            ) );
            $purchased_doublefirstbox = serialize( array(
                389771 => array(
                    $plan_to_insert => 1
                )
            ) );
        }
        
        $stmt = $dbh->prepare('INSERT INTO `Orders` (`Email`, `Shipping_Name`, `Plan`, `Address`, `suite`, `City`, `State`, `Zip`, `Country`, `First_Country`, `customer_ID`, `Order_Date`, `Address_Verified`, `Signature`, `Payment_ID`, `SAP_DocNum`, `is_mystery`, `purchased`)VALUES (:Email, :Shipping_Name, :Plan, :Address, :suite, :City, :State, :Zip, :Country, :First_Country, :customer_ID, :Order_Date, :Address_Verified, :Signature, :Payment_ID, :Sap_DocNum, :is_mystery, :purchased)');
        $stmt->bindParam(':Email', $email);
        $stmt->bindParam(':Shipping_Name', $name);
        $stmt->bindParam(':Plan', $plan_to_insert);
        $stmt->bindParam(':Address', $addressline1);
        $stmt->bindParam(':suite', $addressline2);
        $stmt->bindParam(':City', $city);
        $stmt->bindParam(':State', $state);
        $stmt->bindParam(':Zip', $zip);
        $stmt->bindParam(':Country', $country);
        $stmt->bindParam(':First_Country', $updatedFirstCountry);
        $stmt->bindParam(':customer_ID', $customer_id);
        $stmt->bindParam(':Order_Date', $date);
        $stmt->bindParam(':Address_Verified', $verification);
        $stmt->bindParam(':Signature', $signature);
        $stmt->bindParam(':Payment_ID', $invoiceid);
        $stmt->bindParam(':Sap_DocNum', $sap_doc_num);
        $stmt->bindParam(':is_mystery', $is_mystery);
        $stmt->bindParam(':purchased', $purchased);
        //$stmt->bindParam(':flavor', $flavor);
        $stmt->execute();
        $lastid = $dbh->lastInsertId();
        $stmt = null;

        if(!empty($offer) && $offer->offer_code == 'doublefirstbox') {
            if (strpos($invoiceid, 'in_') === 0) {
                $second_crate_invoice_id = 'free_' . substr($invoiceid, 3);
            } else {
                $second_crate_invoice_id = $invoiceid;
            }

            $doubleOrderCountry = 'Taiwan';

            $stmt = $dbh->prepare('INSERT INTO `Orders` (`Email`, `Shipping_Name`, `Plan`, `Address`, `suite`, `City`, `State`, `Zip`, `Country`, `First_Country`, `customer_ID`, `Order_Date`, `Address_Verified`, `Signature`, `Payment_ID`, `SAP_DocNum`, `is_mystery`, `purchased`)VALUES (:Email, :Shipping_Name, :Plan, :Address, :suite, :City, :State, :Zip, :Country, :First_Country, :customer_ID, :Order_Date, :Address_Verified, :Signature, :Payment_ID, :Sap_DocNum, :is_mystery, :purchased)');
            $stmt->bindParam(':Email', $email);
            $stmt->bindParam(':Shipping_Name', $name);
            $stmt->bindParam(':Plan', $plan_to_insert);
            $stmt->bindParam(':Address', $addressline1);
            $stmt->bindParam(':suite', $addressline2);
            $stmt->bindParam(':City', $city);
            $stmt->bindParam(':State', $state);
            $stmt->bindParam(':Zip', $zip);
            $stmt->bindParam(':Country', $country);
            $stmt->bindParam(':First_Country', $doubleOrderCountry);
            $stmt->bindParam(':customer_ID', $customer_id);
            $stmt->bindParam(':Order_Date', $date);
            $stmt->bindParam(':Address_Verified', $verification);
            $stmt->bindParam(':Signature', $signature);
            $stmt->bindParam(':Payment_ID', $second_crate_invoice_id);
            $stmt->bindParam(':Sap_DocNum', $sap_doc_num);
            $stmt->bindParam(':is_mystery', $is_mystery);
            $stmt->bindParam(':purchased', $purchased_doublefirstbox);
            //$stmt->bindParam(':flavor', $flavor);
            $stmt->execute();
            $lastid = $dbh->lastInsertId();
            $stmt = null;
        }
        
        $stmt = $directus->prepare("SELECT pretty_name FROM plans WHERE plan_id = :plan_id");
        $stmt->bindParam(":plan_id", $invoiceplan);
        $stmt->execute();
        $plan_pretty_name = $stmt->fetch(PDO::FETCH_COLUMN);
        $stmt = null;
        $plan_pretty_name = str_ireplace("Snackcrate", "", $plan_pretty_name);
        $plan_pretty_name = str_ireplace("/w Drink Upgrade", "w/ Drink", $plan_pretty_name);

        if ($firstcountry == "Current") {
            $status = "Renewed";
            $klaviyo_source = "Subscription Renewal";
        } else {
            $status = "Received";
            $klaviyo_source = "Subscription Purchase";
        }

        try {
            if (!empty($giftInfo)) {
                $klaviyo_source = "Gift Invoice";
            }

            if ($status == "Renewed") {
                $subscription = \Stripe\Subscription::retrieve(
                    $subscription_id,
                    array(
                        'stripe_version' => '2020-08-27'
                    )
                );

                if (empty($subscription->metadata["ordered_current_month"]) || $subscription->metadata["ordered_current_month"] == 0) {
                    $month_year = date('m-Y');
                    $stmt = $dbh->prepare("SELECT country_name FROM sap_monthly WHERE month_year = :month_year");
                    $stmt->bindParam(":month_year", $month_year);
                    $stmt->execute();
                    $last_country = $stmt->fetch(PDO::FETCH_COLUMN);

                    SCKlaviyoHelper::getInstance()->sendEvent(
                        'Subscription Renewal',
                        $email,
                        array(
                            '$first_name' => trim(substr($name, 0, strpos($name, ' '))),
                            '$last_name' => trim(substr($name, strpos($name, ' '))),
                            '$city' => $city,
                            '$region' => $state,
                            '$country' => $country,
                            '$zip' => $zip
                        ),
                        array(
                            '$value' => number_format(($invoice->total - $invoice->tax) / 100, 2),
                            'Subscription ID' => $subscription_id,
                            'Order Reference' => $invoiceid,
                            'Plan' => $invoiceplan,
                            'Term' => 'Monthly',
                            'Last Country' => $last_country,
                            'Drink Ugrade' => substr($invoiceplan, -1) == 'W' ? 1 : 0,
                            'Last Renewal' => date('m/d/Y')
                        )
                    );
                }
            }
        } catch (Exception $e) {
            // continue;
        }

        $customerobject->metadata['Status'] = $status;
        $customerobject->save();

        http_response_code(200);
        echo 'Order Placed';

        $ticket_quantity = 0;
        //>ADVENTURE TICKET STUFF
        if (strpos(strtolower($invoiceplan), '8snack') !== false) {
            $ticket_quantity = 1;
        } else if (strpos(strtolower($invoiceplan), '16snack') !== false) {
            $ticket_quantity = 3;
        } else if (strpos(strtolower($invoiceplan), 'big_f') !== false || strpos(strtolower($invoiceplan), 'big f') !== false) {
            $ticket_quantity = 5;
        }

        if ($ticket_quantity > 0) {
            $stmt = $dbh->prepare('INSERT INTO `AdventureTicket` (`Name`,`Address`,`Suite`,`City`,`State`,`Zipcode`,`Email`,`CustomerID`,`OrderNumber`,`TicketQuantity`) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute(array($name, $addressline1, $addressline2, $city, $state, $zip, $email, $customer_id, $invoiceid, $ticket_quantity));
            $stmt = null;
        }

        $stmt = $dbh->prepare('SELECT * FROM AvailableCountries');
        $stmt->execute();
        $signupCountries = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $signupCountries[$row['Country']] = $row;
        }
        $stmt = null;

        $count5WD = 0;
        $temp = time();
        $ShippingDate = 4;

        while ($count5WD < $ShippingDate) {
            $next1WD = strtotime('+1 weekday', $temp);
            $next1WDDate = date('Y-m-d', $next1WD);
            $temp = $next1WD;
            $count5WD++;
        }

        $next5WD = date("m-d-y", $temp);
        if ($firstcountry == "Halloween") {
            $next5WD = "10-21-21";
        }
        if ($firstcountry == "Holiday") {
            $next5WD = "12-16-20";
        }

        $firstcountry_modified = $firstcountry == 'United Kingdom' ? 'England' : $firstcountry;
        if (isset($signupCountries[$firstcountry_modified]) && $signupCountries[$firstcountry_modified]['fixed_delivery_date'] != "" && !empty($signupCountries[$firstcountry_modified]['fixed_delivery_date'])) {

            // Assuming the input is in YYYY-MM-DD format
            $date_parts = explode('-', $signupCountries[$firstcountry_modified]['fixed_delivery_date']);
            $next5WD = $date_parts[1] . '-' . $date_parts[2] . '-' . substr($date_parts[0], -2);
        }

        //>insert into order history table.

        $stmt = $dbh->prepare('INSERT INTO `OrderHistory` (`CustomerID`, `OrderDate`, `Status`, `Price`, `estdeliverydate`, `trackingcode`, `email`, `Shipping_Name`, `Plan`, `Address`, `suite`, `City`, `State`, `Zip`, `Country`,`First_Country`,`Order_Date`,`Address_Verified`,`Signature`,`Payment_ID`,`subscription_id`,`Sap_DocNum`,`is_mystery`, `amount_paid`, `purchased`, `sales_order_reference`)VALUES (:CustomerID, :OrderDate, :Status, :Price, :estdeliverydate, "", :email, :Shipping_Name, :Plan, :Address, :suite, :City, :State, :Zip, :Country, :First_Country, :Order_Date, :Address_Verified, :Signature, :Payment_ID, :subscription_id, :Sap_DocNum, :is_mystery, :amount_paid, :purchased, "")');
        $stmt->bindParam(':CustomerID', $customer_id);
        $stmt->bindParam(':OrderDate', $date);
        $stmt->bindParam(':Status', $status);
        $stmt->bindParam(':Price', $total);
        $stmt->bindParam(':estdeliverydate', $next5WD);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':Shipping_Name', $name);
        $stmt->bindParam(':Plan', $plan_to_insert);
        $stmt->bindParam(':Address', $addressline1);
        $stmt->bindParam(':suite', $addressline2);
        $stmt->bindParam(':City', $city);
        $stmt->bindParam(':State', $state);
        $stmt->bindParam(':Zip', $zip);
        $stmt->bindParam(':Country', $country);
        $stmt->bindParam(':First_Country', $firstcountry);
        $stmt->bindParam(':Order_Date', $date);
        $stmt->bindParam(':Address_Verified', $verification);
        $stmt->bindParam(':Signature', $signature);
        $stmt->bindParam(':Payment_ID', $invoiceid);
        $stmt->bindParam(':subscription_id', $subscription_id);
        $stmt->bindParam(':Sap_DocNum', $sap_doc_num);
        $stmt->bindParam(':is_mystery', $is_mystery);
        $stmt->bindParam(':amount_paid', $amount_paid);
        $stmt->bindParam(':purchased', $purchased);
        // $stmt->bindParam(':sales_order_reference', $sales_order_reference);
        //$stmt->bindParam(':flavor', $flavor);
        $stmt->execute();
        $order_number = $dbh->lastInsertId();

        $purchased_items = unserialize($purchased);

        $inventree = new Inventree();

        //create sales_order_reference
        $time = date('His');
        $sales_order_reference = 'SO-0' . $order_number . $time;

        $customer = $inventree->getCompany($name, $email);

        if(empty($customer)) {
            //Create Customer on tree
            $customer_data = array(
                'name' => $name,
                'description' => $customer_id,
                'website' => '',
                'phone' => '',
                'address' => null,
                'email' => $email,
                'currency' => 'USD',
                'contact' => '',
                'link' => '',
                'image' => null,
                'active' => true,
                'is_customer' => true,
                'is_manufacturer' => false,
                'is_supplier' => false,
                'notes' => null,
                'address_count' => 0,
                'primary_address' => null
            );

            $customer = $inventree->createCompany($customer_data);
        }

        //Create Sales Order     
        $target_date = ($baseCountry == 'Current') ? date("Y-m-t") : date('Y-m-d');

        $sales_order_data = array(
            'creation_date' => date('Y-m-d'),
            'target_date' => $target_date,
            'description' => '',
            'line_items' => 0,
            'completed_lines' => 0,
            'link' => '',
            'project_code' => null,
            'project_code_detail' => null,
            'responsible' => null,
            'responsible_detail' => null,
            'contact' => null,
            'contact_detail' => null,
            'address' => null,
            'address_detail' => null,
            'status' => 10,
            'status_text' => 'Pending',
            'notes' => null,
            'barcode_hash' => '',
            'overdue' => false,
            'customer' => $customer->pk,
            'customer_reference' => '',
            'shipment_date' => null,
            'total_price' => '0',
            'order_currency' => 'USD',
            'reference' => $sales_order_reference
        );
        $sales_order = $inventree -> createSalesOrder($sales_order_data);
        $sales_order_id = !empty($sales_order) ? $sales_order->pk : '';

        foreach($purchased_items as $item => $size) {
            //get post_type
            $stmt = $dbcb->prepare("SELECT post_type FROM wp_posts WHERE ID = :post_id");
            $stmt->bindParam(':post_id', $item);
            $stmt->execute();
            $post_type = $stmt->fetch(PDO::FETCH_COLUMN);
            $stmt = null;

            $stmt = $dbcb->prepare("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id = :post_id AND (meta_key LIKE 'internal-id-code%' OR meta_key IN ('stock', 'cost', 'member-price'))");
            $stmt->bindParam(':post_id', $item);
            $stmt->execute();
            $metaData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $stmt = null;

            if(isset($metaData['stock']) && !empty($metaData['stock'])) {
                //Reduce the stock number by 1
                $stock = $metaData['stock'];
                $new_stock = $stock - 1;

                $stmt = $dbcb->prepare("UPDATE wp_postmeta SET meta_value = :stock_value WHERE post_id = :post_id and meta_key='stock'");
                $stmt->bindParam(':post_id', $item);
                $stmt->bindParam(':stock_value', $new_stock);
                $stmt->execute();
                $stmt = null;
            }
            
            $internal_id_code = '';

            if($post_type == 'country' && is_array($size)) {
                $item_size = key($size);
                $internal_id_code_key = "internal-id-code_" .$item_size;

                if(isset($metaData[$internal_id_code_key])) {
                    $internal_id_code = $metaData[$internal_id_code_key];
                } else {
                    $internal_id_code_others = isset($metaData['internal-id-code_Others']) ? unserialize($metaData['internal-id-code_Others']) : array();
                    if(!empty($internal_id_code_others)) {
                        $filtered_code_items = array_filter($internal_id_code_others, function($code_item) use ($item_size) {
                            return $code_item['size'] === $item_size;
                        });

                        $internal_id_code = !empty($filtered_code_items) ? array_values($filtered_code_items)[0]['code'] : '';
                    }
                }

                if($internal_id_code == '') {
                    $message_to_slack = "We can't get internal-id-code for country item.\nInvoicePlan - $invoiceplan\nFirstCountry - $baseCountry\nPaymentID - $invoiceid\nSalesOrderReference - $sales_order_reference";
                    slack($message_to_slack, '#inventree_report');
                }
            } 
            else {
                $internal_id_code = isset($metaData['internal-id-code']) ? $metaData['internal-id-code'] : '';
                if($internal_id_code == '') {
                    $message_to_slack = "We can't get internal-id-code for promo item.\nPromoItemId - $promo_item_id\nPostId - $item\nPaymentID - $invoiceid\nSalesOrderReference - $sales_order_reference";
                    slack($message_to_slack, '#inventree_report');
                } 
            }


            if($internal_id_code != '') {
                //get price of item
                $priceKey = ($post_type == 'country') ? 'cost' : 'member-price';
                $price = isset($metaData[$priceKey]) ? $metaData[$priceKey] : 0;

                //get inventree_part
                $inventree_part = $inventree -> getInventreePartByIPN($internal_id_code); 
                if($inventree_part != NULL) {

                    //add items to sales order
                    $part_id = $inventree_part->pk;
                    $line_item_data = array(
                        'quantity' => 1,
                        'reference' => '',
                        'notes' => '',
                        'overdue' => false,
                        'part' => $part_id,
                        'order' => $sales_order_id,
                        'sale_price' => $price,
                        'sale_price_currency' => 'USD',
                        'target_date' => $target_date,
                        'link' => ''
                    );
                    
                    $line_item = $inventree -> addLineItemToSalesOrder($line_item_data);
                } 
            }
            
        }

        if($sales_order_id != '') {
            $inventree->issueOrder($sales_order_id);
        }

        //update sales_order_reference field
        $stmt = $dbh->prepare("UPDATE OrderHistory SET sales_order_reference = :sales_order_reference WHERE OrderNumber = :order_number");
        $stmt->bindParam(":sales_order_reference", $sales_order_reference);
        $stmt->bindParam(":order_number", $order_number);
        $stmt->execute();
        $stmt = null;

        if ($tax > 0 && $customerobject->deleted != 1 && $county) {
            $tax = $tax * .01;
            //$county = $customerobject->metadata->Tax_County;

            $stmt = $dbh->prepare('INSERT INTO SalesTax (`county`) VALUES (:county) ON DUPLICATE KEY UPDATE `county` = `county`');
            $stmt->bindParam(':county', $county);
            $stmt->execute();
            $stmt = null;

            $stmt = $dbh->prepare('UPDATE SalesTax SET TaxesPaid=TaxesPaid +:tax WHERE county=:county');
            $stmt->bindParam(':county', $county);
            $stmt->bindParam(':tax', $tax);
            $stmt->execute();
            $stmt = null;
        }

        $stmt = $dbh->prepare('UPDATE `UserActions` SET var2 = "end" WHERE `id` = :id');
        $stmt->bindParam(':id', $db_id);
        $stmt->execute();
        $stmt = null;

        $stmt = $dbh->prepare('UPDATE `UserActions` SET `customer_id` = :customer_id, `var3` = :email WHERE `id` = :id');
        $stmt->bindParam(':customer_id', $customer_id);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':id', $db_id);
        $stmt->execute();
        $stmt = null;

        $stmt = $dbh->prepare('SELECT * FROM Subscriptions where subscription_id = :subscription_id');
        $stmt->bindParam(":subscription_id", $subscription_id);
        $stmt->execute();
        $subscription_row = $stmt->fetch(PDO::FETCH_OBJ);
        $stmt = null;

        include('ProcessAddons.php');

        if (!$added_current_month) {
            // check if multimonth user has canceled
            // update months_left
            $new_months_left = $subscription_row->months_left - 1;
            $stmt = $dbh->prepare("UPDATE Subscriptions SET months_left = :new_months_left WHERE subscription_id = :subscription_id");
            $stmt->bindParam(":subscription_id", $subscription_id);
            $stmt->bindParam(":new_months_left", $new_months_left);
            $stmt->execute();
            $stmt = null;

            if (!empty($subscription_row->canceled_at) && $new_months_left == 1) {
                SCKlaviyoHelper::getInstance()->sendEvent(
                    'Subscription About to End',
                    $email,
                    array(),
                    array()
                );
            }
            if (!empty($subscription_row->canceled_at) && $new_months_left == 0) {
                //Remove Subscription
                $subscription = \Stripe\Subscription::retrieve(
                    $subscription_id,
                    array(
                        'stripe_version' => '2020-08-27'
                    )
                );
                $subscription->cancel();

                $stmt = $dbh->prepare("UPDATE Subscriptions SET is_active = 0 WHERE subscription_id = :subscription_id");
                $stmt->bindParam(":subscription_id", $subscription_id);
                $stmt->execute();
                $stmt = null;

                $stmt = $dbh->prepare("SELECT is_active, GROUP_CONCAT(plan) as plans, GROUP_CONCAT(subscription_id) as subscription_ids
                FROM Subscriptions
                WHERE customer_id = :customer_id
                GROUP BY is_active");
                $stmt->bindParam(":customer_id", $customer_id);
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_OBJ);
                $stmt = null;

                $klaviyo_inactive_sub_ids = '';
                $klaviyo_inactive_sub_plans = '';
                $klaviyo_active_sub_ids = '';
                $klaviyo_active_sub_plans = '';

                foreach ($result as $r) {
                    if ($r->is_active == 0) {
                        $klaviyo_inactive_sub_ids = $r->subscription_ids;
                        $klaviyo_inactive_sub_plans = $r->plans;
                    } elseif ($r->is_active == 1) {
                        $klaviyo_active_sub_ids = $r->subscription_ids;
                        $klaviyo_active_sub_plans = $r->plans;
                    }
                }

                SCKlaviyoHelper::getInstance()->sendEvent(
                    'Subscription Canceled',
                    $email,
                    array(
                        'Inactive Subscription ID(s)' => $klaviyo_inactive_sub_ids,
                        'Inactive Plan(s)' => $klaviyo_inactive_sub_plans,
                        'Active Subscription ID(s)' => $klaviyo_active_sub_ids,
                        'Active Plan(s)' => $klaviyo_active_sub_plans,
                        'Is Active Subscriber' => empty($klaviyo_active_sub_ids) ? 0 : 1,
                        'Drink Upgrade' => strpos($klaviyo_active_sub_plans, 'W') === false ? 0 : 1,
                    ),
                    array(
                        'Cancellation' => $subscription_row->cancel_reason,
                        'crateSize' => $subscription_row->plan
                    )
                );
            } elseif (!empty($subscription_row->update_to) && $new_months_left == 0) {
                $stmt = $directus->prepare('SELECT * FROM plan_funnel WHERE end_plan = :plan OR one_month_plan = :plan OR six_month_plan = :plan OR twelve_month_plan = :plan LIMIT 1');
                $stmt->bindParam(":plan", $subscription_row->update_to);
                $stmt->execute();

                $new_plan = $stmt->fetch(PDO::FETCH_OBJ);
                $stmt = null;

                if ($new_plan) {
                    switch ($subscription_row->update_to) {
                        case $new_plan->end_plan:
                        case $new_plan->one_month_plan:
                            $new_term = 1;
                            break;
                        case $new_plan->six_month_plan:
                            $new_term = 6;
                            break;
                        case $new_plan->twelve_month_plan:
                            $new_term = 12;
                            break;
                    }

                    $new_base_plan = $new_plan->base_plan;

                    if ($new_plan->drink == 1) $new_base_plan .= 'W';

                    $stmt = $dbh->prepare("UPDATE Subscriptions SET plan = :new_plan, term = :term, update_to = NULL WHERE subscription_id = :subscription_id");
                    $stmt->bindParam(":subscription_id", $subscription_id);
                    $stmt->bindParam(":new_plan", $new_base_plan);
                    $stmt->bindParam(":term", $new_term);
                    $stmt->execute();
                    $stmt = null;

                    //Update Stripe Subscription
                    $subscription = \Stripe\Subscription::retrieve(
                        $subscription_id,
                        array(
                            'stripe_version' => '2020-08-27'
                        )
                    );
                    //create new subscription item
                    \Stripe\SubscriptionItem::create([
                        'subscription' => $subscription_id,
                        'price' => $subscription_row->update_to,
                        'quantity' => 1,
                        "prorate" => false,
                    ]);

                    $si_to_delete = \Stripe\SubscriptionItem::retrieve($stripe_subscription_item_id);
                    $si_to_delete->delete(['proration_behavior' => 'none']);
                }
            } elseif ($new_months_left < 0) {
                $new_term = $subscription_row->term - 1;
                $stmt = $dbh->prepare("UPDATE Subscriptions SET months_left = :term WHERE subscription_id = :subscription_id");
                $stmt->bindParam(":term", $new_term);
                $stmt->bindParam(":subscription_id", $subscription_id);
                $stmt->execute();
                $stmt = null;
            }
        }


        /*
        if($db_customer && $months_left <= 0)
        {
            if($db_customer['nextplan'] == "cancelled")
            {
                $stmt = $dbh->prepare('UPDATE `customers` SET currentplanstartdate = NULL, disabled="YES" WHERE id=:id');
                $stmt->bindParam(':id', $customer_id);
                $stmt->execute();
                $stmt = null;

                //Change Pause Status
                $customerobject->metadata{'Pause_Status'} = "Cancelled";

                //Zero Credit
                $customerobject->account_balance = 0;
                $customerobject->save();

                //Remove Subscription
                if(count($customerobject->subscriptions->data) > 0)
                {
                    $test = $customerobject->subscriptions->data[0]->id;
                    $subscription = $customerobject->subscriptions->retrieve($test);
                    $subscription->cancel();
                }

                $stmt = $dbh->prepare('INSERT INTO `UserActions` (`type`,`customer_id`)VALUES ("Subscription Ended",:id)');
                $stmt->bindParam(':id', $customer_id);
                $stmt->execute();
                $stmt = null;
            }
        }
        */
    } catch (Exception $e) {

        $error_message = $e->getMessage();
        http_response_code(500);
        echo $e->getMessage() . "\n";
        print_r($e->getTrace());

        if (empty($invoice)) {
            $invoice_id = $event_json->id;
        } else {
            $invoice_id = $invoice->id;
        }

        $stmt = $dbh->prepare("INSERT INTO webhook_errors (webhook, object_id, error_message) VALUES ('new_order', :invoice_id, :error_message)");
        $stmt->bindParam(':invoice_id', $invoice_id);
        $stmt->bindParam(':error_message', $error_message);
        $stmt->execute();
        $stmt = null;

        exit(1);

    }

} catch (Throwable $e) {

    //ErrorCatcher::generateLog($e);

}

?>