<?php

declare(strict_types=1);

namespace Bavix\Wallet\Services;

use Bavix\Wallet\Interfaces\CartInterface;
use Bavix\Wallet\Interfaces\ProductInterface;
use Bavix\Wallet\Internal\Dto\TransactionDtoInterface;
use Bavix\Wallet\Internal\Dto\TransferDtoInterface;
use Bavix\Wallet\Internal\Service\MathServiceInterface;

final class AssistantService implements AssistantServiceInterface
{
    public function __construct(
        private MathServiceInterface $mathService
    ) {
    }

    /**
     * @param non-empty-array<array-key, TransactionDtoInterface|TransferDtoInterface> $objects
     *
     * @return non-empty-array<array-key, string>
     */
    public function getUuids(array $objects): array
    {
        return array_map(static fn ($object): string => $object->getUuid(), $objects);
    }

    /**
     * @param non-empty-array<array-key, TransactionDtoInterface> $transactions
     *
     * @return array<int, string>
     */
    public function getSums(array $transactions): array
    {
        $amounts = [];
        foreach ($transactions as $transaction) {
            if ($transaction->isConfirmed()) {
                $amounts[$transaction->getWalletId()] = $this->mathService->add(
                    $amounts[$transaction->getWalletId()] ?? 0,
                    $transaction->getAmount()
                );
            }
        }

        return array_filter($amounts, fn (string $amount): bool => $this->mathService->compare($amount, 0) !== 0);
    }

    public function getMeta(CartInterface $cart, ProductInterface $product): ?array
    {
        $metaCart = $cart->getBasketDto()
            ->meta()
        ;
        $metaProduct = $product->getMetaProduct();

        if ($metaProduct !== null) {
            return array_merge($metaCart, $metaProduct);
        }

        if ($metaCart !== []) {
            return $metaCart;
        }

        return null;
    }
}
