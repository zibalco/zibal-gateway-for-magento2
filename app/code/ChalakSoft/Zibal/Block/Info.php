<?php

namespace ChalakSoft\Zibal\Block;

use ChalakSoft\Zibal\Model\PaymentService;
use Magento\Payment\Block\Info as PaymentInfo;

/**
 * Shows the details of a verified Zibal payment on the order.
 * Emails and PDFs render in secure mode, which keeps the card number out of them.
 */
class Info extends PaymentInfo
{
    /**
     * @param null|\Magento\Framework\DataObject|array $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);
        $payment = $this->getInfo();
        $trackId = $payment->getAdditionalInformation(PaymentService::KEY_VERIFIED_TRACK_ID);

        if (!$trackId) {
            return $transport;
        }

        $details = [(string)__('کد پیگیری زیبال') => $trackId];

        $paidAt = $payment->getAdditionalInformation(PaymentService::KEY_PAID_AT);

        if ($paidAt) {
            $details[(string)__('تاریخ و ساعت پرداخت')] = $this->formatPaidAt($paidAt);
        }

        $cardNumber = $payment->getAdditionalInformation(PaymentService::KEY_CARD_NUMBER);

        if ($cardNumber && !$this->getIsSecureMode()) {
            $details[(string)__('شماره کارت پرداخت‌کننده')] = $cardNumber;
        }

        return $transport->addData($details);
    }

    /**
     * Zibal sends a local time without an offset (2018-03-25T23:43:01.053000), so it
     * is shown as received: Magento's date helpers would treat it as UTC and shift it.
     *
     * @param string $paidAt
     * @return string
     */
    private function formatPaidAt($paidAt)
    {
        return substr(str_replace('T', ' ', $paidAt), 0, 19);
    }
}
