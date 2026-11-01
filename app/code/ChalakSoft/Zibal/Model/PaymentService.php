<?php

namespace ChalakSoft\Zibal\Model;

use ChalakSoft\Zibal\Helper\Data;
use ChalakSoft\Zibal\Helper\Zibal as ZibalApi;
use ChalakSoft\Zibal\Model\Exception\GatewayException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment\Transaction;
use Psr\Log\LoggerInterface;

/**
 * Starts Zibal payments and verifies them when the customer returns.
 */
class PaymentService
{
    const METHOD_CODE = 'zibal';

    /** Payment additional_information keys */
    const KEY_TRACK_ID = 'zibal_track_id';
    const KEY_TRACK_IDS = 'zibal_track_ids';
    const KEY_VERIFIED_TRACK_ID = 'zibal_verified_track_id';
    const KEY_REVIEW_TRACK_ID = 'zibal_review_track_id';
    const KEY_REF_NUMBER = 'zibal_ref_number';
    const KEY_CARD_NUMBER = 'zibal_card_number';
    const KEY_PAID_AT = 'zibal_paid_at';
    /** [trackId => ['hash' => sha256(nonce), 'used' => bool]] */
    const KEY_CALLBACK_NONCES = 'zibal_callback_nonces';

    /** Zibal API result codes */
    const RESULT_SUCCESS = 100;
    const RESULT_ALREADY_VERIFIED = 201;
    const RESULT_NOT_PAID = 202;

    /** Zibal inquiry statuses */
    const STATUS_PAID_VERIFIED = 1;
    const STATUS_PAID_UNVERIFIED = 2;

    const CURRENCY_RIAL = 'IRR';
    const CURRENCY_TOMAN = 'IRT';

    const LOCK_TIMEOUT = 30;

    /** @var ZibalApi */
    private $api;

    /** @var Data */
    private $config;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var LockManagerInterface */
    private $lockManager;

    /** @var LoggerInterface */
    private $logger;

    /** @var OrderSender */
    private $orderSender;

    /** @var InvoiceSender */
    private $invoiceSender;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $historyRepository;

    /** @var OrderStatusHistoryInterfaceFactory */
    private $historyFactory;

    /** @var CallbackToken */
    private $callbackToken;

    public function __construct(
        ZibalApi $api,
        Data $config,
        OrderRepositoryInterface $orderRepository,
        LockManagerInterface $lockManager,
        LoggerInterface $logger,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        OrderStatusHistoryRepositoryInterface $historyRepository,
        OrderStatusHistoryInterfaceFactory $historyFactory,
        CallbackToken $callbackToken
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->orderRepository = $orderRepository;
        $this->lockManager = $lockManager;
        $this->logger = $logger;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->historyRepository = $historyRepository;
        $this->historyFactory = $historyFactory;
        $this->callbackToken = $callbackToken;
    }

    /**
     * Amount to charge in Rial, computed from the base (not display) currency.
     *
     * @param Order $order
     * @return int
     * @throws LocalizedException when the base currency is not Rial/Toman
     */
    public function getAmount(Order $order)
    {
        $currency = strtoupper((string)$order->getBaseCurrencyCode());

        if ($currency === self::CURRENCY_TOMAN) {
            $toman = true;
        } elseif ($currency === self::CURRENCY_RIAL) {
            $toman = $this->config->useToman($order->getStoreId());
        } else {
            throw new LocalizedException(
                __('واحد پول پایهٔ فروشگاه باید ریال ایران (IRR) باشد. واحد فعلی: %1', $currency)
            );
        }

        $amount = (float)$order->getBaseGrandTotal();

        if ($toman) {
            $amount *= 10;
        }

        return (int)round($amount);
    }

    /**
     * Requests a payment from Zibal and returns the gateway URL to send the customer to.
     *
     * The callback URL carries only an encrypted one-time token; the order number
     * is deliberately not sent, so Zibal cannot echo it back in plain sight.
     *
     * @param Order $order
     * @return string
     * @throws LocalizedException
     */
    public function start(Order $order)
    {
        if (!$this->isZibalOrder($order) || !$this->isAwaitingPayment($order)) {
            throw new LocalizedException(__('این سفارش قابل پرداخت نیست.'));
        }

        $amount = $this->getAmount($order);
        $token = $this->callbackToken->generate($order);

        try {
            $response = $this->api->postToZibal('request', [
                'amount' => $amount,
                'callbackUrl' => $this->callbackToken->getCallbackUrl($token['token']),
                // Shown in the Zibal panel so payments can still be traced by hand.
                'description' => (string)__('سفارش %1', $order->getIncrementId()),
            ], $order->getStoreId());
        } catch (GatewayException $e) {
            $this->cancel($order, $e->getMessage());

            throw $e;
        }

        if ((int)$response['result'] !== self::RESULT_SUCCESS || empty($response['trackId'])) {
            $message = $this->api->resultCodes((int)$response['result']);

            $this->logger->warning('Zibal rejected payment request', [
                'order' => $order->getIncrementId(),
                'result' => $response['result'],
                'message' => isset($response['message']) ? $response['message'] : null,
            ]);

            $this->cancel($order, $message);

            throw new GatewayException(__('%1', $message));
        }

        $trackId = (string)$response['trackId'];

        $payment = $order->getPayment();
        $trackIds = (array)$payment->getAdditionalInformation(self::KEY_TRACK_IDS);
        $trackIds[] = $trackId;
        $payment->setAdditionalInformation(self::KEY_TRACK_IDS, array_values(array_unique($trackIds)));
        $payment->setAdditionalInformation(self::KEY_TRACK_ID, $trackId);

        $nonces = (array)$payment->getAdditionalInformation(self::KEY_CALLBACK_NONCES);
        $nonces[$trackId] = ['hash' => CallbackToken::hashNonce($token['nonce']), 'used' => false];
        $payment->setAdditionalInformation(self::KEY_CALLBACK_NONCES, $nonces);

        $order->addCommentToStatusHistory(
            __('انتقال به درگاه زیبال. کد پیگیری: %1، مبلغ: %2 ریال', $trackId, $amount)
        );

        $this->orderRepository->save($order);

        return $this->api->getStartUrl($trackId);
    }

    /**
     * Verifies the payment for a customer returning from the gateway.
     *
     * Never trusts the callback's "success" flag: the order is only cancelled or
     * invoiced based on Zibal's server-side verify/inquiry response.
     *
     * @param Order $order
     * @param string $trackId
     * @param string $nonce one-time secret from the callback token
     * @return PaymentResult
     * @throws LocalizedException when the callback does not belong to the order or was already used
     */
    public function complete(Order $order, $trackId, $nonce)
    {
        $lockName = 'zibal_order_' . $order->getId();

        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            return new PaymentResult(
                PaymentResult::REVIEW,
                __('پرداخت این سفارش در حال بررسی است. لطفاً چند لحظه بعد وضعیت سفارش را بررسی کنید.')
            );
        }

        try {
            // Reload inside the lock so a concurrent callback's changes are visible.
            $order = $this->orderRepository->get($order->getId());

            return $this->process($order, (string)$trackId, (string)$nonce);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * @param Order $order
     * @param string $trackId
     * @param string $nonce
     * @return PaymentResult
     * @throws LocalizedException
     */
    private function process(Order $order, $trackId, $nonce)
    {
        if (!$this->isZibalOrder($order) || !$this->ownsTrackId($order, $trackId)) {
            $this->logger->warning('Zibal callback does not match order', [
                'order' => $order->getIncrementId(),
                'trackId' => $trackId,
            ]);

            throw new LocalizedException(__('اطلاعات بازگشتی از درگاه معتبر نیست.'));
        }

        $this->assertCallbackNonce($order, $trackId, $nonce);

        if ($order->getPayment()->getAdditionalInformation(self::KEY_VERIFIED_TRACK_ID) === $trackId) {
            return new PaymentResult(PaymentResult::PAID);
        }

        if ($order->getPayment()->getAdditionalInformation(self::KEY_REVIEW_TRACK_ID) === $trackId) {
            return new PaymentResult(PaymentResult::REVIEW, $this->contactSupportMessage($trackId));
        }

        if (!$this->isAwaitingPayment($order)) {
            return $this->handleClosedOrder($order, $trackId);
        }

        try {
            $response = $this->api->postToZibal('verify', ['trackId' => $trackId], $order->getStoreId());
            $result = (int)$response['result'];

            if ($result === self::RESULT_ALREADY_VERIFIED) {
                // Verified earlier but not recorded here (e.g. a crash after verify).
                $response = $this->api->postToZibal('inquiry', ['trackId' => $trackId], $order->getStoreId());
                $result = (int)$response['result'] === self::RESULT_SUCCESS
                    && isset($response['status'])
                    && (int)$response['status'] === self::STATUS_PAID_VERIFIED
                        ? self::RESULT_SUCCESS
                        : self::RESULT_ALREADY_VERIFIED;
            }
        } catch (GatewayException $e) {
            return $this->review($order, $trackId, $e->getMessage());
        }

        if ($result === self::RESULT_NOT_PAID) {
            $message = $this->api->resultCodes($result);
            $this->consumeNonce($order, $trackId);
            $this->cancel($order, __('پرداخت زیبال ناموفق بود (کد پیگیری: %1): %2', $trackId, $message));

            return new PaymentResult(PaymentResult::FAILED, __('پرداخت با شکست مواجه شد.'));
        }

        if ($result !== self::RESULT_SUCCESS) {
            return $this->review($order, $trackId, $this->api->resultCodes($result) . ' (' . $result . ')');
        }

        return $this->registerPayment($order, $trackId, $response);
    }

    /**
     * Records a payment Zibal has verified. By now the money is taken, so any
     * failure here must leave a trace for the shop instead of disappearing.
     *
     * @param Order $order
     * @param string $trackId
     * @param array $data verify or inquiry response
     * @return PaymentResult
     */
    private function registerPayment(Order $order, $trackId, array $data)
    {
        try {
            $result = $this->capture($order, $trackId, $data);
        } catch (\Exception $e) {
            $this->logger->critical('Zibal payment verified but could not be recorded', [
                'order' => $order->getIncrementId(),
                'trackId' => $trackId,
                'exception' => $e,
            ]);

            $this->addHistoryComment($order, __(
                'پرداخت زیبال تأیید شد (کد پیگیری: %1) ولی ثبت آن در سفارش با خطا مواجه شد: %2. سفارش را دستی بررسی کنید.',
                $trackId,
                $e->getMessage()
            ));

            return new PaymentResult(PaymentResult::REVIEW, $this->contactSupportMessage($trackId));
        }

        if ($result->isPaid()) {
            $this->sendEmails($order);
        }

        return $result;
    }

    /**
     * @param Order $order
     * @param string $trackId
     * @param array $data verify or inquiry response
     * @return PaymentResult
     * @throws \Exception
     */
    private function capture(Order $order, $trackId, array $data)
    {
        $payment = $order->getPayment();

        foreach (['refNumber' => self::KEY_REF_NUMBER, 'cardNumber' => self::KEY_CARD_NUMBER, 'paidAt' => self::KEY_PAID_AT] as $field => $key) {
            if (isset($data[$field]) && is_scalar($data[$field])) {
                $payment->setAdditionalInformation($key, (string)$data[$field]);
            }
        }

        $expectedAmount = $this->getAmount($order);
        $paidAmount = isset($data['amount']) ? (int)$data['amount'] : null;
        $paidOrderId = isset($data['orderId']) ? (string)$data['orderId'] : '';

        $problems = [];

        if ($paidAmount !== $expectedAmount) {
            $problems[] = sprintf('amount paid %s != expected %d', var_export($paidAmount, true), $expectedAmount);
        }

        if ($paidOrderId !== '' && $paidOrderId !== (string)$order->getIncrementId()) {
            $problems[] = sprintf('paid orderId %s != %s', $paidOrderId, $order->getIncrementId());
        }

        if ($problems) {
            $this->logger->critical('Zibal payment does not match order', [
                'order' => $order->getIncrementId(),
                'trackId' => $trackId,
                'problems' => $problems,
            ]);

            $payment->setAdditionalInformation(self::KEY_REVIEW_TRACK_ID, $trackId);
            $this->consumeNonce($order, $trackId);

            if ($order->canHold()) {
                $order->hold();
            }

            $order->addCommentToStatusHistory(__(
                'پرداخت زیبال انجام شد ولی با سفارش مطابقت ندارد و نیاز به بررسی دستی دارد. کد پیگیری: %1، مبلغ پرداختی: %2 ریال، مبلغ مورد انتظار: %3 ریال، شمارهٔ سفارش در درگاه: %4',
                $trackId,
                $paidAmount === null ? '-' : $paidAmount,
                $expectedAmount,
                $paidOrderId === '' ? '-' : $paidOrderId
            ));

            $this->orderRepository->save($order);

            return new PaymentResult(PaymentResult::REVIEW, $this->contactSupportMessage($trackId));
        }

        $payment->setTransactionId($trackId);
        $payment->setLastTransId($trackId);
        $payment->setIsTransactionClosed(true);
        $payment->setAdditionalInformation(self::KEY_VERIFIED_TRACK_ID, $trackId);
        $this->consumeNonce($order, $trackId);
        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, $this->rawDetails($trackId, $data));

        // Creates the invoice and capture transaction and moves the order to "processing".
        $payment->registerCaptureNotification($order->getBaseGrandTotal(), true);

        $this->orderRepository->save($order);

        $this->logger->info('Zibal payment verified', [
            'order' => $order->getIncrementId(),
            'trackId' => $trackId,
            'amount' => $paidAmount,
        ]);

        return new PaymentResult(PaymentResult::PAID);
    }

    /**
     * The customer came back for an order that is no longer awaiting payment
     * (e.g. cancelled from another tab). The payment is never verified here, so an
     * unverified one is reversed to the customer by the gateway; we only record it.
     *
     * @param Order $order
     * @param string $trackId
     * @return PaymentResult
     */
    private function handleClosedOrder(Order $order, $trackId)
    {
        try {
            $response = $this->api->postToZibal('inquiry', ['trackId' => $trackId], $order->getStoreId());
        } catch (GatewayException $e) {
            return $this->review($order, $trackId, $e->getMessage());
        }

        $status = (int)$response['result'] === self::RESULT_SUCCESS && isset($response['status'])
            ? (int)$response['status']
            : null;

        if ($status === self::STATUS_PAID_UNVERIFIED) {
            $this->logger->warning('Zibal payment made for a closed order; left unverified', [
                'order' => $order->getIncrementId(),
                'state' => $order->getState(),
                'trackId' => $trackId,
            ]);

            $this->consumeNonce($order, $trackId);
            $order->addCommentToStatusHistory(__(
                'مشتری پس از بسته شدن سفارش پرداخت کرد (کد پیگیری: %1). این تراکنش تأیید (verify) نشد تا مبلغ به‌صورت خودکار به مشتری بازگردد.',
                $trackId
            ));
            $this->orderRepository->save($order);

            return new PaymentResult(PaymentResult::REVIEW, __(
                'این سفارش قبلاً بسته شده بود و پرداخت شما تأیید نشد. مبلغ کسرشده به‌صورت خودکار به حساب شما بازمی‌گردد. کد پیگیری: %1',
                $trackId
            ));
        }

        if ($status === self::STATUS_PAID_VERIFIED) {
            $this->logger->critical('Zibal payment verified for a closed order', [
                'order' => $order->getIncrementId(),
                'state' => $order->getState(),
                'trackId' => $trackId,
            ]);

            $order->getPayment()->setAdditionalInformation(self::KEY_REVIEW_TRACK_ID, $trackId);
            $this->consumeNonce($order, $trackId);
            $order->addCommentToStatusHistory(__(
                'پرداخت زیبال با کد پیگیری %1 برای این سفارش بسته‌شده تأیید شده است. مبلغ را بازگردانید یا سفارش را دستی بررسی کنید.',
                $trackId
            ));
            $this->orderRepository->save($order);

            return new PaymentResult(PaymentResult::REVIEW, $this->contactSupportMessage($trackId));
        }

        if ($status === null) {
            return $this->review($order, $trackId, 'inquiry result ' . $response['result']);
        }

        $this->consumeNonce($order, $trackId);
        $this->orderRepository->save($order);

        return new PaymentResult(PaymentResult::FAILED, __('این سفارش در انتظار پرداخت نیست.'));
    }

    /**
     * The token must be the one issued with this trackId, and unused.
     *
     * @param Order $order
     * @param string $trackId
     * @param string $nonce
     * @throws LocalizedException
     */
    private function assertCallbackNonce(Order $order, $trackId, $nonce)
    {
        $nonces = (array)$order->getPayment()->getAdditionalInformation(self::KEY_CALLBACK_NONCES);
        $entry = isset($nonces[$trackId]) && is_array($nonces[$trackId]) ? $nonces[$trackId] : null;

        if (!$entry || !isset($entry['hash'])
            || !hash_equals((string)$entry['hash'], CallbackToken::hashNonce($nonce))
        ) {
            $this->logger->warning('Zibal callback token was not issued for this payment', [
                'order' => $order->getIncrementId(),
                'trackId' => $trackId,
            ]);

            throw new LocalizedException(__('اطلاعات بازگشتی از درگاه معتبر نیست.'));
        }

        if (!empty($entry['used'])) {
            $this->logger->info('Zibal callback link reused', [
                'order' => $order->getIncrementId(),
                'trackId' => $trackId,
            ]);

            throw new LocalizedException(__(
                'این لینک بازگشت از درگاه قبلاً استفاده شده است. وضعیت سفارش را از ایمیل یا حساب کاربری خود بررسی کنید.'
            ));
        }
    }

    /**
     * Marks the callback link as used. Persisted by the order save that records
     * the outcome; links that ended in an undetermined state stay usable so the
     * customer can retry and the payment can still be recovered.
     *
     * @param Order $order
     * @param string $trackId
     */
    private function consumeNonce(Order $order, $trackId)
    {
        $payment = $order->getPayment();
        $nonces = (array)$payment->getAdditionalInformation(self::KEY_CALLBACK_NONCES);

        if (isset($nonces[$trackId]) && is_array($nonces[$trackId])) {
            $nonces[$trackId]['used'] = true;
            $payment->setAdditionalInformation(self::KEY_CALLBACK_NONCES, $nonces);
        }
    }

    /**
     * Order confirmation is held back at checkout (Model\Zibal::getOrderPlaceRedirectUrl)
     * and sent here once the payment is verified. Email problems never fail the payment.
     *
     * @param Order $order
     */
    private function sendEmails(Order $order)
    {
        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }

            $invoice = $order->getPayment()->getCreatedInvoice();

            if ($invoice && !$invoice->getEmailSent()) {
                $this->invoiceSender->send($invoice);
            }
        } catch (\Exception $e) {
            $this->logger->error('Zibal: could not send order emails', [
                'order' => $order->getIncrementId(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Adds a history comment without saving the (possibly half-updated) order.
     *
     * @param Order $order
     * @param \Magento\Framework\Phrase|string $comment
     */
    private function addHistoryComment(Order $order, $comment)
    {
        try {
            $history = $this->historyFactory->create();
            $history->setParentId($order->getId());
            $history->setEntityName('order');
            $history->setStatus($order->getStatus());
            $history->setComment((string)$comment);
            $history->setIsCustomerNotified(0);
            $history->setIsVisibleOnFront(0);

            $this->historyRepository->save($history);
        } catch (\Exception $e) {
            $this->logger->critical('Zibal: could not add order comment', [
                'order' => $order->getIncrementId(),
                'comment' => (string)$comment,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param string $trackId
     * @return \Magento\Framework\Phrase
     */
    private function contactSupportMessage($trackId)
    {
        return __('پرداخت انجام شد ولی در ثبت آن مشکلی پیش آمد. لطفاً با کد پیگیری %1 با پشتیبانی فروشگاه تماس بگیرید.', $trackId);
    }

    /**
     * Leaves the order open for an admin when the payment state cannot be determined.
     *
     * @param Order $order
     * @param string $trackId
     * @param string $reason
     * @return PaymentResult
     */
    private function review(Order $order, $trackId, $reason)
    {
        $this->logger->error('Zibal payment could not be verified', [
            'order' => $order->getIncrementId(),
            'trackId' => $trackId,
            'reason' => (string)$reason,
        ]);

        $order->addCommentToStatusHistory(
            __('تأیید پرداخت زیبال انجام نشد و نیاز به بررسی دستی دارد. کد پیگیری: %1، علت: %2', $trackId, $reason)
        );

        $this->orderRepository->save($order);

        return new PaymentResult(
            PaymentResult::REVIEW,
            __('وضعیت پرداخت مشخص نشد. اگر مبلغ از حساب شما کسر شده، با کد پیگیری %1 با پشتیبانی فروشگاه تماس بگیرید.', $trackId)
        );
    }

    /**
     * @param Order $order
     * @param string $message
     */
    private function cancel(Order $order, $message)
    {
        if ($order->canCancel()) {
            $order->cancel();
        }

        $order->addCommentToStatusHistory($message);

        $this->orderRepository->save($order);
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function isZibalOrder(Order $order)
    {
        $payment = $order->getPayment();

        return $payment && $payment->getMethod() === self::METHOD_CODE;
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function isAwaitingPayment(Order $order)
    {
        return in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true);
    }

    /**
     * Whether the trackId was issued by our own payment request for this order.
     *
     * @param Order $order
     * @param string $trackId
     * @return bool
     */
    private function ownsTrackId(Order $order, $trackId)
    {
        if ($trackId === '') {
            return false;
        }

        $trackIds = (array)$order->getPayment()->getAdditionalInformation(self::KEY_TRACK_IDS);

        return in_array($trackId, array_map('strval', $trackIds), true);
    }

    /**
     * @param string $trackId
     * @param array $data
     * @return array
     */
    private function rawDetails($trackId, array $data)
    {
        $details = ['trackId' => $trackId];

        foreach ($data as $key => $value) {
            if (is_scalar($value)) {
                $details[$key] = (string)$value;
            }
        }

        return $details;
    }
}
