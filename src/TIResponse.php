<?php

namespace jamesRUS52\TinkoffInvest;

use app\models\LogTrait;
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
     * @var stdClass
     */
    private $payload;

    /**
     * @var string
     */
    private $status;

    /**
     * TIResponse constructor.
     * @param string $curlResponse
     * @param $curlStatusCode
     * @throws TIException
     */
    public function __construct($curlResponse, $curlStatusCode)
    {
        try {
            if (empty($curlResponse)) {
                throw new \Exception("Response is null");
            }
            $result = json_decode($curlResponse);
            if (isset($result->trackingId) && isset($result->payload) && isset($result->status)) {
                $this->payload = $result->payload;
                $this->trackingId = $result->trackingId;
                $this->status = $result->status;
            } else {
                throw new TIException('Required fields are empty');
            }
            if ($this->status == 'Error') {
                throw new TIException($this->payload->message . ' [' . $this->payload->code . ']');
            }
        }
        catch (Exception $ex) {
            $this->setLastErrorByException($ex);
        }
    }

    /**
     * @return stdClass
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }
}
