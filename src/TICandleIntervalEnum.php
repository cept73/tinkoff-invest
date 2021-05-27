<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace jamesRUS52\TinkoffInvest;

/**
 * Description of TICandleIntervalEnum
 *
 * @author james
 */
abstract class TICandleIntervalEnum
{
    //put your code here
    const MIN1 = "1min";
    const MIN2 = "2min";
    const MIN3 = "3min";
    const MIN5 = "5min";
    const MIN10 = "10min";
    const MIN15 = "15min";
    const MIN30 = "30min";
    const HOUR1 = "hour";
    const HOUR2 = "2hour";
    const HOUR4 = "4hour";
    const DAY = "day";
    const WEEK = "week";
    const MONTH = "month";
    
    /**
     * 
     * @param string $interval
     * @return TICandleIntervalEnum
     */
    public static function getInterval($interval)
    {
        switch ($interval)
        {
            case "1min" : return TICandleIntervalEnum::MIN1;
            case "2min" : return TICandleIntervalEnum::MIN2;
            case "3min" : return TICandleIntervalEnum::MIN3;
            case "5min" : return TICandleIntervalEnum::MIN5;
            case "10min": return TICandleIntervalEnum::MIN10;
            case "15min": return TICandleIntervalEnum::MIN15;
            case "30min": return TICandleIntervalEnum::MIN30;
            case "hour" : return TICandleIntervalEnum::HOUR1;
            case "2hour": return TICandleIntervalEnum::HOUR2;
            case "4hour": return TICandleIntervalEnum::HOUR4;
            case "day"  : return TICandleIntervalEnum::DAY;
            case "week" : return TICandleIntervalEnum::WEEK;
            case "month": return TICandleIntervalEnum::MONTH;
        }

        return null;
    }
}
