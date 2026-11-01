<?php

namespace ChalakSoft\Zibal\Model;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;

class Zibal extends AbstractMethod
{
    protected $_code = 'zibal';

    protected $_infoBlockType = \ChalakSoft\Zibal\Block\Info::class;

    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentHelper $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        UrlInterface $urlBuilder,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?DirectoryHelper $directory = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data,
            $directory
        );

        $this->urlBuilder = $urlBuilder;
    }

    public function acceptPayment(InfoInterface $payment)
    {
       return true;
    }

    public function canVoid()
    {
       return true;
    }

    /**
     * A non-empty redirect URL tells Magento the customer still has to pay
     * elsewhere, so the order confirmation email is held back until the
     * payment is verified (see PaymentService).
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->urlBuilder->getUrl('zibal/index/index', ['_secure' => true]);
    }

    /**
     * Hide the method until a merchant ID is configured.
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        $storeId = $quote ? $quote->getStoreId() : null;

        if (trim((string)$this->getConfigData('merchant_id', $storeId)) === '') {
            return false;
        }

        return parent::isAvailable($quote);
    }
}
