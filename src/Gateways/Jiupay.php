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
use Xiaofan\Pay\Gateways\Jiupay\Support;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Xiaofan\Pay\Contracts\Payable;
use Xiaofan\Pay\Entity\PurchaseResult;

/**
 * @method Response   app(array $config)      APP 支付
 * @method Collection pos(array $config)      刷卡支付
 * @method Collection scan(array $config)     扫码支付
 * @method Collection transfer(array $config) 帐户转账
 * @method Response   wap(array $config)      手机网站支付
 * @method Response   web(array $config)      电脑支付
 * @method Collection mini(array $config)     小程序支付
 */
class Jiupay implements GatewayApplicationInterface {

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
    /**
     * Const mode_query.
     */
    const MODE_TRANSFER = 'transfer';

    /**
     * Const mode_service.
     */
    const MODE_SERVICE = 'service';

    /**
     * Const url.
     */
    const URL = [
        self::MODE_NORMAL => 'http://pay.911pay.vip:8020/v2/pay',
        self::MODE_DEV => 'http://pay.911pay.vip:8020/v2/pay',
        self::MODE_QUERY => 'http://pay.911pay.vip:8020/v1/query',
        self::MODE_TRANSFER => 'http://pay.911pay.vip:8020/v1/agentPay',
    ];

    /**
     * Jiupay payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Jiupay gateway.
     *
     * @var string
     */
    protected $gateway;

    /**
     * extends.
     *
     * @var array
     */
    protected $extends;

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
            'pay_memberid' => $config->get('app_id'),
            'pay_callbackurl' => $config->get('return_url'),
            'pay_notifyurl' => $config->get('notify_url'),
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
//        Events::dispatch(new Events\PayStarting('Jiupay', $gateway, $params));
        $request = new Request();
        $request->setTrustedProxies($request->getClientIps(), Request::HEADER_X_FORWARDED_ALL);
        $this->payload['pay_orderid'] = $charge->getTradeNo();
        $this->payload['pay_amount'] = sprintf("%.2f", intval($charge->getAmount()) / 100);
        $this->payload['pay_applydate'] = $charge->getExtra("orderdate");
        $this->payload['pay_bankcode'] = Support::getPayType($charge->getExtra("method"));
        $this->payload['pay_format'] = Support::getPayFormat($charge->getExtra("method"));
        $this->payload['pay_clientip'] = $request->getClientIp();
        $this->payload['pay_md5sign'] = Support::generateSign($this->payload);
//        dump($this->payload);die;
        $gateway = get_class($this) . '\\' . Str::studly($gateway) . 'Gateway';

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
        }

        Events::dispatch(new Events\RequestReceived('Jiupay', '', $data));

        if (Support::verifySign($data)) {
            $find = $this->find($data['orderid']);
            return new PurchaseResult('jiupay', 
                    $data['orderid'], 
                    $data['transaction_id'], 
                    floatval($find['actualAmount'])*100, 
                    $data['returncode'] === "0000" && $find['status'] == '1', 
                    $data['datetime'], $data);
        }

        Events::dispatch(new Events\SignFailed('Jiupay', '', $data));

        throw new InvalidSignException('Jiupay Sign Verify FAILED', $data);
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
        $this->payload['pay_orderid'] = $order;
        $this->payload['pay_md5sign'] = Support::generateSign($this->payload);
        $this->payload['find'] = '1';
        
        Events::dispatch(new Events\MethodCalled('Jiupay', 'Find', $this->gateway, $this->payload));

        return Support::requestApi($this->payload);
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
        Events::dispatch(new Events\MethodCalled('Jiupay', 'Success', $this->gateway));

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
