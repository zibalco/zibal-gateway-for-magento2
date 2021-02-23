<?php

namespace ChalakSoft\Zibal\Block;

use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\OrderFactory;
use ChalakSoft\Zibal\Helper\Zibal;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;

class Redirect extends Template
{
    protected $checkoutSession;
    protected $orderFactory;
    protected $scopeConfig;
    protected $urlBuilder;
    protected $messageManager;
    protected $catalogSession;
    protected $customerSession;

    /**
     * @var $order Order
     */
    protected $order;

    /** @var Order cache */
    protected $_order;

    /** @var Zibal */
    protected $zibal;

    /** @var BuilderInterface */
    protected $transactionBuilder;

    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        OrderFactory $orderFactory,
        ManagerInterface $messageManager,
        Session $customerSession,
        Template\Context $context,
        BuilderInterface $transactionBuilder,
        Zibal $zibal,
        array $data
    ) {
        parent::__construct($context, $data);

        $this->customerSession = $customerSession;
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->scopeConfig = $context->getScopeConfig();
        $this->urlBuilder = $context->getUrlBuilder();
        $this->messageManager = $messageManager;
        $this->zibal = $zibal;
        $this->transactionBuilder = $transactionBuilder;
    }


    /**
     * @return Order
     */
    public function getOrder()
    {
        if (!empty($this->_order)) {
            return $this->_order;
        }

        $id = $this->getRequest()->getParam('order_id', 0);

        if (empty($id)) {
            $id = $this->customerSession->getOrderId();
        }

        $this->_order = $this->orderFactory->create()->load($id);

        return $this->_order;
    }

    protected function checkOrder()
    {
        $order = $this->getOrder();

        if (empty($order) || $order->isEmpty()) {
            $this->checkoutFail('سفارش مورد نظر پیدا نشد');
        }

        if ($order->getState() != 'new') {
            $this->checkoutFail('این سفارش بسته شده است');
        }

        return $order;
    }

    public function send()
    {
        $order = $this->checkOrder();

        $this->customerSession->setOrderId($order->getId());

        $parameters = [
            'callbackUrl' => $this->getUrl('zibal/Index/callback'),
            'amount' => $this->getOrderPrice(),
        ];

        $response = $this->zibal->postToZibal('request', $parameters);

        if (!isset($response['result'], $response['trackId']) || (int)$response['result'] != 100) {
            $this->checkoutFail(
                $this->zibal->resultCodes($response['result']),
                true
            );
        }

        $trackId = $response['trackId'];

        $startGateWayUrl = 'https://gateway.zibal.ir/start/' . $trackId;

        $this->createTransaction(Transaction::TYPE_CAPTURE, [
            'id' => $trackId,
            'message' => 'انتقال به درگاه پرداخت',
        ]);

        header('location: ' . $startGateWayUrl);

        exit();
    }

    public function back()
    {
        $order = $this->checkOrder();

        $success = (int)$this->getRequest()->getParam('success');
        $trackId = (int)$this->getRequest()->getParam('trackId');

        if ($success != 1) {
            $this->checkoutFail('پرداخت با شکست مواجه شد.', true);
        }

        $response = $this->zibal->postToZibal('verify', [
            'trackId' => $trackId,
        ]);

        if ($response['result'] != 100) {
            $this->checkoutFail(
                $this->zibal->resultCodes($response['result'])
            );
        }

        if ($this->getOrderPrice() != $response['amount']) {
            $this->checkoutFail('پرداخت انجام شد (مشکلی رخ داد)');
        }

        $this->createTransaction(Transaction::TYPE_PAYMENT, [
            'id' => $trackId,
            'message' => $message = 'پرداخت با موفقیت انجام شد',
        ]);

        $this->customerSession->setOrderId('');

        header('Location: ' . $this->getCheckoutSuccess());

        exit();
    }

    protected function createTransaction($status, $paymentData = [])
    {
        $order = $this->getOrder();

        $payment = $order->getPayment();

        $payment->setLastTransId($paymentData['id']);
        $payment->setTransactionId($paymentData['id']);

        $payment->setAdditionalInformation(
            [Transaction::RAW_DETAILS => (array)$paymentData]
        );

        $formatedPrice = $order->getBaseCurrency()->formatTxt(
            $this->getOrderPrice()
        );

        $message = __('The authorized amount is %1.', $formatedPrice);

        $transaction = $this->transactionBuilder->setPayment($payment)
            ->setOrder($order)
            ->setTransactionId($paymentData['id'])
            ->setAdditionalInformation(
                [Transaction::RAW_DETAILS => (array)$paymentData]
            )
            ->setFailSafe(true)
            ->build($status);

        $payment->addTransactionCommentsToOrder(
            $transaction,
            $message
        );

        if ($status == Transaction::TYPE_PAYMENT) {
            $payment->setIsTransactionClosed(1);
            $payment->accept();
        }

        $payment->setParentTransactionId(null);
        $payment->save();
        $order->save();

        return $transaction->save()->getTransactionId();
    }

    protected function checkoutFail($message, $closeOrder = false)
    {
        $this->checkoutSession->setErrorMessage($message);

        if ($closeOrder) {
            $order = $this->getOrder();
            $order->cancel();
            $order->addCommentToStatusHistory($message);
            $order->save();
        }

        header('Location: ' . $this->urlBuilder->getUrl('checkout/onepage/failure'));

        exit();
    }

    protected function getOrderPrice()
    {
        /** @var Order $order */
        $order = $this->getOrder();

        $amount = $order->getGrandTotal();

        if ($this->useToman()) {
            $amount = $amount / 10;
        }

        return (int)$amount;
    }

    protected function getCheckoutSuccess()
    {
        return $this->urlBuilder->getUrl('checkout/onepage/success');
    }

    private function getConfig($value)
    {
        return $this->scopeConfig->getValue('payment/zibal/' . $value, ScopeInterface::SCOPE_STORE);
    }

    protected function useToman()
    {
        return (bool)$this->getConfig('isirt');
    }
}

