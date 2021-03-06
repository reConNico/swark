<?php

namespace Swark\Service;

use Exception;
use Monolog\Logger;
use RuntimeException;
use Shopware\Components\Model\ModelManager;
use Shopware\Models\Order\Order;
use Shopware\Models\Order\Status;
use Swark\Helper\OrderHelper;
use Swark\Helper\PluginHelper;

/**
 * Class OrderService
 */
class OrderService
{
    /**
     * @var ModelManager
     */
    private $models;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var PluginHelper
     */
    private $pluginHelper;

    /**
     * @var TransactionService
     */
    private $transactionService;

    /**
     * @var array
     */
    private $pluginConfig;

    /**
     * @var Logger
     */
    private $errorLogger;

    /**
     * @var Logger
     */
    private $processLogger;

    /**
     * @var ExchangeService
     */
    private $exchangeService;

    /**
     * @param ModelManager       $models
     * @param OrderHelper        $orderHelper
     * @param PluginHelper       $pluginHelper
     * @param TransactionService $transactionService
     * @param array              $pluginConfig
     * @param Logger             $errorLogger
     * @param Logger             $processLogger
     * @param ExchangeService    $exchangeService
     */
    public function __construct(
        ModelManager $models,
        OrderHelper $orderHelper,
        PluginHelper $pluginHelper,
        TransactionService $transactionService,
        array $pluginConfig,
        Logger $errorLogger,
        Logger $processLogger,
        ExchangeService $exchangeService
    ) {
        $this->models = $models;
        $this->orderHelper = $orderHelper;
        $this->pluginHelper = $pluginHelper;
        $this->transactionService = $transactionService;
        $this->pluginConfig = $pluginConfig;
        $this->errorLogger = $errorLogger;
        $this->processLogger = $processLogger;
        $this->exchangeService = $exchangeService;
    }

    /**
     * Function to check the transactions
     *
     * @throws RuntimeException
     * @throws Exception
     */
    public function checkTransactions(): bool
    {
        $orders = $this->orderHelper->getOpenOrders();

        if (!$orders) {
            $this->processLogger->info(
                'No open orders found.'
            );

            return false;
        }

        /** @var Order $order */
        foreach ($orders as $order) {
            $this->processLogger->info(
                'Processing Order [' . $order->getNumber() . ']'
            );

            $attributes = $this->orderHelper->getOrderAttributes($order->getAttribute());

            $transaction = $this->transactionService->getTransaction(
                $attributes['swarkRecipientAddress'],
                $attributes['swarkVendorField']
            );

            if ($transaction) {
                if ($attributes['swarkTransactionId'] === '') {
                    $this->updateOrderTransactionId($order->getAttribute(), $transaction->getId(), $order->getNumber());
                }

                $transactionAmount = $transaction->getAmount() / 100000000;

                if (!$this->checkOrderAmount($transactionAmount, $attributes['swarkArkAmount'])) {
                    $this->processLogger->info(
                        'Order [' . $order->getNumber() . '] received amount is too low: ' . $transactionAmount . '. Needed: ' . $attributes['swarkArkAmount']
                    );

                    /** @var Status $paymentStatus */
                    $paymentStatus = $this->models->getRepository(Status::class)->find(Status::PAYMENT_STATE_PARTIALLY_PAID);

                    $this->updateOrderPaymentStatus($order, $paymentStatus);
                    continue;
                }

                if (!$this->checkConfirmations($transaction->getConfirmations())) {
                    $this->processLogger->info(
                        'Order [' . $order->getNumber() . '] need more confirmations. Currently: ' . $transaction->getConfirmations()
                    );
                    continue;
                }

                $this->updateOrderPaymentStatus($order, $this->orderHelper->getPaymentStatus());

                continue;
            }

            $this->processLogger->warning(
                'No transaction for Order [' . $order->getNumber() . '] found!'
            );
        }

        return true;
    }

    /**
     * @param int $orderNumber
     *
     * @throws Exception
     *
     * @return bool
     */
    public function processOrder(int $orderNumber): bool
    {
        /** @var Order $order */
        $order = $this->orderHelper->getOrder($orderNumber);
        $attributes = $order->getAttribute();

        $this->updateOrderAmount($order, $attributes);
        $this->updateOrderRecipientAddress($attributes, $orderNumber);
        $this->updateOrderVendorField($attributes, $orderNumber);
        $this->updateOrderTransactionId($attributes, '', $orderNumber);

        return true;
    }

    /**
     * @param int $paymentId
     *
     * @return bool
     */
    public function checkPayment(int $paymentId): bool
    {
        return $this->orderHelper->getPaymentObject()->getId() === $paymentId;
    }

    /**
     * @param \Shopware\Models\Attribute\Order $attributes
     * @param string                           $transactionId
     * @param int                              $orderNumber
     *
     * @throws Exception
     */
    private function updateOrderTransactionId(\Shopware\Models\Attribute\Order $attributes, string $transactionId, int $orderNumber): void
    {
        try {
            $attributes->setSwarkTransactionId($transactionId);

            $this->models->persist($attributes);
            $this->models->flush($attributes);
        } catch (Exception $e) {
            $this->errorLogger->error('Order transaction id could not be updated!', $e->getTrace());
            throw $e;
        }

        $this->processLogger->info(
            'Updated transaction id to [' . $transactionId . '] from order [' . $orderNumber . ']'
        );
    }

    /**
     * @param Order                            $order
     * @param \Shopware\Models\Attribute\Order $attributes
     *
     * @throws Exception
     */
    private function updateOrderAmount(Order $order, \Shopware\Models\Attribute\Order $attributes): void
    {
        try {
            $currency = $order->getCurrency();
            $amount = $order->getInvoiceAmount();

            if ($currency !== 'ARK') {
                $defaultCurrency = $this->orderHelper->getDefaultCurrency();

                if ($defaultCurrency->getName() === $currency) {
                    $amount = $this->calculatePrice($amount, $this->orderHelper->getArkCurrencyFactor());
                } else {
                    $factor = $this->exchangeService->getExchangeRate($currency);
                    $amount = $this->calculatePrice($amount, $factor);
                }
            }

            $attributes->setSwarkArkAmount($amount);

            $this->models->persist($attributes);
            $this->models->flush($attributes);
        } catch (Exception $e) {
            $this->errorLogger->error('Order amount could not be updated!', $e->getTrace());
            throw $e;
        }

        $this->processLogger->info(
            'Updated amount to [' . $amount . '] for order [' . $order->getNumber() . ']'
        );
    }

    /**
     * @param \Shopware\Models\Attribute\Order $attributes
     * @param int                              $orderNumber
     *
     * @throws Exception
     */
    private function updateOrderRecipientAddress(\Shopware\Models\Attribute\Order $attributes, int $orderNumber): void
    {
        try {
            $recipient = $this->pluginHelper->getRandomWallet();

            $attributes->setSwarkRecipientAddress($recipient);

            $this->models->persist($attributes);
            $this->models->flush($attributes);
        } catch (Exception $e) {
            $this->errorLogger->error('Order recipient address could not be updated!', $e->getTrace());
            throw $e;
        }

        $this->processLogger->info(
            'Updated recipient address to [' . $recipient . '] from order [' . $orderNumber . ']'
        );
    }

    /**
     * @param \Shopware\Models\Attribute\Order $attributes
     * @param int                              $orderNumber
     *
     * @throws Exception
     */
    private function updateOrderVendorField(\Shopware\Models\Attribute\Order $attributes, int $orderNumber): void
    {
        try {
            $vendorField = $this->orderHelper->getVendorFieldLayout($orderNumber);

            $attributes->setSwarkVendorField($vendorField);

            $this->models->persist($attributes);
            $this->models->flush($attributes);
        } catch (Exception $e) {
            $this->errorLogger->error('Order vendorField could not be updated!', $e->getTrace());
            throw $e;
        }

        $this->processLogger->info(
            'Updated vendorField to [' . $vendorField . '] from order [' . $orderNumber . ']'
        );
    }

    /**
     * @param Order  $order
     * @param Status $paymentStatus
     *
     * @throws Exception
     *
     * @return bool
     */
    private function updateOrderPaymentStatus(Order $order, Status $paymentStatus): bool
    {
        try {
            $order->setPaymentStatus($paymentStatus);
            $this->models->persist($order);
            $this->models->flush($order);

            if ($this->pluginConfig['sendMail']) {
                $orderModule = Shopware()->Modules()->Order();

                $mail = $orderModule->createStatusMail($order->getId(), $paymentStatus->getId());

                if ($mail) {
                    try {
                        $orderModule->sendStatusMail($mail);
                    } catch (Exception $e) {
                        $this->errorLogger->error('Order [' . $order->getNumber() . '] could not send out order status mail', $e->getTrace());

                        return false;
                    }
                }
            }
        } catch (Exception $e) {
            $this->errorLogger->error('Order payment status could not be updated!', $e->getTrace());
            throw $e;
        }

        $this->processLogger->info(
            'Updated order [' . $order->getNumber() . '] and set payment status to [' . $paymentStatus->getName() . ']'
        );

        return true;
    }

    /**
     * @param int $confirmations
     *
     * @return bool
     */
    private function checkConfirmations(int $confirmations): bool
    {
        return $confirmations >= $this->pluginConfig['confirmations'];
    }

    /**
     * @param float $transactionAmount
     * @param float $orderAmount
     *
     * @return bool
     */
    private function checkOrderAmount(float $transactionAmount, float $orderAmount): bool
    {
        return ($transactionAmount >= $orderAmount) ? true : false;
    }

    /**
     * @param float $price
     * @param float $factor
     *
     * @return float
     */
    private function calculatePrice(float $price, float $factor)
    {
        return $price * $factor;
    }
}
