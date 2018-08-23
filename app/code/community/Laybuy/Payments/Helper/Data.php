<?php
/**
 * Created by PhpStorm.
 * User: carl
 * Date: 8/07/17
 * Time: 11:38
 */
class Laybuy_Payments_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function getPendingPaymentStatus() {
        if (version_compare(Mage::getVersion(), '1.4.0', '<')) {
            return Mage_Sales_Model_Order::STATE_HOLDED;
        }
        return Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
    }

    public function generateUID($length = 2)
    {
        return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"), 0, $length);
    }

    public function prefix($target, $prefix, $delimiter = '_')
    {
        return $prefix . $delimiter . $target;
    }
}