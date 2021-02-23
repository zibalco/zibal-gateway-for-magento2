<?php

namespace ChalakSoft\Zibal\Block;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Config\Block\System\Config\Form\Field;

class SendSms extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $url = 'https://magento-shop.ir/send-sms-magento-module.html';

        return "<a href='{$url}'>ماژول ارسال اس ام اس</a>";
    }
}
