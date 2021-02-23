<?php

namespace ChalakSoft\Zibal\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const BASE = 'payment/zibal/';

    /**
     * @param Context $context
     */
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    /**
     * @return boolean
     */
    public function getMerchantId()
    {
        return $this->getConfigValue('merchant_id', static::BASE);
    }

    private function getConfigValue($code, $path, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $path . $code, ScopeInterface::SCOPE_STORE, $storeId
        );
    }
}
