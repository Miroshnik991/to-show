<?php

namespace App;

class ServiceResult extends Result
{
    const int PAYMENT_FAIL = 0;
    const int MONEY_COMING = 1;
    const int MONEY_LEAVING = 2;
    const int TRANSACTION_STARTED = 3;
    protected int|null $operationType = null;
    protected array $psData = [];
    protected string|null $transaction = null;

    public function getOperationType(): ?int
    {
        return $this->operationType;
    }

    public function setOperationType(int $operationType): static
    {
        $this->operationType = $operationType;

        return $this;
    }

    public function getPsData(): array
    {
        return $this->psData;
    }

    public function setPsData(array $psData): static
    {
        $this->psData = $psData;

        return $this;
    }

    public function getTransaction(): ?string
    {
        return $this->transaction;
    }

    /**
     * @param string $transaction
     *
     * @return $this
     */
    public function setTransaction(string $transaction): static
    {
        $this->transaction = $transaction;

        return $this;
    }
}
