<?php

require_once _PS_MODULE_DIR_.'iyzipay/classes/IyzipayModel.php';
require_once _PS_MODULE_DIR_.'iyzipay/classes/IyzipayCheckoutFormObject.php';
require_once _PS_MODULE_DIR_.'iyzipay/classes/IyzipayPkiStringBuilder.php';
require_once _PS_MODULE_DIR_.'iyzipay/classes/IyzipayRequest.php';

class IyzipayCallBackModuleFrontController extends ModuleFrontController {

    public function __construct() {

        parent::__construct();
        $this->display_column_left  = false;
        $this->display_column_right = false;
        $this->context              = Context::getContext();

    }

    public function init() {
        parent::init();


    try {

        if(!Tools::getValue('token')) {

           $errorMessage = $this->l('tokenNotFound');
           throw new \Exception("Token not found");
        
        }

        $customerId      = (int) $this->context->cookie->id_customer;
        $orderId         = (int) $this->context->cookie->id_cart;
        $locale          = $this->context->language->iso_code;
        $remoteIpAddr    = Tools::getRemoteAddr();

        $cart             = $this->context->cart;
        $cartTotal        = (float) $cart->getOrderTotal(true,Cart::BOTH);
        $customer         = new Customer($cart->id_customer);

        $currency                       = $this->context->currency;
        $shopId                         = (int) $this->context->shop->id;
        $currenyId                      = (int) $currency->id;
        $languageId                     = (int) $this->context->language->id;
        $customerSecureKey              = $customer->secure_key;
        $iyziTotalPrice                 = (float) $this->context->cookie->totalPrice;
        $token                          = Tools::getValue('token');

        $extraVars = array();
        $installmentMessage = false;


        $apiKey          = Configuration::get('iyzipay_api_key');
        $secretKey       = Configuration::get('iyzipay_secret_key');
        $rand            = rand(100000,99999999);
        $endpoint        = Configuration::get('iyzipay_api_type');
        $responseObject  = IyzipayCheckoutFormObject::responseObject($orderId,$token,$locale);
        $pkiString       = IyzipayPkiStringBuilder::pkiStringGenerate($responseObject);
        $authorization   = IyzipayPkiStringBuilder::authorization($pkiString,$apiKey,$secretKey,$rand);
        $responseObject  = json_encode($responseObject,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $requestResponse = IyzipayRequest::checkoutFormRequestDetail($endpoint,$responseObject,$authorization);

        $requestResponse->installment     = (int)   $requestResponse->installment;
        $requestResponse->paidPrice       = (float) $requestResponse->paidPrice;
        $requestResponse->paymentId       = (int)   $requestResponse->paymentId;
        $requestResponse->conversationId  = (int)   $requestResponse->conversationId;

        if(empty($orderId)) {

           if($token) {

              $this->cancelPayment($locale,$requestResponse->paymentId,$remoteIpAddr,$apiKey,$secretKey,$rand,$endpoint);

           } else {

            $errorMessage = $this->l('orderNotFound');
            throw new \Exception($errorMessage);

          }

        }


      if($requestResponse->paymentStatus == 'SUCCESS') {

        if($this->context->cookie->iyziToken == $token) {

          $iyziTotalPriceFraud  = $iyziTotalPrice;

          if (isset($requestResponse->installment) && !empty($requestResponse->installment) && $requestResponse->installment > 1) {

            $installmentFee       = $requestResponse->paidPrice - $iyziTotalPrice; 
            $iyziTotalPriceFraud  = $iyziTotalPrice + $installmentFee;
          
          }

          if($iyziTotalPriceFraud < $cartTotal) {

             $this->cancelPayment($locale,$requestResponse->paymentId,$remoteIpAddr,$apiKey,$secretKey,$rand,$endpoint);

          }

        } else {

          $errorMessage = $this->l('basketItemsNotMatch');
            throw new \Exception($errorMessage);

        }

      }


        $iyzicoLocalOrder = new stdClass;
        $iyzicoLocalOrder->paymentId     = !empty($requestResponse->paymentId) ? (int) $requestResponse->paymentId : '';
        $iyzicoLocalOrder->orderId       = $orderId;
        $iyzicoLocalOrder->totalAmount   = !empty($requestResponse->paidPrice) ? (float) $requestResponse->paidPrice : '';
        $iyzicoLocalOrder->status        = $requestResponse->paymentStatus; 

        $iyzicoInsertOrder  = IyzipayModel::insertIyzicoOrder($iyzicoLocalOrder);

        if($requestResponse->paymentStatus != 'SUCCESS' || $requestResponse->status != 'success' || $orderId != $requestResponse->basketId ) {

            if($requestResponse->status == 'success' && $requestResponse->paymentStatus == 'FAILURE') {
              
              $errorMessage = $this->l('error3D');
              throw new Exception($errorMessage);
                
            }

            /* Redirect Error */
            $errorMessage = $this->l('generalError');
            $errorMessage = isset($requestResponse->errorMessage) ? $requestResponse->errorMessage : $errorMessage;
            throw new \Exception($errorMessage);
        }

        /* Save Card */
        if(isset($requestResponse->cardUserKey)) {

            if($customerId) {

                $cardUserKey = IyzipayModel::findUserCardKey($customerId,$apiKey);

                if($requestResponse->cardUserKey != $cardUserKey) {

                    $insertCardUserKey = IyzipayModel::insertCardUserKey($customerId,$requestResponse->cardUserKey,$apiKey);

                }
                
            }   
        
        }

        if (isset($requestResponse->installment) && !empty($requestResponse->installment) && $requestResponse->installment > 1) {
            /* Installment Calc and DB Update */

            $installmentFee                         = $requestResponse->paidPrice - $iyziTotalPrice;
            $this->context->cookie->installmentFee  = $installmentFee;

            $installmentMessage = '<br><br><strong style="color:#000;">Taksitli Alışveriş: </strong>Toplam ödeme tutarınıza <strong style="color:#000">'.$requestResponse->installment.' Taksit </strong> için <strong style="color:red">'.Tools::displayPrice($installmentFee,$currency, false).'</strong> yansıtılmıştır.<br>';

            $installmentMessageEmail = '<br><br><strong style="color:#000;">'.$this->l('installmentShopping').'</strong><br> '.$this->l('installmentOption').'<strong style="color:#000"> '.$requestResponse->installment.' '.$this->l('InstallmentKey').'<br></strong>'.$this->l('commissionAmount').'<strong style="color:red">
             '.Tools::displayPrice($installmentFee,$currency, false).'</strong><br>';

            $extraVars['{total_paid}']            = Tools::displayPrice($requestResponse->paidPrice,$currency, false);
            $extraVars['{date}']                  = Tools::displayDate(date('Y-m-d H:i:s'), null, 1).$installmentMessageEmail;

            /* Invoice false */
            Configuration::updateValue('PS_INVOICE',false);
        }


      $this->module->validateOrder($orderId,Configuration::get('PS_OS_PAYMENT'), $cartTotal, $this->module->displayName, $installmentMessage, $extraVars, $currenyId,false,$customerSecureKey);

      if (isset($requestResponse->installment) && !empty($requestResponse->installment) && $requestResponse->installment > 1) {

          /* Invoice true */
          Configuration::updateValue('PS_INVOICE',$orderId);

          $currentOrderId = (int) $this->module->currentOrder;
          $order = new Order($currentOrderId);

          /* Update Total Price and Installment Calc and DB Update  */
          $updateOrderTotal     = IyzipayModel::updateOrderTotal($requestResponse->paidPrice,$currentOrderId);
          
          $updateOrderPayment   = IyzipayModel::updateOrderPayment($requestResponse->paidPrice,$order->reference);

          /* Open Thread */
          $customer_thread = new CustomerThread();
          $customer_thread->id_contact  = 0;
          $customer_thread->id_customer = $customer->id;
          $customer_thread->id_shop     = $shopId;
          $customer_thread->id_order    = $currentOrderId;
          $customer_thread->id_lang     = $languageId;
          $customer_thread->email       = $customer->email;
          $customer_thread->status      = 'open';
          $customer_thread->token       = Tools::passwdGen(12);
          $customer_thread->add();

          /* Add Info Message */
           $customer_message = new CustomerMessage();
           $customer_message->id_customer_thread  = $customer_thread->id;
           $customer_message->id_employee         = 1;
           $customer_message->message             = $installmentMessage;
           $customer_message->private             = 0;
           $customer_message->add();

      }
      
      Tools::redirect('index.php?controller=order-confirmation&id_cart='.$orderId.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);

       } catch(Exception $e) {


        $errorMessage = $e->getMessage();

        $this->context->smarty->assign(array(
            'errorMessage' => $errorMessage,
        ));
        

          $this->setTemplate('module:iyzipay/views/templates/front/iyzi_error.tpl');

       }
    }

    private function cancelPayment($locale,$paymentId,$remoteIpAddr,$apiKey,$secretKey,$rand,$endpoint) {

        $responseObject  = IyzipayCheckoutFormObject::cancelObject($locale,$paymentId,$remoteIpAddr);
        $pkiString       = IyzipayPkiStringBuilder::pkiStringGenerate($responseObject);
        $authorization   = IyzipayPkiStringBuilder::authorization($pkiString,$apiKey,$secretKey,$rand);

        $responseObject  = json_encode($responseObject,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

        $cancelResponse = IyzipayRequest::paymentCancel($endpoint,$responseObject,$authorization);

        if($cancelResponse->status == 'success') {

          $errorMessage = $this->l('basketItemsNotMatch');
          throw new \Exception($errorMessage);

        } 

          $errorMessage = $this->l('uniqError');
          throw new \Exception($errorMessage);

    }

 }