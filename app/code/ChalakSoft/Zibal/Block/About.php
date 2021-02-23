<?php

namespace ChalakSoft\Zibal\Block;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Config\Block\System\Config\Form\Field;

class About extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $url = 'https://chalaksoft.ir';

        return "<a href='{$url}'>ساخته شده توسط گروه نرم افزاری چلاک سافت</a>";
    }
}
