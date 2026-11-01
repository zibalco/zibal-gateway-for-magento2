<?php

namespace ChalakSoft\Zibal\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Communication with the Zibal API failed or returned an unusable response.
 */
class GatewayException extends LocalizedException
{
}
