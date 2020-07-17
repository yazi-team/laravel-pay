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
use Xiaofan\Pay\Gateways\Xmfdf\Support;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Xiaofan\Pay\Contracts\Payable;
use Xiaofan\Pay\Contracts\Transferable;
use Xiaofan\Pay\Entity\PurchaseResult;

/**
 * @method Response   wap(array $config)      手机网站支付
 */
class Xmfdf implements GatewayApplicationInterface {

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
        self::MODE_TRANSFER => 'http://47.244.151.121:6901/service/payment/api/payment',
        self::MODE_TRANSFER_QUERY => 'http://47.244.151.121:6901/service/payment/api/detail',
    ];

    /**
     * Xmfdf payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Xmfdf gateway.
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
            'shopId' => $config->get('app_id'),
            'callBackUrl' => $config->get('notify_url'),
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
        $callBackUrl = $this->payload['callBackUrl'];
        unset($this->payload['callBackUrl']);
        $this->payload['amount'] = sprintf("%.2f", intval($transfer->getAmount()) / 100);
        $this->payload['len'] = "1";
        $this->payload['data'] = [
            [
                "bankAccount" => $transfer->getRealName(),//姓名
                "amount" => sprintf("%.2f", intval($transfer->getAmount()) / 100),//代付金额
                "orderNo" => $transfer->getTransferNo(),//订单号
                "bankMark" => "ZFB",//银行简拼 如果为支付宝传ZFB
                "bankName" => "支付宝",//银行名称 如果为支付宝传支付宝
                "cardNo" => $transfer->getAccount(),//银行卡号  如果为支付宝传支付宝账号
                "callBackUrl" => $callBackUrl//回调地址
            ]
        ];
        $this->payload['createTime'] = date("Y-m-d H:i:s");
        
        $sign_data    = [
            "shopId"     => $this->payload['shopId'],
            "createTime" => $this->payload['createTime'],
            "amount"     => $this->payload['amount'],
            "len"        => $this->payload['len'],
        ];
        $this->payload['sign'] = Support::generateSign($sign_data);
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
            $request = new Request();
            $content = $request->getContent();
            $data = json_decode($content, true);
        }

        Events::dispatch(new Events\RequestReceived('Xmfdf', '', $data));

        \Illuminate\Support\Facades\Log::info("Xmfdf notify data", [$data]);
        if (Support::verifySign($data)) {
            $find = $this->find($data['orderNo']);
            \Illuminate\Support\Facades\Log::info("Xmfdf notify find data", [$find->toArray()]);
            if ($find['header']['code'] === 0) {
                $find_data = $find['data'];
            }else{
                throw new InvalidSignException('Xmfdf Sign Verify FAILED', $data);
            }
            $data['err_msg'] = '转账失败';
            return new PurchaseResult('xmfdf', 
                    $find_data['orderNo'], 
                    $find_data['serverNo'],
                    0, 
                    trim($data['status']) === 'PAYED', 
                    date('Y-m-d H:i:s'), $data);
        }

        Events::dispatch(new Events\SignFailed('Xmfdf', '', $data));

        throw new InvalidSignException('Xmfdf Sign Verify FAILED', $data);
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
        $this->gateway = $this->gateway . "/{$this->payload['shopId']}/{$order}";
        Events::dispatch(new Events\MethodCalled('Xmfdf', 'Find', $this->gateway, $this->payload));
        return Support::requestApi($this->payload, 'get', $this->gateway);
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
        Events::dispatch(new Events\MethodCalled('Xmfdf', 'Success', $this->gateway));

        return Response::create('OK');
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
