<?php

namespace ChalakSoft\Zibal\Helper;

use ChalakSoft\Zibal\Model\Exception\GatewayException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

class Zibal
{
    const API_URL = 'https://gateway.zibal.ir/v1/';

    const START_URL = 'https://gateway.zibal.ir/start/';

    /** Seconds to wait for the whole request */
    const TIMEOUT = 20;

    /** Seconds to wait for the TCP/TLS connection */
    const CONNECT_TIMEOUT = 10;

    /** @var Data */
    protected $helperData;

    /** @var CurlFactory */
    protected $curlFactory;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(Data $helperData, CurlFactory $curlFactory, LoggerInterface $logger)
    {
        $this->helperData = $helperData;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
    }

    /**
     * Calls Zibal's REST API
     *
     * @param string $path
     * @param array $parameters
     * @param int|null $storeId
     *
     * @return array decoded response, always containing a "result" key
     *
     * @throws GatewayException when the request fails or the response is not usable
     */
    public function postToZibal($path, array $parameters, $storeId = null)
    {
        $parameters['merchant'] = $this->helperData->getMerchantId($storeId);

        $url = static::API_URL . $path;

        $curl = $this->curlFactory->create();
        $curl->setTimeout(static::TIMEOUT);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, static::CONNECT_TIMEOUT);
        $curl->addHeader('Content-Type', 'application/json');

        try {
            $curl->post($url, json_encode($parameters));
        } catch (\Exception $e) {
            $this->logger->error('Zibal request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new GatewayException(__('ارتباط با درگاه زیبال برقرار نشد.'), $e);
        }

        $body = (string)$curl->getBody();
        $response = json_decode($body, true);

        if (!is_array($response) || !isset($response['result'])) {
            $this->logger->error('Zibal returned an invalid response', [
                'path' => $path,
                'status' => $curl->getStatus(),
                'body' => mb_substr($body, 0, 500),
            ]);

            throw new GatewayException(__('پاسخ نامعتبر از درگاه زیبال دریافت شد.'));
        }

        return $response;
    }

    /**
     * @param string $trackId
     * @return string
     */
    public function getStartUrl($trackId)
    {
        return static::START_URL . rawurlencode((string)$trackId);
    }

    /**
     * returns a string message based on result parameter from curl response
     *
     * @param $code
     *
     * @return string
     */
    public function resultCodes($code)
    {
        switch ($code) {
            case 100:
                return 'با موفقیت تایید شد';

            case 102:
                return 'merchant یافت نشد';

            case 103:
                return 'merchant غیرفعال';

            case 104:
                return 'merchant نامعتبر';

            case 201:
                return 'قبلا تایید شده';

            case 105:
                return 'amount بایستی بزرگتر از 1,000 ریال باشد';

            case 106:
                return 'callbackUrl نامعتبر می‌باشد. (شروع با http و یا https)';

            case 113:
                return 'amount مبلغ تراکنش از سقف میزان تراکنش بیشتر است.';

            case 202:
                return 'سفارش پرداخت نشده یا ناموفق بوده است';

            case 203:
                return 'trackId نامعتبر می‌باشد';

            default:
                return 'وضعیت مشخص شده معتبر نیست';
        }
    }

    /**
     * returns a string message based on status parameter from $_GET
     *
     * @param $code
     *
     * @return string
     */
    public function statusCodes($code)
    {
        switch ($code) {
            case -1:
                return 'در انتظار پردخت';

            case -2:
                return 'خطای داخلی';

            case 1:
                return 'پرداخت شده - تاییدشده';

            case 2:
                return 'پرداخت شده - تاییدنشده';

            case 3:
                return 'لغوشده توسط کاربر';

            case 4:
                return '‌شماره کارت نامعتبر می‌باشد';

            case 5:
                return '‌موجودی حساب کافی نمی‌باشد';

            case 6:
                return 'رمز واردشده اشتباه می‌باشد';

            case 7:
                return '‌تعداد درخواست‌ها بیش از حد مجاز می‌باشد';

            case 8:
                return '‌تعداد پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد';

            case 9:
                return 'مبلغ پرداخت اینترنتی روزانه بیش از حد مجاز می‌باشد';

            case 10:
                return '‌صادرکننده‌ی کارت نامعتبر می‌باشد';

            case 11:
                return 'خطای سوییچ';

            case 12:
                return 'کارت قابل دسترسی نمی‌باشد';

            default:
                return 'وضعیت مشخص شده معتبر نیست';
        }
    }
}
