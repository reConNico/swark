<?php

namespace Swark\Services;

use ArkEcosystem\Client\ConnectionManager;
use Swark\Structs\Transaction;
use Swark\Structs\Timestamp;

/**
 * Class TransactionService
 */
class TransactionService
{
    /**
     * @var ConnectionManager
     */
    private $connectionManager;

    /**
     * @var LoggerService
     */
    private $loggerService;

    /**
     * TransactionService constructor.
     *
     * @param ConnectionService $connectionService
     * @param LoggerService     $loggerService
     */
    public function __construct(
        ConnectionService $connectionService,
        LoggerService $loggerService
    ) {
        $this->connectionManager = $connectionService->getConnectionManager();
        $this->loggerService = $loggerService;
    }

    /**
     * @param string $wallet
     * @param string $vendorField
     *
     * @return Transaction
     */
    public function getTransaction(string $wallet, string $vendorField): Transaction
    {
        $transaction = $this->getTransactionByVendorField($wallet, $vendorField);

        if ($transaction) {
            return new Transaction(
                $transaction['id'],
                $transaction['amount'],
                $transaction['recipient'],
                $transaction['vendorField'],
                $transaction['confirmations'],
                new Timestamp(
                    $transaction['timestamp']['epoch'],
                    $transaction['timestamp']['unix'],
                    $transaction['timestamp']['human']
                )
            );
        }

        return null;
    }

    /**
     * @param string $wallet
     * @param string $vendorField
     *
     * @return array
     */
    protected function getTransactionByVendorField(string $wallet, string $vendorField): array
    {
        try {
            $response = $this->connectionManager
                ->connection('main')->transactions()->search([
                    'recipientId' => $wallet,
                    'vendorFieldHex' => \implode(\unpack('H*', $vendorField))
                ]);
        } catch (\Exception $e) {
            $this->loggerService->warning('main node api was not executable', $e);
            try {
                $response = $this->connectionManager
                    ->connection('backup')->transactions()->search([
                        'recipientId' => $wallet,
                        'vendorFieldHex' => \implode(\unpack('H*', $vendorField))
                    ]);
            } catch (\Exception $e) {
                $this->loggerService->error('backup node api was not executable', $e);
            }
        }

        // TODO: Also check multiple transactions
        return $response['data'][0] ?: [];
    }
}