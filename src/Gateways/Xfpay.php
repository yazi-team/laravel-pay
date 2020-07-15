<?php

namespace Xiaofan\Pay\Gateways;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Xiaofan\Pay\Contracts\GatewayApplicationInterface;
use Xiaofan\Pay\Contracts\GatewayInterface;
use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidGatewayException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Xfpay\Support;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Xiaofan\Pay\Contracts\Payable;
use Xiaofan\Pay\Contracts\Transferable;
use Xiaofan\Pay\Entity\PurchaseResult;

/**
 * @method Response   wap(array $config)      手机网站支付
 */
class Xfpay implements GatewayApplicationInterface {

    /**
     * Const mode_normal.
     */
    const MODE_NORMAL = 'normal';

    /**
     * Const mode_dev.
     */
    const MODE_DEV = 'dev';
    
    /**
     * Const mode_query.
     */
    const MODE_QUERY = 'query';
    
    const MODE_TRANSFER = 'transfer';
    const MODE_TRANSFER_QUERY = 'transfer_query';

    /**
     * Const mode_service.
     */
    const MODE_SERVICE = 'service';

    /**
     * Const url.
     */
    const URL = [
        self::MODE_NORMAL => 'http://netway.xfzfpay.com:90/api/pay',
        self::MODE_DEV => 'http://netway.xfzfpay.com:90/api/pay',
        self::MODE_QUERY => 'http://query.xfzfpay.com:90/api/queryPayResult',
    ];

    /**
     * Xfpay payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Xfpay gateway.
     *
     * @var string
     */
    protected $gateway;

    /**
     * Bootstrap.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws \Exception
     */
    public function __construct(Config $config) {
        $this->gateway = Support::create($config)->getBaseUri();
        $this->payload = [
            'version' => 'V3.3.0.0',
            'charsetCode' => 'UTF-8',
            'merchNo' => $config->get('app_id'),
            'notifyUrl' => $config->get('notify_url'),
            'notifyViewUrl' => $config->get('return_url'),
            'payType' => $config->get('pay_type'),
        ];
    }

    /**
     * Magic pay.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $method
     * @param array  $charge
     *
     * @throws GatewayException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     * @throws InvalidGatewayException
     * @throws InvalidSignException
     *
     * @return Response|Collection
     */
    public function __call($method, Payable $charge) {
        return $this->pay($method, $charge);
    }

    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $gateway
     * @param array  $charge
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function pay($gateway, Payable $charge) {
        $request = app('request');
        $request->setTrustedProxies($request->getClientIps(), Request::HEADER_X_FORWARDED_ALL);
        $this->payload['randomNum'] = Str::random(8);
        $this->payload['orderNo'] = $charge->getTradeNo();
        $this->payload['goodsName'] = $charge->getSubject();
        $this->payload['amount'] = (string)($charge->getAmount());
        $this->payload['sign'] = Support::generateSign($this->payload);
//        dump($this->payload);
        $json = Support::json_encode_ex($this->payload);
        $gateway = get_class($this) . '\\' . Str::studly($gateway) . 'Gateway';
        $this->payload = [
            'merchNo' => $this->payload['merchNo'],
            'data' => Support::encode_pay($json)
        ];
//        dump($this->payload);
        if (class_exists($gateway)) {
            return $this->makePay($gateway);
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] not exists");
    }
    /**
     * Pay an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array  $transfer
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    public function transfer(Transferable $transfer) {
        $gateway = 'transfer';
        $request = app('request');
        $request->setTrustedProxies($request->getClientIps(), Request::HEADER_X_FORWARDED_ALL);
        $NoticeUrl = $this->payload['NoticeUrl'];
        unset($this->payload['NoticeUrl']);
        $data = [
            "MerchantOrderNo" => $transfer->getTransferNo(),
            "BankCode" => Support::getBankCode($transfer->getAccount(), $transfer->getExtra('bank_name')),
            "PayeeType" => "0",
            "PayeeName" => $transfer->getRealName(),
            "PayeeAccount" => $transfer->getAccount(),
            "Amount" => sprintf("%.2f", intval($transfer->getAmount()) / 100),
            'NoticeUrl' => $NoticeUrl,
            "Remark" => $transfer->getRemark()
        ];
        
        $this->payload['Data'] = Support::rsa_encrypt(http_build_query($data));
        $this->payload['Sign'] = Support::generateSign($this->payload);
//        dump($this->payload);die;
        $gateway = get_class($this) . '\\' . Str::studly($gateway) . 'Gateway';
        \Illuminate\Support\Facades\Log::info($gateway);
        if (class_exists($gateway)) {
            return $this->makePay($gateway);
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] not exists");
    }

    /**
     * Verify sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array|null $data
     *
     * @throws InvalidSignException
     * @throws InvalidConfigException
     */
    public function verify($data = null, bool $refund = false): PurchaseResult {
        if (is_null($data)) {
            $request = Request::createFromGlobals();

            $data = $request->request->count() > 0 ? $request->request->all() : $request->query->all();
            if (isset($data['data'])) {
                $data = Support::decode($data['data']);
            }
        }
//        Events::dispatch(new Events\RequestReceived('Xfpay', '', $data));

        if (Support::verifySign($data)) {
            $data['err_msg'] = '';
            return new PurchaseResult('Xfpay', 
                    $data['orderNo'], 
                    $data['orderNo'], 
                    $data['amount'], 
                    $data['payStateCode'] === '00', 
                    date("Y-m-d H:i:s"), $data);
        }

        Events::dispatch(new Events\SignFailed('Xfpay', '', $data));

        throw new InvalidSignException('Xfpay Sign Verify FAILED', $data);
    }

    /**
     * Query an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string $order
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function find($order, string $type = 'wap'): Collection {
        $data = ['OrderNo' => $order];
        $this->payload['Data'] = Support::rsa_encrypt(http_build_query($data));
        $this->payload['Sign'] = Support::generateSign($this->payload);
        
        Events::dispatch(new Events\MethodCalled('Xfpay', 'Find', $this->gateway, $this->payload));

        return Support::requestApi($this->payload, "get");
    }

    /**
     * Refund an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function refund(array $order): Collection {
        
    }

    /**
     * Cancel an order.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param array|string $order
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function cancel($order): Collection {
    }

    /**
     * Close an order.
     *
     * @param string|array $order
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public function close($order): Collection {
        
    }

    /**
     * Reply success to alipay.
     *
     * @author yansongda <me@yansongda.cn>
     */
    public function success(): Response {
        Events::dispatch(new Events\MethodCalled('Xfpay', 'Success', $this->gateway));

        return Response::create('SUCCESS');
    }

    /**
     * Make pay gateway.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws InvalidGatewayException
     *
     * @return Response|Collection
     */
    protected function makePay(string $gateway) {
        $app = new $gateway();

        if ($app instanceof GatewayInterface) {
            return $app->pay($this->gateway, array_filter($this->payload, function ($value) {
                                return '' !== $value && !is_null($value);
                            }));
        }

        throw new InvalidGatewayException("Pay Gateway [{$gateway}] Must Be An Instance Of GatewayInterface");
    }

}
