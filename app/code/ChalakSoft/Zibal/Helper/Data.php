<?php

namespace ChalakSoft\Zibal\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    const BASE = 'payment/zibal/';

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getMerchantId($storeId = null)
    {
        return trim((string)$this->getConfigValue('merchant_id', static::BASE, $storeId));
    }

    /**
     * Whether shop prices are entered in Toman (Zibal expects Rial).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function useToman($storeId = null)
    {
        return (bool)$this->getConfigValue('isirt', static::BASE, $storeId);
    }

    private function getConfigValue($code, $path, $storeId = null)
    {
        return $this->scopeConfig->getValue(
            $path . $code, ScopeInterface::SCOPE_STORE, $storeId
        );
    }
}
