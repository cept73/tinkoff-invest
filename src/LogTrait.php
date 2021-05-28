<?php

namespace jamesRUS52\TinkoffInvest;

use Exception;

trait LogTrait
{
    public function logString($errorMessage): void
    {
        /** @noinspection ForgottenDebugOutputInspection */
        error_log($errorMessage);
    }

    public function logException(Exception $ex): void
    {
        $CURL_STATUS_CODES = [
            401 => 'Authorization error',
            429 => 'Too Many Requests',
        ];

        if ($errorCode = $CURL_STATUS_CODES[$ex->getCode()] ?? null) {
            $this->logString("Error code: $errorCode");
        }
        $this->logString($ex->getMessage());
    }
}
