<?php

namespace App\Contracts;

use App\DTO\SmsSendResult;

interface SmsSenderInterface
{
    /**
     * Send SMS through configured transport adapter.
     *
     * @param int $simId
     * @param string $phone
     * @param string $message
     * @param array<string, mixed> $meta
     * @return \App\DTO\SmsSendResult
     */
    public function send(int $simId, string $phone, string $message, array $meta = []): SmsSendResult;
}
