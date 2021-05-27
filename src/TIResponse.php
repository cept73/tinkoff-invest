<?php

namespace jamesRUS52\TinkoffInvest;

use Exception;
use stdClass;

/**
 * Class TIResponse
 * @package jamesRUS52\TinkoffInvest
 */
class TIResponse
{
    use LogTrait;

    /**
     * @var string
     */
    private $trackingId;

    /**
     * @var ?stdClass
     */
    private $payload;

    /**
     * @var string
     */
    private $status;

    /**
     * TIResponse constructor.
     * @param string $curlResponse
     */
    public function __construct(string $curlResponse)
    {
        try {
            if (empty($curlResponse)) {
                throw new Exception('Response is null');
            }
            $result = json_decode($curlResponse, true);
            if (!$result || empty($result->trackingId) || empty($result->payload) || empty($result->status)) {
                $this->payload = $result->payload;
                $this->trackingId = $result->trackingId;
                $this->status = $result->status;
            } else {
                throw new TIException('Required fields are empty ' . json_encode($this));
            }
            if ($this->status === 'Error') {
                throw new TIException($this->payload->message . ' [' . $this->payload->code . ']');
            }
        }
        catch (Exception $ex) {
            $this->logException($ex);
        }
    }

    /**
     * @return stdClass
     */
    public function getPayload(): ?stdClass
    {
        return $this->payload;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }
}
