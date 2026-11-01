<?php

namespace ChalakSoft\Zibal\Controller\Index;

use ChalakSoft\Zibal\Model\CallbackToken;
use ChalakSoft\Zibal\Model\PaymentService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Psr\Log\LoggerInterface;

/**
 * Customer returns here from Zibal:
 * GET /zibal/index/callback/t/<encrypted token>/?trackId=&success=&status=
 */
class Callback extends Action implements HttpGetActionInterface
{
    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var OrderFactory */
    private $orderFactory;

    /** @var CallbackToken */
    private $callbackToken;

    /** @var PaymentService */
    private $paymentService;

    /** @var RemoteAddress */
    private $remoteAddress;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        CallbackToken $callbackToken,
        PaymentService $paymentService,
        RemoteAddress $remoteAddress,
        LoggerInterface $logger
    ) {
        parent::__construct($context);

        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->callbackToken = $callbackToken;
        $this->paymentService = $paymentService;
        $this->remoteAddress = $remoteAddress;
        $this->logger = $logger;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        $trackId = (string)$this->getRequest()->getParam('trackId', '');
        $token = $this->callbackToken->parse($this->getRequest()->getParam(CallbackToken::PARAM));
        $order = $token && ctype_digit($trackId) ? $this->resolveOrder($token) : null;

        if (!$order) {
            $this->logger->warning('Zibal callback rejected before processing', [
                'trackId' => $trackId,
                'tokenValid' => (bool)$token,
                'ip' => $this->remoteAddress->getRemoteAddress(),
            ]);
            $this->messageManager->addErrorMessage(__('اطلاعات بازگشتی از درگاه معتبر نیست.'));

            return $resultRedirect->setPath('checkout/onepage/failure');
        }

        try {
            $result = $this->paymentService->complete($order, $trackId, $token['n']);

            if ($result->isPaid()) {
                $this->prepareSuccessPage($order);

                return $resultRedirect->setPath('checkout/onepage/success');
            }

            $this->messageManager->addErrorMessage($result->getMessage());
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(__(
                'در بررسی پرداخت خطایی رخ داد. اگر مبلغ از حساب شما کسر شده، با کد پیگیری %1 با پشتیبانی فروشگاه تماس بگیرید.',
                $trackId
            ));
        }

        return $resultRedirect->setPath('checkout/onepage/failure');
    }

    /**
     * The order is identified only by the encrypted token, never by plain parameters.
     *
     * @param array $token
     * @return Order|null
     */
    private function resolveOrder(array $token)
    {
        $order = $this->orderFactory->create()->loadByIncrementIdAndStoreId($token['o'], $token['s']);

        if (!$order || !$order->getId()) {
            return null;
        }

        // Guards against a reused increment ID (e.g. after the sequence was reset).
        if (!hash_equals((string)$order->getCreatedAt(), $token['c'])) {
            return null;
        }

        return $order;
    }

    /**
     * The success page only renders for the order recorded in the checkout session,
     * which may be gone (expired, other browser). The callback link is one-time, so
     * only the first, genuine return from Zibal gets here.
     *
     * @param Order $order
     */
    private function prepareSuccessPage(Order $order)
    {
        $this->checkoutSession->setLastQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
        $this->checkoutSession->setLastOrderId($order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
    }
}
