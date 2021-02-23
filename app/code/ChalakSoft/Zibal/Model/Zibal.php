<?php

namespace ChalakSoft\Zibal\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\InfoInterface;

class Zibal extends AbstractMethod
{
    protected $_code = 'zibal';

    public function acceptPayment(InfoInterface $payment)
    {
       return true;
    }

    public function canVoid()
    {
       return true;
    }
}
