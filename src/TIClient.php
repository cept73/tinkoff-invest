<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace jamesRUS52\TinkoffInvest;

use GuzzleHttp\Handler\CurlMultiHandler;
use WebSocket\BadOpcodeException;
use WebSocket\Client;
use Exception;
use DateTime;
use DateInterval;

/**
 * Description of TIClient
 *
 * @author james
 */
class TIClient
{
    use ResponseTrait;

    public const CANDLE_SUBSCRIBE   = 'subscribe';
    public const CANDLE_UNSUBSCRIBE = 'unsubscribe';

    //put your code here
    /**
     * @var string
     */
    private $token;

    /**
     * @var TISiteEnum
     */
    private $url;

    /**
     *
     * @var Client
     */
    private $wsClient;

    /**
     * @var bool
     */
    private $startGetting = false;

    /**
     * @var int
     */
    private $response_now = 0;

    /**
     * @var
     */
    private $response_start_time;

    private $brokerAccountId = null;

    private $debug = false;

    private $ignore_ssl_peer_verification = false;

    /**
     * @var \CurlMultiHandle store queue of async handles
     */
    private $curl_multi_exec_queue;

    /**
     * @var array list of async CURLs
     */
    private $async_curls_list = [];

    /**
     *
     * @param string $token token from tinkoff.ru for specific site
     * @param TISiteEnum $site site name (sandbox or real exchange)
     * @param null $account
     * @throws TIException
     */
    function __construct($token, $site, $account = null)
    {
        $this->token = $token;
        $this->url = $site;
        $this->brokerAccountId = $account;
        $this->wsConnect();
    }

    /**
     * @return null
     */
    public function getBrokerAccount()
    {
        return $this->brokerAccountId;
    }

    /**
     * @param null $brokerAccountId
     */
    public function setBrokerAccount($account)
    {
        $this->brokerAccountId = $account;
    }



    /**
     * Удаление всех позиций в песочнице
     *
     * @param null $accountId
     * @return string status
     * @throws TIException
     */
    public function sbClear()
    {
        $response = $this->sendRequest("/sandbox/clear",
            "POST",
            ["brokerAccountId" => $this->brokerAccountId]
        );
        return $response->getStatus();
    }

    /** Remove sandbox Account
     * @param null $accountId
     * @return string
     * @throws TIException
     */
    public function sbRemove()
    {
        $response = $this->sendRequest("/sandbox/remove",
            "POST",
            ["brokerAccountId" => $this->brokerAccountId]
        );
        return $response->getStatus();
    }

    /**
     * Регистрация клиента в sandbox
     *
     * @return TIAccount
     * @throws TIException
     */
    public function sbRegister()
    {
        $response = $this->sendRequest("/sandbox/register", "POST");
        return new TIAccount($response->getPayload()->brokerAccountType,
                            $response->getPayload()->brokerAccountId);

    }

    /**
     * Выставление баланса по инструментным позициям
     *
     * @param double $balance
     * @param string $figi
     *
     * @return string status
     * @throws TIException
     */
    public function sbPositionBalance($balance, $figi)
    {
        $request = ["figi" => $figi, "balance" => $balance];
        $request_body = json_encode($request, JSON_NUMERIC_CHECK);
        $response = $this->sendRequest(
            "/sandbox/positions/balance",
            "POST",
            ["brokerAccountId" => $this->brokerAccountId],
            $request_body
        );
        return $response->getStatus();
    }

    /**
     * Выставление баланса по инструментным позициям
     *
     * @param double $balance
     * @param string $currency
     *
     * @param null $accountId
     * @return string status
     * @throws TIException
     */
    public function sbCurrencyBalance($balance, $currency = TICurrencyEnum::RUB)
    {
        $request = ["currency" => $currency, "balance" => $balance];
        $request_body = json_encode($request, JSON_NUMERIC_CHECK);
        $response = $this->sendRequest(
            "/sandbox/currencies/balance",
            "POST",
            ["brokerAccountId" => $this->brokerAccountId],
            $request_body
        );
        return $response->getStatus();
    }

    /**
     * Получение списка акций
     *
     * @param array $tickers Ticker Filter
     *
     * @return TIInstrument[] Список инструментов
     * @throws TIException
     */
    public function getStocks($tickers = null)
    {
        $response = $this->sendRequest("/market/stocks", "GET");
        return $this->setUpLists($response, $tickers);
    }

    /**
     * Получение списка облигаций
     *
     * @param array $tickers filter tickers
     *
     * @return TIInstrument[]
     * @throws TIException
     */
    public function getBonds($tickers = null)
    {
        $response = $this->sendRequest("/market/bonds", "GET");
        return $this->setUpLists($response, $tickers);
    }

    /**
     * Получение списка ETF
     *
     * @param array $tickers filter ticker
     *
     * @return TIInstrument[]
     * @throws TIException
     */
    public function getEtfs($tickers = null)
    {
        $response = $this->sendRequest("/market/etfs", "GET");
        return $this->setUpLists($response, $tickers);
    }

    /**
     * Получение списка валют
     *
     * @param array $tickers filter ticker
     *
     * @return TIInstrument[]
     * @throws TIException
     */
    public function getCurrencies($tickers = null)
    {
        $currencies = [];
        $response = $this->sendRequest("/market/currencies", "GET");

        foreach ($response->getPayload()->instruments as $instrument) {
            if ($tickers === null || in_array($instrument->ticker, $tickers)) {
                $currency = TICurrencyEnum::getCurrency($instrument->currency);

                $curr = new TIInstrument(
                    $instrument->figi,
                    $instrument->ticker,
                    null,
                    $instrument->minPriceIncrement,
                    $instrument->lot,
                    $currency,
                    $instrument->name,
                    $instrument->type
                );
                $currencies[] = $curr;
            }
        }
        return $currencies;
    }


    /**
     * Получение инструмента по тикеру
     *
     * @param string $ticker
     *
     * @return TIInstrument
     * @throws TIException
     */
    public function getInstrumentByTicker($ticker)
    {
        $response = $this->sendRequest(
            "/market/search/by-ticker",
            "GET",
            ["ticker" => $ticker]
        );

        if ($response->getPayload()->total === 0) {
            throw new TIException("Cannot find instrument by ticker {$ticker}");
        }

        $currency = TICurrencyEnum::getCurrency(
            $response->getPayload()->instruments[0]->currency
        );
        $isin = (isset($response->getPayload()->instruments[0]->isin)) ? $response->getPayload(
        )->instruments[0]->isin : null;
        return new TIInstrument(
            $response->getPayload()->instruments[0]->figi,
            $response->getPayload()->instruments[0]->ticker,
            $isin,
            $response->getPayload()->instruments[0]->minPriceIncrement,
            $response->getPayload()->instruments[0]->lot,
            $currency,
            $response->getPayload()->instruments[0]->name,
            $response->getPayload()->instruments[0]->type
        );
    }

    /**
     * Получение инструмента по FIGI
     *
     * @param string $figi
     *
     * @return TIInstrument
     * @throws TIException
     */
    public function getInstrumentByFigi($figi)
    {
        $response = $this->sendRequest(
            "/market/search/by-figi",
            "GET",
            ["figi" => $figi]
        );

        $currency = TICurrencyEnum::getCurrency($response->getPayload()->currency);

        $isin = (isset($response->getPayload()->isin)) ? $response->getPayload()->isin : null;
        return new TIInstrument(
            $response->getPayload()->figi,
            $response->getPayload()->ticker,
            $isin,
            $response->getPayload()->minPriceIncrement,
            $response->getPayload()->lot,
            $currency,
            $response->getPayload()->name,
            $response->getPayload()->type
        );
    }

    /**
     * Получение исторического стакана
     *
     * @param string $figi
     * @param int $depth
     * @return TIOrderBook
     * @throws TIException
     */
    public function getHistoryOrderBook($figi, $depth = 1)
    {
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > 20) {
            $depth = 20;
        }
        $response = $this->sendRequest(
            "/market/orderbook",
            "GET",
            [
                'figi' => $figi,
                'depth' => $depth,
            ]
        );

        return $this->setUpOrderBook($response->getPayload());
    }

    /**
     * Получение исторических свечей
     * default figi = AAPL
     * default from 7Days ago
     * default to now
     * default interval 15 min
     *
     * @param string $figi
     * @param \DateTime $from
     * @param \DateTime $to
     * @param string $interval
     * @return TICandle[]
     * @throws TIException
     */
    public function getHistoryCandles($figi, $from = null, $to = null, $interval = null)
    {
        $fromDate = new DateTime();
        $fromDate->sub(new DateInterval('P7D'));
        $toDate = new DateTime();

        $response = $this->sendRequest(
            "/market/candles",
            "GET",
            [
                'figi' => $figi,
                'from' => empty($from) ? $fromDate->format('c') : $from->format('c'),
                'to' => empty($to) ? $toDate->format('c') : $to->format('c'),
                'interval' => empty($interval) ? TIIntervalEnum::MIN15 : $interval
            ]
        );
        $array = [];
        foreach ($response->getPayload()->candles as $candle) {
            $array [] = $this->setUpCandle($candle);
        }
        return $array;
    }


    /**
     * Получение текущих аккаунтов пользователя
     *
     * @return TIAccount[]
     * @throws TIException
     */
    public function getAccounts()
    {
        $response = $this->sendRequest("/user/accounts", "GET");
        $accounts = [];
        foreach ($response->getPayload()->accounts as $index => $account) {
            $accounts [] = new TIAccount(
                $account->brokerAccountType,
                $account->brokerAccountId
            );
        }
        return $accounts;
    }


    /**
     * Получить портфель клиента
     *
     * @return TIPortfolio
     * @throws TIException
     */
    public function getPortfolio()
    {
        $currs = [];
        $params = [
                    'brokerAccountId' => $this->brokerAccountId
                    ];

        $response = $this->sendRequest(
            "/portfolio/currencies",
            "GET",
            $params
        );

        foreach ($response->getPayload()->currencies as $currency) {
            $ticurrency = TICurrencyEnum::getCurrency($currency->currency);
            $blocked = (isset($currency->blocked)) ? $currency->blocked : 0;

            $curr = new TIPortfolioCurrency(
                $currency->balance,
                $ticurrency,
                $blocked
            );
            $currs[] = $curr;
        }

        $instrs = [];
        $response = $this->sendRequest("/portfolio", "GET", $params);

        foreach ($response->getPayload()->positions as $position) {
            $expectedYeildCurrency = null;
            $expectedYeildValue = null;
            if (isset($position->expectedYield)) {
                $expectedYeildCurrency = TICurrencyEnum::getCurrency(
                    $position->expectedYield->currency
                );
                $expectedYeildValue = $position->expectedYield->value;
            }

            $isin = (isset($position->isin)) ? $position->isin : null;
            $blocked = (isset($position->blocked)) ? $position->blocked : 0;
            $averagePositionPrice = (isset($position->averagePositionPrice)) ? $position->averagePositionPrice : null;
            $averagePositionPriceNoNkd = (isset($position->averagePositionPriceNoNkd)) ? $position->averagePositionPriceNoNkd : null;

            $instr = new TIPortfolioInstrument(
                $position->figi,
                $position->ticker,
                $isin,
                $position->instrumentType,
                $position->balance,
                $blocked,
                $position->lots,
                $expectedYeildValue,
                $expectedYeildCurrency,
                $position->name,
                $averagePositionPrice,
                $averagePositionPriceNoNkd
            );
            $instrs[] = $instr;
        }

        return new TIPortfolio($currs, $instrs);
    }

    /**
     * Создание лимитной заявки
     *
     * @param string $figi
     * @param int $lots
     * @param $operation
     * @param double $price
     *
     * @return ?TIOrder
     * @throws TIException
     */
    public function sendOrder($figi, $lots, $operation, $price = null, $asyc = false)
    {
        $req_body = json_encode(
            (object)[
                "lots" => $lots,
                "operation" => $operation,
                "price" => $price,
            ]
        );

        $order_type = empty($price) ? "market-order" : "limit-order";

        $requestParams = [
            "/orders/" . $order_type,
            "POST",
            [
                "figi" => $figi,
                "brokerAccountId" => $this->brokerAccountId
            ],
            $req_body
        ];

        if ($async) {
            $this->addAsyncRequest(...$requestParams);
            return null;
        }

        $response = $this->sendRequest(...$requestParams);
        return $this->setUpOrder($response, $figi);
    }

    /**
     * Отменить заявку
     *
     * @param string $orderId Номер заявки
     *
     * @return string status
     * @throws TIException
     */
    public function cancelOrder($orderId)
    {
        $response = $this->sendRequest(
            "/orders/cancel",
            "POST",
            [
                "orderId" => $orderId,
                "brokerAccountId" => $this->brokerAccountId
            ]
        );

        return $response->getStatus();
    }

    /**
     * @param null $orderIds
     * @return array
     * @throws TIException
     */
    public function getOrders($orderIds = null)
    {
        $orders = [];
        $response = $this->sendRequest("/orders", "GET");
        foreach ($response->getPayload() as $order) {
            if ($orderIds === null || in_array($order->orderId, $orderIds)) {
                $ord = new TIOrder(
                    $order->orderId,
                    TIOperationEnum::getOperation($order->operation),
                    $order->status,
                    null, // rejected
                    $order->requestedLots,
                    $order->executedLots,
                    null, // comm currency
                    $order->figi,
                    $order->type,
                    '',
                    $order->price,
                );
                $orders[] = $ord;
            }
        }
        return $orders;
    }

    /**
     *
     * @param DateTime $fromDate
     * @param DateTime $toDate
     * @param string $figi
     * @return TIOperation[]
     * @throws TIException
     */
    public function getOperations($fromDate, $toDate, $figi = null)
    {
        $operations = [];
        $response = $this->sendRequest(
            "/operations",
            "GET",
            [
                "from" => $fromDate->format("c"),
                "to" => $toDate->format("c"),
                "figi" => $figi,
                "brokerAccountId" => $this->brokerAccountId,
            ]
        );

        foreach ($response->getPayload()->operations as $operation) {
            $trades = [];
            foreach ((empty($operation->trades) ? [] : $operation->trades) as $operationTrade)
            {
                $trades[] = new TIOperationTrade(
                    empty($operationTrade->tradeId) ? null : $operationTrade->tradeId,
                    empty($operationTrade->date) ? null : $operationTrade->date,
                    empty($operationTrade->price) ? null : $operationTrade->price,
                    empty($operationTrade->quantity) ? null : $operationTrade->quantity
                );
            }
            $commissionCurrency = (isset($operation->commission)) ? TICurrencyEnum::getCurrency(
                $operation->commission->currency
            ) : null;
            $commissionValue = (isset($operation->commission)) ? $operation->commission->value : null;
            try {
                $dateTime = new DateTime($operation->date);
            } catch (Exception $e) {
                throw new TIException('Can not create DateTime from operations');
            }
            $opr = new TIOperation(
                $operation->id,
                $operation->status,
                $trades,
                new TICommission($commissionCurrency, $commissionValue),
                TICurrencyEnum::getCurrency($operation->currency),
                $operation->payment,
                empty($operation->price) ? null : $operation->price,
                empty($operation->quantity) ? null : $operation->quantity,
                empty($operation->figi) ? null : $operation->figi,
                empty($operation->instrumentType) ? null : $operation->instrumentType,
                $operation->isMarginCall,
                $dateTime,
                TIOperationEnum::getOperation(
                    empty($operation->operationType) ? null : $operation->operationType
                )
            );
            $operations[] = $opr;
        }
        return $operations;
    }

    /**
     * @param bool $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param bool $ignore_ssl_peer_verification
     */
    public function setIgnoreSslPeerVerification($ignore_ssl_peer_verification)
    {
        $this->ignore_ssl_peer_verification = $ignore_ssl_peer_verification;
    }

    public function createRequest(
        $action,
        $method,
        $req_params = [],
        $req_body = null
    ) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url . $action);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

        if (count($req_params) > 0) {
            curl_setopt(
                $curl,
                CURLOPT_URL,
                $this->url . $action . '?' . http_build_query(
                    $req_params
                )
            );
        }

        if ($method !== "GET") {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $req_body);
        }

        curl_setopt(
            $curl,
            CURLOPT_HTTPHEADER,
            [
                'Content-Type:application/json',
                'Authorization: Bearer ' . $this->token,
            ]
        );

        if ($this->debug) {
            curl_setopt($curl, CURLOPT_VERBOSE, true);
            curl_setopt($curl, CURLOPT_CERTINFO, true);
        }

        if ($this->ignore_ssl_peer_verification) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        }

        return $curl;
    }

    /**
     * Отправка запроса на API
     *
     * @param string $action
     * @param string $method
     * @param array $req_params
     * @param string $req_body
     *
     * @throws TIException
     */
    public function sendRequest(
        $action,
        $method,
        $req_params = [],
        $req_body = null
    ) {
        $curl = $this->createRequest($action, $method, $req_params, $req_body);

        $out = curl_exec($curl);
        $res = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $error = curl_error($curl);
        curl_close($curl);

        try {
            if ($res === 0) {
                throw new \Exception($error);
            }

            return new TIResponse($out, $res);
        } catch (Exception $ex) {
            $this->
            return null;
        }
    }

    public function initAsyncQueue()
    {
        $this->curl_multi_exec_queue = curl_multi_init();
    }

    public function addAsyncRequest(
        $action,
        $method,
        $req_params = [],
        $req_body = null,
    ) {
        if (!$this->curl_multi_exec_queue) {
            $this->initAsyncQueue();
        }

        $curl = $this->createRequest($action, $method, $req_params, $req_body);
        curl_multi_add_handle($curl_multi_exec_queue, $curl);
        $this->async_curls_list[] = $curl;
    }

    public function runAsyncRequestsQueue()
    {
        if (!($queue = $this->curl_multi_exec_queue)) {
            return;
        }

        $runSubConnectionsFunction = function ($queue, &$active) {
            while (true) {
                $curlCode = curl_multi_exec($queue, $active);
                if ($curlCode !== CURLM_CALL_MULTI_PERFORM) {
                    return $curlCode;
                }
            }
        };

        $active = null;
        $mrc = $runSubConnectionsFunction($queue, $active);
        while ($active && $mrc === CURLM_OK) {
            if (curl_multi_select($queue) !== -1) {
                $runSubConnectionsFunction($queue, $active);
            }
        }
    }

    public function closeAsyncRequests()
    {
        if (!($curl_multi_exec_queue = $this->curl_multi_exec_queue)) {
            return;
        }

        foreach ($this->async_curls_list as $channel) {
            curl_multi_remove_handle($curl_multi_exec_queue, $channel);
        }

        curl_multi_close($curl_multi_exec_queue);
    }

    /**
     * @throws TIException
     */
    private function wsConnect()
    {
        try {
            $this->wsClient = new Client(
                "wss://api-invest.tinkoff.ru/openapi/md/v1/md-openapi/ws",
                [
                    "timeout" => 60,
                    "headers" => ["authorization" => "Bearer {$this->token}"],
                ]
            );
        } catch (Exception $e) {
            throw new TIException(
                "Can't connect to stream API. " . $e->getCode() . ' ' . $e->getMessage()
            );
        }
    }


    /**
     * @param $figi
     * @param $interval
     * @param string $action
     * @throws TIException
     */
    private function candleSubscription($figi, $interval, $action = self::CANDLE_SUBSCRIBE)
    {
        $request = '{
                        "event": "candle:' . $action . '",
                        "figi": "' . $figi . '",
                        "interval": "' . $interval . '"
                    }';
        if (!$this->wsClient->isConnected()) {
            $this->wsConnect();
        }
        try {
            $this->wsClient->send($request);
        } catch (BadOpcodeException $e) {
            throw new TIException('Can not send websocket request errorMessage' . $e->getMessage());
        }
    }

    /**
     * Получить свечу
     *
     * @param string $figi
     * @param string $interval
     *
     * @return TICandle
     * @throws TIException
     */
    public function getCandle($figi, $interval)
    {
        $this->candleSubscription($figi, $interval);
        $response = $this->wsClient->receive();
        $this->candleSubscription($figi, $interval, self::ACTION_UNSUBSCRIBE);
        $json = json_decode($response);
        if (empty($json)) {
            throw new TIException('Got empty response for Candle');
        }
        return $this->setUpCandle($json->payload);
    }

    /**
     * @param $figi
     * @param $depth
     * @param string $action
     * @throws TIException
     */
    private function orderbookSubscribtion($figi, $depth, $action = self::CANDLE_SUBSCRIBE)
    {
        $request = '{
                        "event": "orderbook:' . $action . '",
                        "figi": "' . $figi . '",
                        "depth": ' . $depth . '
                    }';
        if (!$this->wsClient->isConnected()) {
            $this->wsConnect();
        }
        try {
            $this->wsClient->send($request);
        } catch (BadOpcodeException $e) {
            throw new TIException('Can not send websocket request errorMessage' . $e->getMessage());
        }
    }

    /**
     * Получить стакан
     *
     * @param string $figi
     * @param int $depth
     *
     * @return TIOrderBook
     * @throws TIException
     */
    public function getOrderBook($figi, $depth = 1)
    {
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > 20) {
            $depth = 20;
        }
        $this->orderbookSubscribtion($figi, $depth);
        $response = $this->wsClient->receive();
        $this->orderbookSubscribtion($figi, $depth, self::CANDLE_UNSUBSCRIBE);
        $json = json_decode($response);
        if (empty($json)) {
            throw new TIException('Got empty response for OrderBook');
        }
        return $this->setUpOrderBook($json->payload);
    }

    /**
     * @param $figi
     * @param string $action
     * @throws TIException
     */
    private function instrumentInfoSubscription($figi, $action = self::CANDLE_SUBSCRIBE)
    {
        $request = '{
                        "event": "instrument_info:' . $action . '",
                        "figi": "' . $figi . '"
                    }';
        if (!$this->wsClient->isConnected()) {
            $this->wsConnect();
        }
        try {
            $this->wsClient->send($request);
        } catch (BadOpcodeException $e) {
            throw new TIException('Can not send websocket request errorMessage' . $e->getMessage());
        }
    }

    /**
     * Get Instrument info
     *
     * @param string $figi
     *
     * @return TIInstrumentInfo
     * @throws TIException
     */
    public function getInstrumentInfo($figi)
    {
        $this->instrumentInfoSubscription($figi);
        $response = $this->wsClient->receive();
        $this->instrumentInfoSubscription($figi, self::CANDLE_UNSUBSCRIBE);
        $json = json_decode($response);
        if (empty($json)) {
            throw new TIException('Got empty response for InstrumentInfo');
        }

        return $this->setUpInstrumentInfo($json->payload);
    }


    /**
     * @param $figi
     * @param $interval
     * @throws TIException
     */
    public function subscribeGettingCandle($figi, $interval)
    {
        $this->candleSubscription($figi, $interval);
    }

    /**
     * @param $figi
     * @param $depth
     * @throws TIException
     */
    public function subscribeGettingOrderBook($figi, $depth)
    {
        $this->orderbookSubscribtion($figi, $depth);
    }

    /**
     * @param $figi
     * @throws TIException
     */
    public function subscribeGettingInstrumentInfo($figi)
    {
        $this->instrumentInfoSubscription($figi);
    }

    /**
     * @param $figi
     * @param $interval
     * @throws TIException
     */
    public function unsubscribeGettingCandle($figi, $interval)
    {
        $this->candleSubscription($figi, $interval, self::CANDLE_UNSUBSCRIBE);
    }

    /**
     * @param $figi
     * @param $depth
     * @throws TIException
     */
    public function unsubscribeGettingOrderBook($figi, $depth)
    {
        $this->orderbookSubscribtion($figi, $depth, self::CANDLE_UNSUBSCRIBE);
    }

    /**
     * @param $figi
     * @throws TIException
     */
    public function unsubscribeGettingInstrumentInfo($figi)
    {
        $this->instrumentInfoSubscription($figi, self::CANDLE_UNSUBSCRIBE);
    }


    /**
     * @param $callback
     * @param int $max_response
     * @param int $max_time_sec
     */
    public function startGetting(
        $callback,
        $max_response = 10,
        $max_time_sec = 60
    ) {
        $this->startGetting = true;
        $this->response_now = 0;
        $this->response_start_time = time();
        while (true) {
            $response = $this->wsClient->receive();
            $json = json_decode($response);
            if (!isset($json->event) || $json === null) {
                continue;
            }
            try {
                switch ($json->event) {
                    case "candle" :
                        $object = $this->setUpCandle($json->payload);
                        break;
                    case "orderbook" :
                        $object = $this->setUpOrderBook($json->payload);
                        break;
                    case "instrument_info" :
                        $object = $this->setUpInstrumentInfo($json->payload);
                        break;
                }
                if (!empty($object)) {
                    call_user_func($callback, $object);
                }
            } catch (TIException $e) {
                //TODO: add Exception to logger
            }
            $this->response_now++;
            if ($this->startGetting === false || ($max_response !== null && $this->response_now >= $max_response) || ($max_time_sec !== null && time(
                    ) > $this->response_start_time + $max_time_sec)) {
                break;
            }
        }
    }


    /**
     *
     */
    public function stopGetting()
    {
        $this->startGetting = false;
    }


    /**
     * @param $payload
     * @return TIOrderBook
     */
    private function setUpOrderBook($payload)
    {
        return new TIOrderBook(
            empty($payload->depth) ? null : $payload->depth,
            empty($payload->bids) ? null : $payload->bids,
            empty($payload->asks) ? null : $payload->asks,
            empty($payload->figi) ? null : $payload->figi,
            empty($payload->tradeStatus) ? null : $payload->tradeStatus,
            empty($payload->minPriceIncrement) ? null : $payload->minPriceIncrement,
            empty($payload->faceValue) ? null : $payload->faceValue,
            empty($payload->lastPrice) ? null : $payload->lastPrice,
            empty($payload->closePrice) ? null : $payload->closePrice,
            empty($payload->limitUp) ? null : $payload->limitUp,
            empty($payload->limitDown) ? null : $payload->limitDown
        );
    }

    /**
     * @param $payload
     * @return TIInstrumentInfo
     */
    private function setUpInstrumentInfo($payload)
    {
        $object = new TIInstrumentInfo(
            $payload->trade_status,
            $payload->min_price_increment,
            $payload->lot,
            $payload->figi
        );
        if (isset($payload->accrued_interest)) {
            $object->setAccrued_interest(
                $payload->accrued_interest
            );
        }
        if (isset($payload->limit_up)) {
            $object->setLimit_up($payload->limit_up);
        }
        if (isset($payload->limit_down)) {
            $object->setLimit_down($payload->limit_down);
        }
        return $object;
    }


    /**
     * @param $payload
     * @return TICandle
     * @throws TIException
     */
    private function setUpCandle($payload)
    {
        try {
            $datetime = new DateTime($payload->time);
        } catch (Exception $e) {
            throw new TIException('Can not create DateTime for Candle');
        }
        return new TICandle(
            $payload->o,
            $payload->c,
            $payload->h,
            $payload->l,
            $payload->v,
            $datetime,
            TICandleIntervalEnum::getInterval(
                $payload->interval
            ),
            $payload->figi
        );
    }

    /**
     * @param TIResponse $response
     * @param null|array $tickers
     * @return array
     */
    private function setUpLists($response, $tickers = null)
    {
        $array = [];
        foreach ($response->getPayload()->instruments as $instrument) {
            if ($tickers === null || in_array($instrument->ticker, $tickers)) {
                $currency = TICurrencyEnum::getCurrency($instrument->currency);
                $minPriceIncrement = (isset($instrument->minPriceIncrement)) ? $instrument->minPriceIncrement : null;

                $stock = new TIInstrument(
                    empty($instrument->figi) ? null : $instrument->figi,
                    empty($instrument->ticker) ? null : $instrument->ticker,
                    empty($instrument->isin) ? null : $instrument->isin,
                    $minPriceIncrement,
                    empty($instrument->lot) ? null : $instrument->lot,
                    $currency,
                    empty($instrument->name) ? null : $instrument->name,
                    empty($instrument->type) ? null : $instrument->type
                );
                $array[] = $stock;
            }
        }
        return $array;
    }

    /**
     * @param TIResponse $response
     * @param string $figi
     * @return TIOrder
     */
    private function setUpOrder($response, $figi)
    {
        $payload = $response->getPayload();
        $commissionValue = (isset($payload->commission)) ? $payload->commission->value : null;
        $commissionCurrency = (isset($payload->commission)) ? TICurrencyEnum::getCurrency(
            $payload->commission->currency
        ) : null;
        $rejectReason = (isset($payload->rejectReason)) ? $payload->rejectReason : null;

        return new TIOrder(
            empty($payload->orderId) ? null : $payload->orderId,
            TIOperationEnum::getOperation($payload->operation),
            empty($payload->status) ? null : $payload->status,
            $rejectReason,
            empty($payload->requestedLots) ? null : $payload->requestedLots,
            empty($payload->executedLots) ? null : $payload->executedLots,
            new TICommission($commissionCurrency, $commissionValue),
            $figi,
            null, // type
            empty($payload->message) ? null : $payload->message,
            empty($payload->price) ? null : $payload->price,
        );
    }
}
