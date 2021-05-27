<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace jamesRUS52\TinkoffInvest;

/**
 * Description of TISite
 *
 * @author james
 */
abstract class TICurrencyEnum
{
    //put your code here
    const RUB = "RUB";
    const USD = "USD";
    const EUR = "EUR";
    const GBP = "GBP";
    const HKD = "HKD";
    const CHF = "CHF";
    const JPY = "JPY";
    const CNY = "CNY";
    const TRL = "TRY";

    /**
     *
     * @param string $currency
     * @return TICurrencyEnum
     */
    public static function getCurrency($currency)
    {
        switch ($currency) {
            case self::RUB: return TICurrencyEnum::RUB;
            case self::USD: return TICurrencyEnum::USD;
            case self::EUR: return TICurrencyEnum::EUR;
            case self::GBP: return TICurrencyEnum::GBP;
            case self::HKD: return TICurrencyEnum::HKD;
            case self::CHF: return TICurrencyEnum::CHF;
            case self::JPY: return TICurrencyEnum::JPY;
            case self::CNY: return TICurrencyEnum::CNY;
            case self::TRY: return TICurrencyEnum::TRL;
        }

        return null;
    }
}
