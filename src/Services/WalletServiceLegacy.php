<?php

declare(strict_types=1);

namespace Bavix\Wallet\Services;

use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;
use Bavix\Wallet\Internal\Exceptions\LockProviderNotFoundException;
use Bavix\Wallet\Internal\Exceptions\TransactionFailedException;
use Bavix\Wallet\Internal\Service\MathServiceInterface;
use Bavix\Wallet\Models\Wallet as WalletModel;
use Illuminate\Database\RecordsNotFoundException;

/** @deprecated */
final class WalletServiceLegacy
{
    private MathServiceInterface $mathService;
    private RegulatorServiceInterface $regulatorService;
    private AtomicServiceInterface $atomicService;

    public function __construct(
        MathServiceInterface $mathService,
        RegulatorServiceInterface $regulatorService,
        AtomicServiceInterface $atomicService
    ) {
        $this->mathService = $mathService;
        $this->regulatorService = $regulatorService;
        $this->atomicService = $atomicService;
    }

    /**
     * @throws LockProviderNotFoundException
     * @throws RecordsNotFoundException
     * @throws TransactionFailedException
     * @throws ExceptionInterface
     *
     * @see WalletModel::refreshBalance()
     * @deprecated
     */
    public function refresh(WalletModel $wallet): bool
    {
        return $this->atomicService->block($wallet, function () use ($wallet) {
            $whatIs = $wallet->balance;
            $balance = $wallet->getAvailableBalance();
            if ($this->mathService->compare($whatIs, $balance) === 0) {
                return true;
            }

            $wallet->balance = (string) $balance;

            return $wallet->save() && $this->regulatorService->sync($wallet, $balance);
        });
    }
}
