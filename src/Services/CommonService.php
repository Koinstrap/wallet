<?php

namespace Bavix\Wallet\Services;

use function app;
use Bavix\Wallet\Exceptions\AmountInvalid;
use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Bavix\Wallet\Interfaces\Wallet;
use Bavix\Wallet\Internal\Assembler\TransactionDtoAssembler;
use Bavix\Wallet\Internal\Assembler\TransferDtoAssembler;
use Bavix\Wallet\Internal\BookkeeperInterface;
use Bavix\Wallet\Internal\ConsistencyInterface;
use Bavix\Wallet\Internal\MathInterface;
use Bavix\Wallet\Internal\Service\AssistantService;
use Bavix\Wallet\Internal\Service\AtmService;
use Bavix\Wallet\Internal\Service\CastService;
use Bavix\Wallet\Internal\Service\PrepareService;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Transfer;
use Bavix\Wallet\Models\Wallet as WalletModel;
use Bavix\Wallet\Objects\Bring;
use function compact;
use function max;
use Throwable;

class CommonService
{
    private DbService $dbService;
    private LockService $lockService;
    private MathInterface $math;
    private AtmService $atmService;
    private CastService $castService;
    private WalletService $walletService;
    private AssistantService $assistantService;
    private PrepareService $prepareService;
    private BookkeeperInterface $bookkeeper;
    private ConsistencyInterface $consistency;
    private TransferDtoAssembler $transferDtoAssembler;
    private TransactionDtoAssembler $transactionDtoAssembler;

    public function __construct(
        DbService $dbService,
        LockService $lockService,
        MathInterface $math,
        CastService $castService,
        WalletService $walletService,
        BookkeeperInterface $bookkeeper,
        ConsistencyInterface $consistency,
        AssistantService $satisfyService,
        PrepareService $prepareService,
        TransferDtoAssembler $transferDtoAssembler,
        TransactionDtoAssembler $transactionDtoAssembler,
        AtmService $atmService
    ) {
        $this->dbService = $dbService;
        $this->lockService = $lockService;
        $this->math = $math;
        $this->atmService = $atmService;
        $this->castService = $castService;
        $this->walletService = $walletService;
        $this->bookkeeper = $bookkeeper;
        $this->consistency = $consistency;
        $this->assistantService = $satisfyService;
        $this->prepareService = $prepareService;
        $this->transferDtoAssembler = $transferDtoAssembler;
        $this->transactionDtoAssembler = $transactionDtoAssembler;
    }

    /**
     * @param int|string $amount
     *
     * @throws AmountInvalid
     * @throws BalanceIsEmpty
     * @throws InsufficientFunds
     * @throws Throwable
     */
    public function transfer(Wallet $from, Wallet $to, $amount, ?array $meta = null, string $status = Transfer::STATUS_TRANSFER): Transfer
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($from, $to, $amount, $meta, $status) {
            $discount = $this->walletService->discount($from, $to);
            $newAmount = max(0, $this->math->sub($amount, $discount));
            $fee = $this->walletService->fee($to, $newAmount);
            $this->consistency->checkPotential($from, $this->math->add($newAmount, $fee));

            return $this->forceTransfer($from, $to, $amount, $meta, $status);
        });
    }

    /**
     * @param int|string $amount
     *
     * @throws AmountInvalid
     * @throws Throwable
     */
    public function forceTransfer(Wallet $from, Wallet $to, $amount, ?array $meta = null, string $status = Transfer::STATUS_TRANSFER): Transfer
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($from, $to, $amount, $meta, $status) {
            $from = $this->walletService->getWallet($from);
            $discount = $this->walletService->discount($from, $to);
            $fee = $this->walletService->fee($to, $amount);

            $amount = max(0, $this->math->sub($amount, $discount));
            $withdraw = $this->forceWithdraw($from, $this->math->add($amount, $fee, $from->decimal_places), $meta);
            $deposit = $this->deposit($to, $amount, $meta);

            $transfers = $this->multiBrings([
                app(Bring::class)
                    ->setStatus($status)
                    ->setDeposit($deposit)
                    ->setWithdraw($withdraw)
                    ->setDiscount($discount)
                    ->setFrom($from)
                    ->setTo($to),
            ]);

            return current($transfers);
        });
    }

    /**
     * @param int|string $amount
     *
     * @throws AmountInvalid
     */
    public function forceWithdraw(Wallet $wallet, $amount, ?array $meta, bool $confirmed = true): Transaction
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($wallet, $amount, $meta, $confirmed) {
            return $this->operation($wallet, Transaction::TYPE_WITHDRAW, $amount, $meta, $confirmed);
        });
    }

    /**
     * @param int|string $amount
     *
     * @throws AmountInvalid
     */
    public function deposit(Wallet $wallet, $amount, ?array $meta, bool $confirmed = true): Transaction
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($wallet, $amount, $meta, $confirmed) {
            return $this->operation($wallet, Transaction::TYPE_DEPOSIT, $amount, $meta, $confirmed);
        });
    }

    /**
     * Create Bring with DB::transaction.
     *
     * @param Bring[] $brings
     *
     * @deprecated
     * @see AtmService::makeTransfers()
     */
    public function assemble(array $brings): array
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($brings) {
            $self = $this;

            return $this->dbService->transaction(static function () use ($self, $brings) {
                return $self->multiBrings($brings);
            });
        });
    }

    /**
     * Create Bring without DB::transaction.
     *
     * @param non-empty-array<mixed, Bring> $brings
     *
     * @deprecated
     * @see AtmService::makeTransfers()
     */
    public function multiBrings(array $brings): array
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($brings) {
            $objects = [];
            foreach ($brings as $bring) {
                $object = $this->transferDtoAssembler->create(
                    $bring->getDeposit()->getKey(),
                    $bring->getWithdraw()->getKey(),
                    $bring->getStatus(),
                    $this->castService->getModel($bring->getFrom()),
                    $this->castService->getModel($bring->getTo()),
                    $bring->getDiscount(),
                    $bring->getFee()
                );

                $objects[$object->getUuid()] = $object;
            }

            return $this->atmService->makeTransfers($objects);
        });
    }

    /**
     * @param int|string $amount
     *
     * @deprecated
     */
    public function addBalance(Wallet $wallet, $amount): bool
    {
        return $this->lockService->lock($this, __FUNCTION__, function () use ($wallet, $amount) {
            /** @var WalletModel $wallet */
            $walletObject = $this->walletService->getWallet($wallet);
            $balance = $this->bookkeeper->increase($walletObject, $amount);

            try {
                $result = $wallet->newQuery()
                    ->whereKey($wallet->getKey())
                    ->update(compact('balance'))
                ;
            } catch (Throwable $throwable) {
                $this->bookkeeper->sync($walletObject, $wallet->getAvailableBalance());

                throw $throwable;
            }

            if ($result) {
                $wallet->fill(compact('balance'))
                    ->syncOriginalAttributes('balance')
                ;
            } else {
                $this->bookkeeper->sync($walletObject, $wallet->getAvailableBalance());
            }

            return $result;
        });
    }

    protected function operation(Wallet $wallet, string $type, $amount, ?array $meta, bool $confirmed = true): Transaction
    {
        assert(in_array($type, [Transaction::TYPE_DEPOSIT, Transaction::TYPE_WITHDRAW], true));

        if ($type === Transaction::TYPE_DEPOSIT) {
            $dto = $this->prepareService->deposit($wallet, (string) $amount, $meta, $confirmed);
        } else {
            $dto = $this->prepareService->withdraw($wallet, (string) $amount, $meta, $confirmed);
        }

        $transactions = $this->atmService->makeTransactions([$dto]); // q1
        $object = $this->castService->getWallet($wallet);
        $totals = $this->assistantService->getSums([$dto]);
        $total = (string) ($totals[$object->getKey()] ?? 0);

        // optimize queries
        if ($this->math->compare($total, 0) !== 0) {
            $balance = $this->bookkeeper->increase($object, $total);

            $object->newQuery()->update(compact('balance')); // ?q2
            $object->fill(compact('balance'))->syncOriginalAttribute('balance');
        }

        return current($transactions);
    }
}
