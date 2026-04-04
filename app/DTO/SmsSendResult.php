<?php

namespace App\DTO;

class SmsSendResult
{
    /** @var bool */
    public $success;

    /** @var string|null */
    public $providerMessageId;

    /** @var string|null */
    public $error;

    /** @var string|null */
    public $errorLayer;

    /** @var array<string, mixed> */
    public $raw;

    /**
     * @param bool $success
     * @param string|null $providerMessageId
     * @param string|null $error
     * @param string|null $errorLayer
     * @param array<string, mixed> $raw
     */
    public function __construct(
        bool $success,
        ?string $providerMessageId = null,
        ?string $error = null,
        ?string $errorLayer = null,
        array $raw = []
    )
    {
        $this->success = $success;
        $this->providerMessageId = $providerMessageId;
        $this->error = $error;
        $this->errorLayer = $errorLayer;
        $this->raw = $raw;
    }

    /**
     * @param string|null $providerMessageId
     * @param array<string, mixed> $raw
     * @return self
     */
    public static function success(?string $providerMessageId = null, array $raw = []): self
    {
        return new self(true, $providerMessageId, null, null, $raw);
    }

    /**
     * @param string|null $error
     * @param string|null $errorLayer
     * @param array<string, mixed> $raw
     * @return self
     */
    public static function failed(?string $error = null, array $raw = [], ?string $errorLayer = null): self
    {
        return new self(false, null, $error, $errorLayer, $raw);
    }
}
