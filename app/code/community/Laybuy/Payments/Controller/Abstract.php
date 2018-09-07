<?php
/**
* Magento BinaryPay Payment Extension
*
* NOTICE OF LICENSE
*
* Copyright 2017 MageBinary
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
*   http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*
* @category    MageBinary
* @package     MageBinary_BinaryPay
* @author      MageBinary Team
* @copyright   Copyright (c) 2017 MageBinary (http://www.magebinary.com)
* @license     http://www.apache.org/licenses/LICENSE-2.0
*/

/**
 * Abstract BinaryPay Controller
 */
abstract class Laybuy_Payments_Controller_Abstract extends Mage_Core_Controller_Front_Action
{
    /**
     * $_gateway - Gateway Name for each controller
     */
    protected $_gateway;

    /**
     * $_data - save card info after build the card or mobile number details
     * @var array
     */
    protected $_data;

    /**
     * $_api - API model for current gateway
     */
    protected $_api;

    /**
     * $_tokenRef to delete
     * @var string
     */
    protected $_tokenRef;

    /**
     * Predispatch: should set layout area
     *
     * @return Mage_Core_Controller_Front_Action
     */
    public function preDispatch()
    {
        parent::preDispatch();

        if (!Mage::getSingleton('customer/session')->isLoggedIn() ||
            !Mage::getModel('magebinary_binarypay/directpost')->getPaymentMethodConfig('active') &&
            !Mage::getModel('magebinary_binarypay/webpayment')->getPaymentMethodConfig('active')) {
            $this->_redirect('/');
            return;
        }
    }

    /**
     * _getApi - Set API value and return Api model object
     * @return object
     */
    protected function _getApi()
    {
        if (!isset($this->_api) && !is_object($this->_api)) {
            $this->_api = Mage::getModel('magebinary_binarypay/'.$this->_gateway);
        }
        return $this->_api;
    }

    public function getApi()
    {
        return $this->_api = Mage::getModel('magebinary_binarypay/'.$this->_gateway);
    }

    /**
     * _redirectAfterSuccess - Redirect user after successful operation
     */
    protected function _redirectAfterSuccess()
    {
        if ($redirectUrl = Mage::getSingleton('core/session')->getRedirectUrl(true)) {
            $this->_redirectUrl($redirectUrl);
        }
        return $this;
    }

    /**
     * _validateDataInfo - validate data info that user want to be saved
     * @return
     */
    protected function _validateDataInfo()
    {
        $data = $this->_data;
        // All fields should not be empty
        foreach ($data as $key => $value) {
            if (empty($value)) {
                Mage::throwException("Invalid key $key, value can not be empty");
                break;
            }
        }
        return $this;
    }

    /**
     * _prepareGetGateway - validate requested data ( Get and Post )
     * @return MageBinary_BinaryPay_Model_$this->_gateway
     */
    protected function _prepareGetGateway()
    {
        $request = $this->getRequest()->getPost();

        // If the request is not post
        if (empty($request)) {
            // try to get Data
            $request = $this->getRequest()->getParams();
            if (empty($request)) {
                Mage::throwException('Unknown requested data!');
            }
        }

        return $this;
    }

    /**
     * _validateDeleteRequest - validate delete request and set request reference value
     */
    protected function _validateDeleteRequest()
    {
        $request        = $this->getRequest();
        $params         = $request->getParams();

        if (empty($params['token_reference']) && empty($params['mobile_number'])) {
            Mage::throwException($this->__('Unknown Request!'));
        }

        if (!empty($params['token_reference'])) {
            $this->_tokenRef = $params['token_reference'];
        }

        return $this;
    }

    /**
     * Return checkout session object
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckoutSession()
    {
        if (!$this->_checkoutSession) {
            $this->_checkoutSession = Mage::getSingleton('checkout/session');
        }
        return $this->_checkoutSession;
    }

    /**
     * _sendMessage - send message to user in frontend
     * @param  array $message
     * @return $this
     */
    protected function _sendMessage($message)
    {
        if (isset($message) && !empty($message)) {
            $this->getResponse()->clearHeaders()->setHeader('Content-type', 'application/json', true);
            $this->getResponse()->setBody(json_encode($message));
        }

        return $this;
    }

    protected function _setGateway($gateway)
    {
        $this->_gateway = $gateway;
        return $this;
    }

    protected function isBackendOrder()
    {
        $isBackend = false;
        $cookie     = Mage::getModel('core/cookie');
        $session = $this->_getCheckoutSession();
        // If comming from backend
        $quoteId = $cookie->get('quoteEntityId');
        $cookie->delete('quoteEntityId');

        if ($quoteId) {
            $isBackend = true;
        }
        return $isBackend;
    }

    public function placeOrder($status)
    {
        $quote = $this->_getQuote();

        $quote->collectTotals();

        $checkout = Mage::getModel('laybuy_payments/checkout')->setQuote($quote)->setStatus($status);

        $checkout->saveOrder();
    }

    /**
     * Return checkout quote object
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote()
    {
        if (!$this->_quote) {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }
        return $this->_quote;
    }

    public function createOrderForBackend($quote)
    {
        $incrementId = $this->_createOrderForBackend($quote->getId());
        $order       = Mage::getModel('sales/order')->load($incrementId, 'increment_id');
        $orderId     = $order->getId();
        return $this->_redirectUrl($this->_generateBackendUrl($orderId));
    }

    protected function _generateBackendUrl($orderId)
    {
        $url = Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/view');
        $url = str_replace('view/', "view/order_id/$orderId/", $url);
        return $url;
    }

    protected function _createOrderForBackend($quoteId)
    {
        // Trigger capture method to set transaction data and place order
        $quote = Mage::getModel('sales/quote')->load($quoteId);
        $quote->collectTotals();
        $service = Mage::getModel('sales/service_quote', $quote);
        $service->submitAll();
        $quote->save();

        /* Get Order Id */
        $incrementId = $quote->getData('reserved_order_id');

        return $incrementId;
    }

    abstract protected function _getOrderStatusAndMessage();
}