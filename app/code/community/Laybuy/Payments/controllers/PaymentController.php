<?php
/**
 * @package   Laybuy_Payments
 * @author    16hands <carl@16hands.co.nz>
 * @copyright Copyright (c) 2017 Laybuy (https://www.laybuy.com/)
 */

/**
 * Class Laybuy_Payments_PaymentController
 *
 * Controller for the Laybuy Payment Process
 *
 */
class Laybuy_Payments_PaymentController extends Laybuy_Payments_Controller_Abstract
{
    const LAYBUY_LIVE_URL = 'https://api.laybuy.com';

    const LAYBUY_SANDBOX_URL = 'https://sandbox-api.laybuy.com';

    const LAYBUY_RETURN_SUCCESS = 'laybuypayments/success';

    const LAYBUY_RETURN_FAIL = 'laybuypayments/fail';

    const LAYBUY_LOG_FILENAME = 'laybuy_debug.log';

    protected $_checkout;
    protected $_quote;

    protected function _getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    public function _validateResponse($request)
    {
        //TODO: some validation logic here.
        if ($request->getParam('status') !== 'SUCCESS') {
            Mage::throwException('You have canceled the payment at checkout. Please contact us for more assistance or select another payment method to complete checkout.');
        }

        $token = $this->getRequest()->getParam('token');
        if (!$this->getRequest()->getParam('token')) {
            Mage::throwException('Laybuy reference token cannot be found');
        }

        $apiResponse = json_decode($this->getLaybuyClient()->restPost('/order/confirm', json_encode(array('token'=> $token)))->getBody());
        $apiStatus = isset($apiResponse->result) ? $apiResponse->result : 'Unknown';

        if ($apiStatus !== 'SUCCESS') {
            Mage::throwException('Transcation DECLINED');
        }

        $apiResponse->token = $token;

        return $apiResponse;
    }

    // The response action is triggered when Laybuy sends back a response after processing the customer's payment
    //  GET /laybuypayments/payment/response/?status=SUCCESS&token=z8jFQf31BbRN3fEmjUbrxYZhQ6bwTtNNXoyCTpjo
    public function responseAction()
    {
        $request = $this->getRequest();
        $session = $this->_getCheckoutSession();

        // Processing the order
        try {
            $apiResponse = $this->_validateResponse($request);
            // Pass Response To Capture
            $session->setResponse(get_object_vars($apiResponse));
            $session->setIsPayment(true);
            // Check Response Status
            return $this->_forward('placeOrder');
        } catch (Mage_Core_Exception $e) {
            //Magento or payment error.
            $session->addError($e->getMessage());
            Mage::log($e->getTrace(), null, 'laybuy.log', true);
        } catch (Exception $e) {
            //system error.
            $session->addError($e->getMessage());
            Mage::log($e->getTrace(), null, 'laybuy.log', true);
        }
        $this->_redirect('checkout/cart');
    }

        /**
     * placeOrderAction - Submit and place the order
     */
    public function placeOrderAction()
    {
        $session = $this->_getCheckoutSession();

        // Unset data after use
        if (!$session->getIsPayment()) {
            // Redirect if customers try to access the controller directly
            return $this->_redirect('checkout/cart');
        }
        $session->unsIsPayment();

        try {
            $status = $this->_getOrderStatusAndMessage();
            $this->placeOrder($status);
        } catch (Exception $e) {
            Mage::helper('checkout')->sendPaymentFailedEmail(
                $this->_getQuote(),
                $e->getMessage()
            );
            $session->addError($e->getMessage());
            Mage::log($e->getTrace(), Zend_Log::ERR, 'laybuy.log', true);
        }
        $this->_redirect('checkout/onepage/success');
        return;
    }

    protected function _getOrderStatusAndMessage()
    {
        $status = new Varien_Object();
        $status->setStatus(Mage_Sales_Model_Order::STATE_PROCESSING);
        return $status;
    }


    private function getLaybuyClient() {

        $laybuy_sandbox = $this->getConfigData('sandbox_mode') == 1;

        if ($laybuy_sandbox) {
            $laybuy_merchantid = $this->getConfigData('sandbox_merchantid');
            $laybuy_apikey = $this->getConfigData('sandbox_apikey');
            $url = self::LAYBUY_SANDBOX_URL;
        } else {
            $laybuy_merchantid = $this->getConfigData('live_merchantid');
            $laybuy_apikey = $this->getConfigData('live_apikey');
            $url = self::LAYBUY_LIVE_URL;
        }

        try {
            $client = new Zend_Rest_Client($url);
            $client->getHttpClient()->setAuth($laybuy_merchantid, $laybuy_apikey, Zend_Http_Client::AUTH_BASIC);

        } catch (Exception $e) {

            Mage::logException($e);
            //Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());

            $this->dbg(__METHOD__ . ': LAYBUY CLIENT FAILED: ' . $laybuy_merchantid . ":< apikey >");

            $result['success'] = FALSE;
            $result['error'] = TRUE;
            $result['error_messages'] = $this->__('There was an error processing your order. Please contact us or try again later. [Laybuy connect]');

            // Let customer know its real bad
            Mage::getSingleton('core/session')->addError($result['error_messages']);
        }

        return $client;

    }

    private function getConfigData($field, $storeId = NULL) {
        $path = 'payment/laybuy_payments/' . $field;
        return Mage::getStoreConfig($path, $storeId);
    }



}