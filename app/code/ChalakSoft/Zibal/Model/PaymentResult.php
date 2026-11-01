<?php

namespace ChalakSoft\Zibal\Model;

/**
 * Outcome of handling a customer's return from the gateway.
 */
class PaymentResult
{
    /** Payment verified and invoiced */
    const PAID = 'paid';

    /** Customer did not pay; the order was cancelled */
    const FAILED = 'failed';

    /** Money may have been taken; the order is left for manual review */
    const REVIEW = 'review';

    /** @var string */
    private $outcome;

    /** @var string */
    private $message;

    /**
     * @param string $outcome
     * @param string $message
     */
    public function __construct($outcome, $message = '')
    {
        $this->outcome = $outcome;
        $this->message = (string)$message;
    }

    /**
     * @return string
     */
    public function getOutcome()
    {
        return $this->outcome;
    }

    /**
     * @return bool
     */
    public function isPaid()
    {
        return $this->outcome === self::PAID;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }
}
