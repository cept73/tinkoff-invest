<?php

/**
 * @noinspection SpellCheckingInspection
 * @noinspection PhpUnused
 */

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace jamesRUS52\TinkoffInvest;

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
    use LogTrait;

    public const CANDLE_SUBSCRIBE   = 'subscribe';
    public const ACTION_UNSUBSCRIBE = 'unsubscribe';

    //put your code here
    /**
     * @var string
     */
    private $token;

    /**
     * @var string
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

    private $brokerAccountId;

    private $debug = false;

    private $ignore_ssl_peer_verification = false;

    private $curl_multi_exec_queue;

    /**
     * @var array list of async CURLs
     */
    private $async_curls_list = [];

    /**
     *
     * @param string $token token from tinkoff.ru for specific site
     * @param string $site site name (sandbox or real exchange)
     * @param ?string $account
     */
    public function __construct(string $token, string $site, string $account = null)
    {
        $this->token = $token;
        $this->url = $site;
        $this->brokerAccountId = $account;
        $this->wsConnect();
    }

    /**
     * @return ?string
     */
    public function getBrokerAccount(): ?string
    {
        return $this->brokerAccountId;
    }

    /**
     * @param $account
     */
    public function setBrokerAccount($account): void
    {
        $this->brokerAccountId = $account;
    }


    /**
     * Удаление всех позиций в песочнице
     *
     * @return string status
     */
    public function sbClear(): ?string
    {
        $response = $this->sendRequest('/sandbox/clear',
            'POST',
            ['brokerAccountId' => $this->brokerAccountId]
        );

        return $response ? $response->getStatus() : null;
    }

    /**
     * Remove sandbox Account
     *
     * @return ?string
     */
    public function sbRemove(): ?string
    {
        $response = $this->sendRequest('/sandbox/remove',
            'POST',
            ['brokerAccountId' => $this->brokerAccountId]
        );
        return $response ? $response->getStatus() : null;
    }

    /**
     * Регистрация клиента в sandbox
     *
     * @return ?TIAccount
     */
    public function sbRegister(): ?TIAccount
    {
        $response = $this->sendRequest('/sandbox/register', 'POST');
        if (!$response) {
            return null;
        }
        return new TIAccount($response->getPayload()->brokerAccountType,
                            $response->getPayload()->brokerAccountId);

    }

    /**
     * Выставление баланса по инструментным позициям
     *
     * @param double $balance
     * @param string $figi
     *
     * @return ?string status
     */
    public function sbPositionBalance(float $balance, string $figi): ?string
    {
        $request = ['figi' => $figi, 'balance' => $balance];
        $request_body = json_encode($request, JSON_NUMERIC_CHECK);
        $response = $this->sendRequest(
            '/sandbox/positions/balance',
            'POST',
            ['brokerAccountId' => $this->brokerAccountId],
            $request_body
        );

        return $response ? $response->getStatus() : null;
    }

    /**
     * Выставление баланса по инструментным позициям
     *
     * @param double $balance
     * @param string $currency
     *
     * @return ?string status
     */
    public function sbCurrencyBalance(float $balance, string $currency = TICurrencyEnum::RUB): ?string
    {
        $request = ['currency' => $currency, 'balance' => $balance];
        $request_body = json_encode($request, JSON_NUMERIC_CHECK);
        $response = $this->sendRequest(
            '/sandbox/currencies/balance',
            'POST',
            ['brokerAccountId' => $this->brokerAccountId],
            $request_body
        );
        return $response ? $response->getStatus() : null;
    }

    /**
     * Получение списка акций
     *
     * @param array|null $tickers Ticker Filter
     *
     * @return TIInstrument[] Список инструментов
     */
    public function getStocks(array $tickers = null): ?array
    {
        $response = $this->sendRequest('/market/stocks', 'GET');
        return $response ? $this->setUpLists($response, $tickers) : null;
    }

    /**
     * Получение списка облигаций
     *
     * @param array|null $tickers filter tickers
     *
     * @return TIInstrument[]
     */
    public function getBonds(array $tickers = null): ?array
    {
        $response = $this->sendRequest('/market/bonds', 'GET');
        return $response ? $this->setUpLists($response, $tickers) : null;
    }

    /**
     * Получение списка ETF
     *
     * @param array|null $tickers filter ticker
     *
     * @return TIInstrument[]
     */
    public function getEtfs(array $tickers = null): ?array
    {
        $response = $this->sendRequest('/market/etfs', 'GET');
        return $response ? $this->setUpLists($response, $tickers) : null;
    }

    /**
     * Получение списка валют
     *
     * @param array|null $tickers filter ticker
     *
     * @return ?TIInstrument[]
     */
    public function getCurrencies(array $tickers = null): ?array
    {
        $currencies = [];
        $response = $this->sendRequest('/market/currencies', 'GET');
        if (!$response) {
            return null;
        }

        foreach ($response->getPayload()->instruments as $instrument) {
            if ($tickers === null || in_array($instrument->ticker, $tickers, true)) {
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
     * @return ?TIInstrument
     */
    public function getInstrumentByTicker(string $ticker): ?TIInstrument
    {
        $response = $this->sendRequest(
            '/market/search/by-ticker',
            'GET',
            ['ticker' => $ticker]
        );
        if (!$response) {
            return null;
        }

        if ($response->getPayload()->total === 0) {
            $this->logString('Cannot find instrument by ticker {$ticker}');
            return null;
        }

        $firstInstrument = $response->getPayload()->instruments[0];

        $currency = TICurrencyEnum::getCurrency($firstInstrument->currency);

        return new TIInstrument(
            $firstInstrument->figi,
            $firstInstrument->ticker,
            $firstInstrument->isin ?? null,
            $firstInstrument->minPriceIncrement,
            $firstInstrument->lot,
            $currency,
            $firstInstrument->name,
            $firstInstrument->type
        );
    }

    /**
     * Получение инструмента по FIGI
     *
     * @param string $figi
     *
     * @return ?TIInstrument
     */
    public function getInstrumentByFigi(string $figi): ?TIInstrument
    {
        try {
            $response = $this->sendRequest(
                '/market/search/by-figi',
                'GET',
                ['figi' => $figi]
            );
            if ($response) {
                return null;
            }

            $currency = TICurrencyEnum::getCurrency($response->getPayload()->currency);

            return new TIInstrument(
                $response->getPayload()->figi,
                $response->getPayload()->ticker,
                $response->getPayload()->isin ?? null,
                $response->getPayload()->minPriceIncrement,
                $response->getPayload()->lot,
                $currency,
                $response->getPayload()->name,
                $response->getPayload()->type
            );
        } catch (Exception $ex) {
            $this->logException($ex);
        }
        return null;
    }

    /**
     * Получение исторического стакана
     *
     * @param string $figi
     * @param int $depth
     * @return TIOrderBook
     */
    public function getHistoryOrderBook(string $figi, int $depth = 1): ?TIOrderBook
    {
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > 20) {
            $depth = 20;
        }
        $response = $this->sendRequest(
            '/market/orderbook',
            'GET',
            [
                'figi' => $figi,
                'depth' => $depth,
            ]
        );

        return $response ? $this->setUpOrderBook($response->getPayload()) : null;
    }

    /**
     * Получение исторических свечей
     * default figi = AAPL
     * default from 7Days ago
     * default to now
     * default interval 15 min
     *
     * @param string $figi
     * @param DateTime|null $from
     * @param DateTime|null $to
     * @param string|null $interval
     * @return ?TICandle[]
     */
    public function getHistoryCandles(string $figi, DateTime $from = null, DateTime $to = null, string $interval = null): ?array
    {
        $fromDate = new DateTime();
        $fromDate->sub(new DateInterval('P7D'));
        $toDate = new DateTime();

        $response = $this->sendRequest(
            '/market/candles',
            'GET',
            [
                'figi'      => $figi,
                'from'      => $from === null ? $fromDate->format('c') : $from->format('c'),
                'to'        => $to === null ? $toDate->format('c') : $to->format('c'),
                'interval'  => empty($interval) ? TIIntervalEnum::MIN15 : $interval
            ]
        );
        if (!$response) {
            return null;
        }

        $array = [];
        foreach ($response->getPayload()->candles as $candle) {
            $array [] = $this->setUpCandle($candle);
        }
        return $array;
    }


    /**
     * Получение текущих аккаунтов пользователя
     *
     * @return ?TIAccount[]
     */
    public function getAccounts(): ?array
    {
        $response = $this->sendRequest('/user/accounts', 'GET');
        if (!$response) {
            return null;
        }

        $accounts = [];
        foreach ($response->getPayload()->accounts as $account) {
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
     */
    public function getPortfolio(): ?TIPortfolio
    {
        $currList = [];
        $params = ['brokerAccountId' => $this->brokerAccountId];

        $response = $this->sendRequest('/portfolio/currencies', 'GET', $params);
        if (!$response) {
            return null;
        }

        foreach ($response->getPayload()->currencies as $currency) {
            $tiCurrency = TICurrencyEnum::getCurrency($currency->currency);

            $curr = new TIPortfolioCurrency(
                $currency->balance,
                $tiCurrency,
                $currency->blocked ?? 0
            );
            $currList[] = $curr;
        }

        $instrList = [];
        $response = $this->sendRequest('/portfolio', 'GET', $params);

        foreach ($response->getPayload()->positions as $position) {
            $expectedYieldCurrency = null;
            $expectedYieldValue = null;
            if (isset($position->expectedYield)) {
                $expectedYieldCurrency = TICurrencyEnum::getCurrency(
                    $position->expectedYield->currency
                );
                $expectedYieldValue = $position->expectedYield->value;
            }

            $instr = new TIPortfolioInstrument(
                $position->figi,
                $position->ticker,
                $position->isin ?? null,
                $position->instrumentType,
                $position->balance,
                $position->blocked ?? 0,
                $position->lots,
                $expectedYieldValue,
                $expectedYieldCurrency,
                $position->name,
                $position->averagePositionPrice ?? null,
                $position->averagePositionPriceNoNkd ?? null
            );
            $instrList[] = $instr;
        }

        return new TIPortfolio($currList, $instrList);
    }

    /**
     * Создание лимитной заявки
     *
     * @param string $figi
     * @param int $lots
     * @param $operation
     * @param null $price
     * @param bool $async
     * @return ?TIOrder
     */
    public function sendOrder(string $figi, int $lots, $operation, $price = null, bool $async = false): ?TIOrder
    {
        $req_body = json_encode(
            (object)[
                'lots' => $lots,
                'operation' => $operation,
                'price' => $price,
            ]
        );

        $order_type = empty($price) ? 'market-order' : 'limit-order';

        $requestParams = [
            '/orders/' . $order_type,
            'POST',
            [
                'figi' => $figi,
                'brokerAccountId' => $this->brokerAccountId
            ],
            $req_body
        ];

        if ($async) {
            $this->addAsyncRequest(...$requestParams);
            return null;
        }

        $response = $this->sendRequest(...$requestParams);
        if (!$response) {
            return null;
        }
        return $this->setUpOrder($response, $figi);
    }

    /**
     * Отменить заявку
     *
     * @param string $orderId Номер заявки
     *
     * @return string status
     */
    public function cancelOrder(string $orderId): ?string
    {
        $response = $this->sendRequest(
            '/orders/cancel',
            'POST',
            [
                'orderId' => $orderId,
                'brokerAccountId' => $this->brokerAccountId
            ]
        );

        return $response ? $response->getStatus() : null;
    }

    /**
     * @param null $orderIds
     * @return ?array
     */
    public function getOrders($orderIds = null): ?array
    {
        $orders = [];
        $response = $this->sendRequest('/orders', 'GET');
        if (!$response) {
            return null;
        }

        foreach ($response->getPayload() as $order) {
            if ($orderIds === null || in_array($order->orderId, $orderIds, true)) {
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
                    $order->price
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
     * @param null $figi
     * @return TIOperation[]
     */
    public function getOperations(DateTime $fromDate, DateTime $toDate, $figi = null): ?array
    {
        $operations = [];
        $response = $this->sendRequest(
            '/operations',
            'GET',
            [
                'from' => $fromDate->format('c'),
                'to' => $toDate->format('c'),
                'figi' => $figi,
                'brokerAccountId' => $this->brokerAccountId,
            ]
        );
        if (!$response) {
            return null;
        }

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
            } catch (Exception $ex) {
                $this->logString('Can not create DateTime from operations');
                $this->logException($ex);
                return null;
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
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * @param bool $ignore_ssl_peer_verification
     */
    public function setIgnoreSslPeerVerification(bool $ignore_ssl_peer_verification): void
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

        if ($method !== 'GET') {
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

        // NOT SAFE!!!
        if ($this->ignore_ssl_peer_verification) {
            /** @noinspection CurlSslServerSpoofingInspection */
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
     * @param string|null $req_body
     * @return TIResponse|null
     */
    public function sendRequest(
        string $action,
        string $method,
        array $req_params = [],
        string $req_body = null
    ): ?TIResponse
    {
        $curl = $this->createRequest($action, $method, $req_params, $req_body);

        $out = curl_exec($curl);
        $res = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $error = curl_error($curl);
        curl_close($curl);

        try {
            if ($res === 0) {
                $this->logString($error);
                return null;
            }

            return new TIResponse($out);
        } catch (Exception $ex) {
            $this->logException($ex);
            return null;
        }
    }

    public function initAsyncQueue(): void
    {
        $this->curl_multi_exec_queue = curl_multi_init();
    }

    public function addAsyncRequest(
        $action,
        $method,
        $req_params = [],
        $req_body = null
    ): void
    {
        if (!$this->curl_multi_exec_queue) {
            $this->initAsyncQueue();
        }

        if ($curl = $this->createRequest($action, $method, $req_params, $req_body)) {
            curl_multi_add_handle($this->curl_multi_exec_queue, $curl);
            $this->async_curls_list[] = $curl;
        }
    }

    public function runAsyncRequestsQueue(): void
    {
        if (!($queue = $this->curl_multi_exec_queue)) {
            return;
        }

        $runSubConnectionsFunction = static function ($queue, &$active) {
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

    public function closeAsyncRequests(): void
    {
        if (!($curl_multi_exec_queue = $this->curl_multi_exec_queue)) {
            return;
        }

        foreach ($this->async_curls_list as $channel) {
            curl_multi_remove_handle($curl_multi_exec_queue, $channel);
        }

        curl_multi_close($curl_multi_exec_queue);
    }

    private function wsConnect(): void
    {
        try {
            $this->wsClient = new Client(
                'wss://api-invest.tinkoff.ru/openapi/md/v1/md-openapi/ws',
                [
                    'timeout' => 60,
                    'headers' => ['authorization' => "Bearer $this->token"],
                ]
            );
        } catch (Exception $ex) {
            $this->logString("Can't connect to stream API.");
            $this->logException($ex);
        }
    }


    /**
     * @param $figi
     * @param $interval
     * @param string $action
     */
    private function candleSubscription($figi, $interval, string $action = self::CANDLE_SUBSCRIBE): void
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
        } catch (BadOpcodeException $ex) {
            $this->logString('Can not send websocket request errorMessage');
            $this->logException($ex);
        }
    }

    /**
     * Получить свечу
     *
     * @param string $figi
     * @param string $interval
     *
     * @return TICandle
     */
    public function getCandle(string $figi, string $interval): ?TICandle
    {
        $this->candleSubscription($figi, $interval);
        $response = $this->wsClient->receive();
        $this->candleSubscription($figi, $interval, self::ACTION_UNSUBSCRIBE);

        $json = json_decode($response, true);
        if (empty($json)) {
            $this->logString('Got empty response for Candle');
            return null;
        }

        return $this->setUpCandle($json->payload);
    }

    /**
     * @param $figi
     * @param $depth
     * @param string $action
     */
    private function orderbookSubscription($figi, $depth, string $action = self::CANDLE_SUBSCRIBE): void
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
        } catch (BadOpcodeException $ex) {
            $this->logString('Can not send websocket request errorMessage');
            $this->logException($ex);
        }
    }

    /**
     * Получить стакан
     *
     * @param string $figi
     * @param int $depth
     *
     * @return TIOrderBook
     */
    public function getOrderBook(string $figi, int $depth = 1): ?TIOrderBook
    {
        if ($depth < 1) {
            $depth = 1;
        }
        if ($depth > 20) {
            $depth = 20;
        }

        $this->orderbookSubscription($figi, $depth);
        $response = $this->wsClient->receive();
        $this->orderbookSubscription($figi, $depth, self::ACTION_UNSUBSCRIBE);

        $json = json_decode($response, true);
        if (empty($json)) {
            $this->logString('Got empty response for OrderBook');
            return null;
        }

        return $this->setUpOrderBook($json->payload);
    }

    /**
     * @param $figi
     * @param string $action
     */
    private function instrumentInfoSubscription($figi, string $action = self::CANDLE_SUBSCRIBE): void
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
        } catch (BadOpcodeException $ex) {
            $this->logString('Can not send websocket request errorMessage');
            $this->logException($ex);
        }
    }

    /**
     * Get Instrument info
     *
     * @param string $figi
     *
     * @return TIInstrumentInfo
     */
    public function getInstrumentInfo(string $figi): ?TIInstrumentInfo
    {
        $this->instrumentInfoSubscription($figi);
        $response = $this->wsClient->receive();
        $this->instrumentInfoSubscription($figi, self::ACTION_UNSUBSCRIBE);
        $json = json_decode($response, true);
        if (empty($json)) {
            $this->logString('Got empty response for InstrumentInfo');
            return null;
        }

        return $this->setUpInstrumentInfo($json->payload);
    }


    /**
     * @param $figi
     * @param $interval
     */
    public function subscribeGettingCandle($figi, $interval): void
    {
        $this->candleSubscription($figi, $interval);
    }

    /**
     * @param $figi
     * @param $depth
     */
    public function subscribeGettingOrderBook($figi, $depth): void
    {
        $this->orderbookSubscription($figi, $depth);
    }

    /**
     * @param $figi
     */
    public function subscribeGettingInstrumentInfo($figi): void
    {
        $this->instrumentInfoSubscription($figi);
    }

    /**
     * @param $figi
     * @param $interval
     */
    public function unsubscribeGettingCandle($figi, $interval): void
    {
        $this->candleSubscription($figi, $interval, self::ACTION_UNSUBSCRIBE);
    }

    /**
     * @param $figi
     * @param $depth
     */
    public function unsubscribeGettingOrderBook($figi, $depth): void
    {
        $this->orderbookSubscription($figi, $depth, self::ACTION_UNSUBSCRIBE);
    }

    /**
     * @param $figi
     */
    public function unsubscribeGettingInstrumentInfo($figi): void
    {
        $this->instrumentInfoSubscription($figi, self::ACTION_UNSUBSCRIBE);
    }


    /**
     * @param $callback
     * @param int $max_response
     * @param int $max_time_sec
     */
    public function startGetting(
        $callback,
        int $max_response = 10,
        int $max_time_sec = 60
    ): void
    {
        $this->startGetting = true;
        $response_now = 0;
        $response_start_time = time();
        while (true) {
            $response = $this->wsClient->receive();
            $json = json_decode($response, true);
            if (!isset($json->event) || $json === null) {
                continue;
            }
            try {
                $object = null;
                switch ($json->event) {
                    case 'candle' :
                        $object = $this->setUpCandle($json->payload);
                        break;
                    case 'orderbook' :
                        $object = $this->setUpOrderBook($json->payload);
                        break;
                    case 'instrument_info' :
                        $object = $this->setUpInstrumentInfo($json->payload);
                        break;
                }
                if ($object) {
                    $callback($object);
                }
            } catch (TIException $ex) {
                $this->logException($ex);
                return;
            }

            $response_now++;
            if ($this->startGetting === false || ($max_response !== null && $response_now >= $max_response) || ($max_time_sec !== null && time(
                    ) > $response_start_time + $max_time_sec)) {
                break;
            }
        }
    }

    public function stopGetting(): void
    {
        $this->startGetting = false;
    }

    /**
     * @param $payload
     * @return TIOrderBook
     */
    private function setUpOrderBook($payload): TIOrderBook
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
    private function setUpInstrumentInfo($payload): TIInstrumentInfo
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
     * @return ?TICandle
     */
    private function setUpCandle($payload): ?TICandle
    {
        try {
            $datetime = new DateTime($payload->time);
            return new TICandle(
                $payload->o,
                $payload->c,
                $payload->h,
                $payload->l,
                $payload->v,
                $datetime,
                TICandleIntervalEnum::getInterval($payload->interval),
                $payload->figi
            );
        } catch (Exception $ex) {
            $this->logString('Can not create DateTime for Candle');
            $this->logException($ex);
            return null;
        }
    }

    /**
     * @param TIResponse $response
     * @param array|null $tickers
     * @return array
     */
    private function setUpLists(TIResponse $response, array $tickers = null): ?array
    {
        try {
            $array = [];
            foreach ($response->getPayload()->instruments as $instrument) {
                if ($tickers === null || in_array($instrument->ticker, $tickers, true)) {
                    $stock = new TIInstrument(
                        empty($instrument->figi) ? null : $instrument->figi,
                        empty($instrument->ticker) ? null : $instrument->ticker,
                        empty($instrument->isin) ? null : $instrument->isin,
                        $instrument->minPriceIncrement ?? null,
                        empty($instrument->lot) ? null : $instrument->lot,
                        TICurrencyEnum::getCurrency($instrument->currency),
                        empty($instrument->name) ? null : $instrument->name,
                        empty($instrument->type) ? null : $instrument->type
                    );
                    $array[] = $stock;
                }
            }
            return $array;
        } catch (Exception $ex) {
            $this->logException($ex);
            return null;
        }
    }

    /**
     * @param TIResponse $response
     * @param string $figi
     * @return TIOrder
     */
    private function setUpOrder(TIResponse $response, string $figi): ?TIOrder
    {
        try {
            $payload = $response->getPayload();
            $commissionValue = (isset($payload->commission)) ? $payload->commission->value : null;
            $commissionCurrency = (isset($payload->commission))
                ? TICurrencyEnum::getCurrency($payload->commission->currency)
                : null;

            return new TIOrder(
                empty($payload->orderId) ? null : $payload->orderId,
                TIOperationEnum::getOperation($payload->operation),
                empty($payload->status) ? null : $payload->status,
                $payload->rejectReason ?? null,
                empty($payload->requestedLots) ? null : $payload->requestedLots,
                empty($payload->executedLots) ? null : $payload->executedLots,
                new TICommission($commissionCurrency, $commissionValue),
                $figi,
                null, // type
                empty($payload->message) ? null : $payload->message,
                empty($payload->price) ? null : $payload->price
            );
        } catch (Exception $ex) {
            $this->logException($ex);
            return null;
        }
    }
}
