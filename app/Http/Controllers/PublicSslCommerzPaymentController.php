<?php

namespace shopist\Http\Controllers;
use App\Models\Order;
use App\Models\Payment;
use Brian2694\Toastr\Facades\Toastr;
//use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Session;
use Illuminate\Routing\UrlGenerator;
use App\Http\Controllers;
use Anam\Phpcart\Cart;
session_start();

class PublicSslCommerzPaymentController extends Controller
{
    public function index(Request $request)
    {
        //echo 'kkkk';exit;
        //$shippingCost = number_format(Session::get('shipping_cost'),2);
        //$total = Cart::total(null, null, '') + $shippingCost;
        //echo $total = Cart::total(null, null, '');
        //dd($total);
        //exit;
        //dd('ok');
        # Here you have to receive all the order data to initate the payment.
        # Lets your oder trnsaction informations are saving in a table called "orders"
        # In orders table order uniq identity is "order_id","order_status" field contain status of the transaction, "grand_total" is the order amount to be paid and "currency" is for storing Site Currency which will be checked with paid currency.
        $post_data = array();
        $post_data['total_amount'] = 10; # You cant not pay less than 10
        $post_data['currency'] = "BDT";
        $post_data['tran_id'] = uniqid(); // tran_id must be unique

        $order =  Order::find(Session::get('order_id'));
//        $order->total = $post_data['total_amount'];
        $order->currency = $post_data['currency'];
        $order->transaction_id = $post_data['tran_id'];
        $order->ssl_status = 'Pending';
        $order->save();

        #Start to save these value  in session to pick in success page.
        $_SESSION['payment_values']['tran_id']=$post_data['tran_id'];
        #End to save these value  in session to pick in success page.
        $server_name=$request->root()."/";
        $post_data['success_url'] = $server_name . "success";
        $post_data['fail_url'] = $server_name . "fail";
        $post_data['cancel_url'] = $server_name . "cancel";

        #Before  going to initiate the payment order status need to update as Pending.
        $update_product = DB::table('orders')
            ->where('transaction_id', $post_data['tran_id'])
            ->update(['ssl_status' => 'Pending','currency' => $post_data['currency']]);
        $sslc = new SSLCommerz();
        //dd($update_product);
        # initiate(Transaction Data , false: Redirect to SSLCOMMERZ gateway/ true: Show all the Payement gateway here )
        $payment_options = $sslc->initiate($post_data, false);
        if (!is_array($payment_options)) {
            print_r($payment_options);
            $payment_options = array();
        }
    }
    public function success(Request $request)
    {
        echo 'success';exit;
        //echo "Transaction is Successful";
        $sslc = new SSLCommerz();
        #Start to received these value from session. which was saved in index function.
        $tran_id = $_SESSION['payment_values']['tran_id'];
        #End to received these value from session. which was saved in index function.
        #Check order status in order tabel against the transaction id or order id.
        $order_detials = DB::table('orders')
            ->where('transaction_id', $tran_id)
            ->select('transaction_id', 'ssl_status','currency','total')->first();
        $chekTotal= $order_detials->total + number_format(Session::get('shipping_cost'),2);
        //dd($chekTotal);
        if($order_detials->ssl_status=='Pending')
        {

            $validation = $sslc->orderValidate($tran_id, $chekTotal, $order_detials->currency, $request->all());
            if($validation == TRUE)
            {
                /*
                That means IPN did not work or IPN URL was not set in your merchant panel. Here you need to update order status
                in order table as Processing or Complete.
                Here you can also sent sms or email for successfull transaction to customer
                */
                $update_product = DB::table('orders')
                    ->where('transaction_id', $tran_id)
                    ->update([
                        'ssl_status' => 'Completed',
                        'amount_after_getaway_fee' => $_POST['store_amount'],

//                        'payment_method' => $_POST['card_type'],
                        'payment_details' => json_encode($_POST),
                    ]);
                $order =  Order::find(Session::get('order_id'));
                $payment_method = Payment::find($order->payment_id);
                $payment_method->type = $_POST['card_type'];
                $payment_method->status = 'Complete';
                $payment_method->save();

                Toastr::success('Transaction is successfully Completed tar','Success');
                Cart::destroy();
                return redirect('profile/Order');
            }
            else
            {
                /*
                That means IPN did not work or IPN URL was not set in your merchant panel and Transation validation failed.
                Here you need to update order status as Failed in order table.
                */
                $update_product = DB::table('orders')
                    ->where('transaction_id', $tran_id)
                    ->update(['ssl_status' => 'Failed']);
                echo "validation Fail";
            }
        }
        else if($order_detials->ssl_status=='Processing' || $order_detials->ssl_status=='Complete')
        {
            /*
             That means through IPN Order status already updated. Now you can just show the customer that transaction is completed. No need to udate database.
             */
            //echo "Transaction is successfully Complete ash";
            Toastr::success('Transaction is successfully Completed tar','Success');
            Cart::destroy();
            return redirect('profile/Order');
        }
        else
        {
            #That means something wrong happened. You can redirect customer to your product page.
            //echo "Invalid Transaction";
            Toastr::error('Invalid Transaction ','Error');
            Cart::destroy();
            return redirect('/');
        }

    }
    public function fail(Request $request)
    {
        echo 'fail';exit;
        $tran_id = $_SESSION['payment_values']['tran_id'];
        $order_detials = DB::table('orders')
            ->where('transaction_id', $tran_id)
            ->select('transaction_id', 'ssl_status','currency','total')->first();
        if($order_detials->order_status=='Pending')
        {
            $update_product = DB::table('orders')
                ->where('transaction_id', $tran_id)
                ->update(['ssl_status' => 'Failed']);
            //echo "Transaction is Falied";
            Toastr::error('Transaction is Falied','Error');
            Cart::destroy();
            return redirect('/');
        }
        else if($order_detials->ssl_status=='Processing' || $order_detials->ssl_status=='Complete')
        {
            //echo "Transaction is already Successful";
            Toastr::success('Transaction is already Successful','Success');
            Cart::destroy();
            return redirect('profile/Order');
        }
        else
        {
            //echo "Transaction is Invalid";
            Toastr::error('Transaction is Invalid','Error');
            Cart::destroy();
            return redirect('/');
        }

    }
    public function cancel(Request $request)
    {
        echo 'cancel';exit;
        $tran_id = $_SESSION['payment_values']['tran_id'];
        $order_detials = DB::table('orders')
            ->where('transaction_id', $tran_id)
            ->select('transaction_id', 'ssl_status','currency','total')->first();
        if($order_detials->ssl_status=='Pending')
        {
            $update_product = DB::table('orders')
                ->where('transaction_id', $tran_id)
                ->update(['ssl_status' => 'Canceled']);
            //echo "Transaction is Cancel";
            Toastr::error('Transaction is Cancel','Error');
            Cart::destroy();
            return redirect('/');
        }
        else if($order_detials->ssl_status=='Processing' || $order_detials->ssl_status=='Complete')
        {
            //echo "Transaction is already Successful";
            Toastr::success('Transaction is already Successful','Success');
            Cart::destroy();
            return redirect('profile/Order');
        }
        else
        {
            //echo "Transaction is Invalid";
            Toastr::error('Transaction is Invalid','Error');
            Cart::destroy();
            return redirect('/');
        }

    }
    public function ipn(Request $request)
    {
        #Received all the payement information from the gateway
        if($request->input('tran_id')) #Check transation id is posted or not.
        {
            $tran_id = $request->input('tran_id');
            #Check order status in order tabel against the transaction id or order id.
            $order_details = DB::table('orders')
                ->where('transaction_id', $tran_id)
                ->select('transaction_id', 'ssl_status','currency','total')->first();
            if($order_details->ssl_status =='Pending')
            {
                $sslc = new SSLCommerz();
                $validation = $sslc->orderValidate($tran_id, $order_details->total, $order_details->currency, $request->all());
                if($validation == TRUE)
                {
                    /*
                    That means IPN worked. Here you need to update order status
                    in order table as Processing or Complete.
                    Here you can also sent sms or email for successfull transaction to customer
                    */
                    $update_product = DB::table('orders')
                        ->where('transaction_id', $tran_id)
                        ->update(['ssl_status' => 'Processing']);

                    echo "Transaction is successfully Complete";
                }
                else
                {
                    /*
                    That means IPN worked, but Transation validation failed.
                    Here you need to update order status as Failed in order table.
                    */
                    $update_product = DB::table('orders')
                        ->where('transaction_id', $tran_id)
                        ->update(['ssl_status' => 'Failed']);

                    echo "validation Fail";
                }

            }
            else if($order_details->ssl_status == 'Processing' || $order_details->ssl_status =='Complete')
            {

                #That means Order status already updated. No need to udate database.

                echo "Transaction is already successfully Complete";
            }
            else
            {
                #That means something wrong happened. You can redirect customer to your product page.

                echo "Invalid Transaction";
            }
        }
        else
        {
            echo "Inavalid Data";
        }
    }
}
