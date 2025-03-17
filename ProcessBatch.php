<?php
$date = date('m/d/Y h:i:s a', time());
error_log("{$date} - Begin ProcessBatch.php");
use Stripe\Stripe;
use Stripe\StripeClient;

require_once("/var/www/env.php");
ini_set('display_startup_errors', 0);
ini_set('display_errors', 0);
ini_set('error_log', '/var/log/www-error.log'); 

set_time_limit(0);
ignore_user_abort(1);

require_once("klaviyo/klaviyo-helper-new.php" );
require_once("inventree.php");
require_once("easypost/easypost-php/lib/easypost.php");
\EasyPost\EasyPost::setApiKey('URWVm1qFfJ0Z733uEl5kiw');

$servername = getenv('cr_db_host');
$username = getenv('cr_db_username');
$password = getenv('cr_db_password');
$dbname = getenv('cr_db_dbname');

$dbh = new PDO('mysql:dbname='.$dbname.';host=' . $servername . ';charset=utf8', $username, $password);
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//require_once('Stripe.php');
require_once('stripe/stripe-php/init.php');

Stripe::setApiKey(getenv('stripe_api_key'));
$stripe = new StripeClient(getenv('stripe_api_key'));

try
{
    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    $inventree = new Inventree();

    $sql = "SELECT * FROM BatchForProcessing ORDER BY created_at LIMIT 1";
    $stmt_batches = $dbh->prepare($sql);
    $stmt_batches->execute();

    //$sql = "SELECT COUNT(*) FROM BatchForProcessing";
    $result = $conn->query($sql) or die($conn->error);
    $num_rows = $result->num_rows;

    //	echo "number of rows: {$num_rows}\n";
    error_log("{$date} - number of rows: {$num_rows}"); 
    $batch_ids = array();
    $producers = array();
	//while($row = $result->fetch_assoc()) {
    while($row = $stmt_batches->fetch(PDO::FETCH_ASSOC))
    {
        array_push($batch_ids, $row["Batch_ID"]);
        array_push($producers, $row["Producer"]);

        $id = $row["Batch_ID"];
        $sql = "DELETE FROM BatchForProcessing WHERE Batch_ID = '$id'";

        $result = $conn->query($sql) or die($conn->error);

    }

    $msg1 = print_r($$batch_ids, true);
    $date = date('Y-m-d H:i:s');
    $error_file = "easypost/easypost_errors.log";
    $message = "{$date} batch_ids in for loop of ProcessBatch.php: {$msg1}\n";
    file_put_contents($error_file, $message, FILE_APPEND);

    foreach($batch_ids as $j => $id)
    {
        $producer = $producers[$j];
        
        $batch = \EasyPost\Batch::retrieve($id);
        
        $msg1 = print_r($batch, true);
        $date = date('Y-m-d H:i:s');
        $error_file = "easypost/easypost_errors.log";
        $message = "{$date} batch in for loop of ProcessBatch.php:batch id - {$id} -- {$msg1}\n";
        file_put_contents($error_file, $message, FILE_APPEND);

        $numberofshipments = $batch->num_shipments;
        $numberofshipments = (int)$numberofshipments;
        echo "number of shipments: {$numberofshipments}\n";
     
        for($i=0;$i<$numberofshipments;$i++)
        {
            try
            {
                $shipmentID = $batch->shipments[$i]->id;
                
                $shipment = \EasyPost\Shipment::retrieve($shipmentID);

                //log
                $msg1 = print_r($shipment, true);
                $date = date('Y-m-d H:i:s');
                $error_file = "easypost/easypost_errors.log";
                $message = "{$date} Error1 in for loop of ProcessBatch.php: {$msg1}\n";
                file_put_contents($error_file, $message, FILE_APPEND);
                $msg2 = var_export($shipment, true);
                $message = "{$date} Error2 in for loop of ProcessBatch.php: {$msg2}\n";
                file_put_contents($error_file, $message, FILE_APPEND);
                
                $customer_id = $shipment->to_address->email;
                
                if($customer_id == ""){
                    mail("help@snackcrate.com","Tracking Error",$shipmentID.'<br />'.$batch->shipments[$i]->reference);
                    continue;
                }
                
                $customer_id = substr($customer_id, 0, strpos($customer_id, "@"));
                
                $trackingnumber = $shipment->tracking_code;
                
                $tracker = $shipment->tracker->id;
                
                $trackingurl = $shipment->tracker->public_url;

                
                $customerreference = $batch->shipments[$i]->reference;
                
                $email = $shipment->to_address->email;
                $barcode = '';
                if(strtolower($customer_id) != "noid"){
                    $customer_id_lower = strtolower($customer_id);
                    $stmt = $dbh->prepare("SELECT id FROM customers WHERE LOWER(id) = :customer_ID");
                    $stmt->bindParam(':customer_ID', $customer_id_lower);
                    $stmt->execute();
                    $customer_id = $stmt->fetch(PDO::FETCH_COLUMN);
                    $stmt = null;

                    $stmt = $dbh->prepare("SELECT email, barcode FROM OrdersBatch WHERE Payment_ID = :payment_id");
                    $stmt->bindParam(":payment_id", $customerreference);
                    $stmt->execute();
                    $data = $stmt->fetch(PDO::FETCH_OBJ);
                    $email = $data->email;
                    $barcode = $data->barcode;
                    $stmt = null;

                    if(!$email)
                    {
                        $stmt = $dbh->prepare("SELECT email FROM OrderHistory WHERE Payment_ID = :payment_id");
                        $stmt->bindParam(":payment_id", $customerreference);
                        $stmt->execute();

                        $email = $stmt->fetch(PDO::FETCH_COLUMN);
                        $stmt = null;
                    }

                    if($customer_id)
                    {
                        $cu = $stripe->customers->retrieve($customer_id);
                        //$cu = Stripe_Customer::retrieve($customer_id);
                        $cu->metadata['Shipping_URL'] = $trackingurl;
                        $cu->metadata['First_Country'] = 'Current';
                        $cu->metadata['Status'] = 'Shipped';
                        $cu->metadata['Shipping_Status'] = 'Label Generated - Not yet shipped.';
                        $cu->metadata['Tracker'] = $tracker;
                        $cu->save();
                    }

                    if($customer_id && !$email)
                    {
                        $email = $cu->email;
                    }
                }
                else
                {
                    $stmt = $dbh->prepare("SELECT email, barcode FROM OrdersBatch WHERE Payment_ID = :payment_id");
                    $stmt->bindParam(":payment_id", $customerreference);
                    $stmt->execute();

                    $data = $stmt->fetch(PDO::FETCH_OBJ);
                    $email = $data->email;
                    $barcode = $data->barcode;
                    $stmt = null;

                    if(!$email)
                    {
                        $stmt = $dbh->prepare("SELECT email FROM OrderHistory WHERE Payment_ID = :payment_id");
                        $stmt->bindParam(":payment_id", $customerreference);
                        $stmt->execute();

                        $email = $stmt->fetch(PDO::FETCH_COLUMN);
                        $stmt = null;
                    }
                }
                

                $plan = '';

                //>need to get the plan name to change email template for B2Bsamples.
                $sql4 = "SELECT * FROM `OrderHistory` where `Payment_ID` = '$customerreference'";
                $result4 = $conn->query($sql4);
                
                $sales_order_reference = '';
                $price = 0;
                while($row4 = $result4->fetch_assoc()){
                    $plan = $row4["Plan"];
                    $sales_order_reference = $row4["sales_order_reference"];
                    $price =  $row4["amount_paid"] ? $row4["amount_paid"] : $row4["Price"];
                }

                $line_items_count = 0;
                $sales_order = $inventree->getSalesOrderByReference($sales_order_reference);
                $part = $inventree->getPartByBarcode($barcode);

                if($sales_order != null && $part != null) {
                    $sales_order_id = $sales_order->pk;
                    $stock_part_id = $part->pk;
                    $stock = $inventree->getStockByPartId($stock_part_id);
                    
                    $line_items = $inventree->getLineItems($sales_order_id);
                    $line_items_count = count($line_items);

                    if($line_items_count == 1) {
                        $line_item = $line_items[0];
                        $line_part_id = $line_item->part;
                        if($line_part_id !== $stock_part_id) {
                            //remove line item from sales order
                            $inventree->removeLineItemFromSalesOrder($line_item->pk);
                            $line_items_count = 0;
                        }
                    }

                    if($line_items_count == 0) {
                        //add this item to sales order
                        $line_item_data = array(
                            'quantity' => 1,
                            'reference' => '',
                            'notes' => '',
                            'overdue' => false,
                            'part' => $stock_part_id,
                            'order' => $sales_order_id,
                            'sale_price' => $price / 100,
                            'sale_price_currency' => 'USD',
                            'target_date' => date('Y-m-d'),
                            'link' => ''
                        );
                        $line_item = $inventree -> addLineItemToSalesOrder($line_item_data);
                    }

                    $shipments = $inventree->getShipmentBySalesOrderId($sales_order_id);
                    if(empty($shipments)) {

                        $shipment_data = array(
                            'order' => $sales_order_id,
                            'reference' => sprintf("SHIP-%s-%d-%d", $sales_order_reference, time(), mt_rand(1000, 9999)),
                            'tracking_number' => '',
                            'delivery_date' => date("Y-m-t"),
                            'invoice_number' => $customerreference
                        );
                        $shipment_inv = $inventree->createShipment($shipment_data);
                        $shipment_id = $shipment_inv->pk;
                    }
                    else {
                        $shipment_inv = $shipments[0];
                        $shipment_id = $shipment_inv->pk;
                        $shipment_data = array(
                            'delivery_date' => date("Y-m-t"),
                            'invoice_number' => $customerreference,
                            "reference" => $shipment_inv->reference,
                            'order' => $sales_order_id
                        );
                        $inventree->updateShipment($shipment_id, $shipment_data);
                    }
                    
                    $allocation_items = [];
                    $allocation_items[] = array(
                        'line_item' => $line_item->pk,
                        'quantity' => '1',
                        'stock_item' => $stock->pk
                    );
                    $allocation_data = array(
                        'items' => $allocation_items,
                        'shipment' => $shipment_id
                    );
                    $inventree->allocateStockItems($sales_order_id, $allocation_data);

                    $shipment_data = array(
                        'shipment_date' => date('Y-m-d'),
                        'tracking_number' => $trackingnumber
                    );
                    $inventree->completeShipment($shipment_id, $shipment_data);
                    $inventree->completeOrder($sales_order_id);
                }
                
                $sql = "UPDATE OrderHistory SET trackingcode = '$tracker' WHERE Payment_ID = '$customerreference'";
                $result = $conn->query($sql) or die($conn->error);
                
                $sql2 = "UPDATE OrderHistory SET Producer = '$producer' WHERE Payment_ID = '$customerreference'";
                $result2 = $conn->query($sql2) or die($conn->error);
                
                $sql3 = "UPDATE OrderHistory SET trackingnumber = '$trackingnumber' WHERE Payment_ID = '$customerreference'";
                $result3 = $conn->query($sql3) or die($conn->error);

                $payment_ID2 = '';
                $sql5 = "SELECT * FROM `OrdersBatch` where `Payment_ID` = '$customerreference'";
                $result5 = $conn->query($sql5);
                while($row5 = $result5->fetch_assoc()){
                    $payment_ID2 = $row5["Payment_ID2"];
                }

                if(!empty($payment_ID2)) {
                    $sql6 = "UPDATE OrderHistory SET trackingcode = '$tracker' WHERE Payment_ID = '$payment_ID2'";
                    $result = $conn->query($sql6) or die($conn->error);
                    
                    $sql7 = "UPDATE OrderHistory SET Producer = '$producer' WHERE Payment_ID = '$payment_ID2'";
                    $result2 = $conn->query($sql7) or die($conn->error);
                    
                    $sql8 = "UPDATE OrderHistory SET trackingnumber = '$trackingnumber' WHERE Payment_ID = '$payment_ID2'";
                    $result3 = $conn->query($sql8) or die($conn->error);
                }
                
                if($email == "@snackcrate.com" || is_null($email) || $email == ''){continue;}


                $stmt = $dbh->prepare("SELECT email_sent FROM OrdersBatch WHERE Payment_ID = :payment_id");
                $stmt->bindParam(":payment_id", $customerreference);
                $stmt->execute();
                $sent = $stmt->fetch(PDO::FETCH_COLUMN);
                $stmt = null;

                $stmt = $dbh->prepare("UPDATE OrdersBatch SET email_sent = 1 WHERE Payment_ID = :payment_id");
                $stmt->bindParam(":payment_id", $customerreference);
                $stmt->execute();

                if($sent == 1)
                    continue;

                /* SMTP API
                ====================================================*/

                // Obtain template variables
                /* BEGIN - cratesize */
                $directus = new PDO('mysql:dbname=snackcratedb;host=' . $servername . ';charset=utf8', $username, $password);
                $directus->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $stmt = $dbh->prepare("SELECT Plan FROM Orders WHERE Payment_ID = :customerreference");
                $stmt->bindParam(':customerreference', $customerreference);
                $stmt->execute();
                $planid = $stmt->fetch(PDO::FETCH_COLUMN);
                $stmt = null;

                $stmt_plan = $directus->prepare('SELECT `pretty_name` FROM `plans` WHERE plan_id=:planid');
                $stmt_plan->bindParam(':planid', $planid);
                $stmt_plan->execute();
                $crate_size = $stmt_plan->fetch(PDO::FETCH_COLUMN);
                $stmt_plan = null;
                if($crate_size == '' || is_null($crate_size))
                {
                    $crate_size = "";//"SnackCrate";
                }

                /* END - cratesize */
            
                // ADD THE SUBSTITUTIONS   
                $to_address = $shipment->to_address;

                $address_for_notification  = $to_address->street1 . ', '; 
                $address_for_notification .=($to_address->street2 != '' && !is_null($to_address->street2)) ? $to_address->street2 . ', ' : '';
                $address_for_notification .= $to_address->city . ', ';
                $address_for_notification .= $to_address->state . ', ';
                $address_for_notification .= $to_address->zip;

                SCKlaviyoHelper::getInstance()->sendEvent(
                    'Fulfilled Order',
                    $email,
                    array(),
                    array(
                        'event_details' => array(
                            "trackingLink" => $trackingurl,
                            "shippingAddress" => ucwords($address_for_notification),
                            "shipmentDate" => date('n/j/y'),
                            "crateSize" => $crate_size
                        ),
                    )
                );
            }
            catch(Exception $e)
            {
                $msg = $e->getMessage();
                $date = date('Y-m-d H:i:s');
                $error_file = "easypost/easypost_errors.log";
                $message = "{$date} Error in for loop of ProcessBatch.php: {$msg}\n";
                file_put_contents($error_file, $message, FILE_APPEND);
            }
        }
    }
    $stmt_batches = null;
		
			
		
    /*
	if ($conn->query($sql) === TRUE) {
		echo "New record created successfully";
	} else {
		echo "Error: " . $sql . "<br>" . $conn->error;
    }
    */
}
catch(Exception $e)
{
	//$date = date('Y-m-d H:i:s');
    //$error_file = get_template_directory() . "/assets/includes/php-error.log";
    //$message = "{$date} ProcessBatch Error: ".$e->getMessage()."\n";
    //file_put_contents($error_file, $message, FILE_APPEND);
    
    //mail('bhackett@snackcrate.com', 'BATCH Error Notice', $message);
    print_r($e);
    $msg = $e->getMessage();
    $date = date('Y-m-d H:i:s');  
    $error_file = "easypost/easypost_errors.log";
    $message = "{$date} Error out of for loop on ProcessBatch.php: {$msg}\n";
    file_put_contents($error_file, $message, FILE_APPEND);
}

?>
