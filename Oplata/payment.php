<?php

/**
 * payment.php
 * -----------
 **/

//if (!empty($_POST)) {
//    mail('@', 'popoff', json_encode($_POST));
//}

class Oplata
{
    const RESPONCE_SUCCESS = 'success';
    const RESPONCE_FAIL = 'failure';

    const ORDER_SEPARATOR = '#';

    const SIGNATURE_SEPARATOR = '|';

    const ORDER_APPROVED = 'approved';
    const ORDER_DECLINED = 'declined';

    const URL = "https://api.oplata.com/api/checkout/redirect/";

    protected static $responseFields = array(
        'rrn',
        'masked_card',
        'sender_cell_phone',
        'response_status',
        'currency',
        'fee',
        'reversal_amount',
        'settlement_amount',
        'actual_amount',
        'order_status',
        'response_description',
        'order_time',
        'actual_currency',
        'order_id',
        'tran_type',
        'eci',
        'settlement_date',
        'payment_system',
        'approval_code',
        'merchant_id',
        'settlement_currency',
        'payment_id',
        'sender_account',
        'card_bin',
        'response_code',
        'card_type',
        'amount',
        'sender_email');

    public static function getSignature($data, $password, $encoded = true)
    {
        $data = array_filter($data, function($var) {
            return $var !== '' && $var !== null;
        });
        ksort($data);

        $str = $password;
        foreach ($data as $k => $v) {
            $str .= self::SIGNATURE_SEPARATOR . $v;
        }

        if ($encoded) {
            return sha1($str);
        } else {
            return $str;
        }
    }

    public static function isPaymentValid($oplataSettings, $response)
    {
        if ($oplataSettings['merchant'] != $response['merchant_id']) {
            return 'An error has occurred during payment. Merchant data is incorrect.';
        }

        $originalResponse = $response;
        foreach ($response as $k => $v) {
            if (!in_array($k, self::$responseFields)) {
                unset($response[$k]);
            }
        }

        if (self::getSignature($response, $oplataSettings['secretkey']) != $originalResponse['signature']) {
            return 'An error has occurred during payment. Signature is not valid.';
        }

        return true;
    }
}



/**
 * Function pay_go
 **/
function pay_go()
{
    global $gOptions;

    $orderId = $_SESSION['order_info']['code'];

    $returnUrl = HOST_NAME . 'pay_get.php?type=Oplata'; //&order_sid=' . $_GET['order_sid'];
    $oplata = new Oplata();
    $oplataArgs = array('order_id' => $orderId . Oplata::ORDER_SEPARATOR . time(),
        'merchant_id' => MERCHANT_ID,
        'order_desc' => 'Shopping',
        'amount' => round($_SESSION['order_info']['totalsum'] * 100),
        'currency' => 'UAH',
        'server_callback_url' => $returnUrl,
        'response_url' => $returnUrl . '&send_email=1',
        'lang' => 'RU',
        'sender_email' => $_SESSION['client']['email'],
        'delayed' => 'N');

    $oplataArgs['signature'] = $oplata->getSignature($oplataArgs, MERCHANT_SECRET);


    $oplataArgsArray = array();
    foreach ($oplataArgs as $key => $value) {
        $oplataArgsArray[] = "<input type='hidden' name='$key' value='$value'/>";
    }

    echo '	<form action="' . Oplata::URL . '" method="post" id="oplata_payment_form">
  				' . implode('', $oplataArgsArray) . '
				<input type="submit" id="submit_oplata_payment_form" />
					<script type="text/javascript">
					document.getElementById("submit_oplata_payment_form").click();
					</script>
				</form>';
    exit;
}


/**
 * Function pay_get
 **/
function pay_get()
{
    global $gData;

//    echo "<pre>";
//    print_r($_POST);
//    exit;

    // Connect to Data
    $gData = data_connect();


    list($orderId,) = explode(OPLATA::ORDER_SEPARATOR, $_POST['order_id']);
    list( ,$orderId) = explode('-', $orderId);
    $order = $gData->GetArchiveOrder($orderId * 1);

    $oplata = new Oplata();
    $settings = array(
        'merchant' => MERCHANT_ID,
        'secretkey' => MERCHANT_SECRET
    );

    $invoice_url = HOST_NAME . 'invoice.php?order_id=' . $orderId . '&order_sid=' . $order['sid'];
    if ($_POST['order_status'] == Oplata::ORDER_DECLINED) {
        header('location: ' . $invoice_url);
    }

    $paymentInfo = $oplata->isPaymentValid($settings, $_REQUEST);
    if ($paymentInfo === true && isset($_REQUEST['send_email']) && !empty($_REQUEST['send_email'])) {
        $content = sendEmailToAdmin($order);

        return $content;
    } else {
        header('location: ' . $invoice_url);
    }
}

function sendEmailToAdmin($order)
{
    global $gOptions;

    // Send e-mail for admin
    $tplm = new FastTemplate('./pay_mod/Oplata');
    $tplm->DefineTemplate(array('mail_message' => 'pay_try_mail.htm'));
    $tplm->Assign(array(
        'ORDER_CODE' => htmlspecialchars($order['code']),
        'SUMA' => number_format($order['payment_cost'], 2, ',', "'"),
        'CURR' => htmlspecialchars($order['payment_curr']),
        'SHOPNAMES' => htmlspecialchars($gOptions['attr_shop_name']),
        'SHOPURL' => htmlspecialchars($gOptions['attr_shop_url'])
    ));
    $tplm->Parse('MAIL', 'mail_message');
    $mailer = new Emailer(MAIL_SERVER);
    $mailer->SetCharset($gOptions['attr_admin_charset']);
    $mailer->SetTypeText();
    $all_message = iconv(SHOP_CHARSET, $gOptions['attr_admin_charset'], $tplm->Fetch('MAIL'));
    $subject = substr($all_message, strpos($all_message, 'Message_subject:') + 16, strpos($all_message, 'Message_content:') - 16);
    $message = substr($all_message, strpos($all_message, 'Message_content:') + 16);
    $mailer->AddMessage($message);
    $mailer->BuildMessage();
    $mailer->Send($gOptions['attr_admin_email'], $gOptions['attr_shop_email'], ltrim($subject, " "));

    $content = @implode("", (@file('./pay_mod/Oplata/pay_success.htm')));

    return $content;
}

