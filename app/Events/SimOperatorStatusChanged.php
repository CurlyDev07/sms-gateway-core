<?php

namespace App\Events;

class SimOperatorStatusChanged
{
    /**
     * @var int
     */
    public $simId;

    /**
     * @var int
     */
    public $companyId;

    /**
     * @var string
     */
    public $oldStatus;

    /**
     * @var string
     */
    public $newStatus;

    /**
     * @param int $simId
     * @param int $companyId
     * @param string $oldStatus
     * @param string $newStatus
     */
    public function __construct(int $simId, int $companyId, string $oldStatus, string $newStatus)
    {
        $this->simId = $simId;
        $this->companyId = $companyId;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }
}
