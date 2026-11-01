<?php

namespace ChalakSoft\Zibal\Controller\Index;

use ChalakSoft\Zibal\Model\Exception\GatewayException;
use ChalakSoft\Zibal\Model\PaymentService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Sends the customer's just-placed order to the Zibal gateway.
 */
class Index extends Action implements HttpGetActionInterface
{
    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var PaymentService */
    private $paymentService;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        CheckoutSession $checkoutSession,
        PaymentService $paymentService,
        LoggerInterface $logger
    ) {
        parent::__construct($context);

        $this->checkoutSession = $checkoutSession;
        $this->paymentService = $paymentService;
        $this->logger = $logger;
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        // Only the order placed in this customer's own session can be paid here.
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order || !$order->getId()) {
            $this->messageManager->addErrorMessage(__('سفارش مورد نظر پیدا نشد.'));

            return $resultRedirect->setPath('checkout/cart');
        }

        try {
            $gatewayUrl = $this->paymentService->start($order);

            return $resultRedirect->setUrl($gatewayUrl);
        } catch (GatewayException $e) {
            // Nothing was paid and the order is cancelled: give the cart back so
            // the customer can simply try again.
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(
                __('%1 سبد خرید شما بازگردانده شد؛ لطفاً دوباره تلاش کنید.', $e->getMessage())
            );

            return $resultRedirect->setPath('checkout/cart');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(__('انتقال به درگاه پرداخت با خطا مواجه شد.'));
        }

        return $resultRedirect->setPath('checkout/onepage/failure');
    }
}
