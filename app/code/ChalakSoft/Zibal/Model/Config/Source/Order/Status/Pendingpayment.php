<?php

namespace ChalakSoft\Zibal\Model\Config\Source\Order\Status;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Config\Source\Order\Status;

class Pendingpayment extends Status
{
    /**
     * @var string[]
     */
    protected $_stateStatuses = [
        Order::STATE_PENDING_PAYMENT,
        Order::STATE_CANCELED,
        Order::STATE_CLOSED,
        Order::STATE_COMPLETE,
        Order::STATE_HOLDED,
        Order::STATE_NEW,
        Order::STATE_PAYMENT_REVIEW,
        Order::STATE_PROCESSING,
    ];
}
