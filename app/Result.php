<?php

namespace App;

class Result
{
    /** @var bool */
    protected bool $isSuccess = true;

    public function __construct(protected array $data = [])
    {
    }

    /**
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array $data
     */
    public function setData(array $data)
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Returns the result status.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->isSuccess;
    }
}
