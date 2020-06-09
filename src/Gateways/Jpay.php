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
use Xiaofan\Pay\Gateways\Jpay\Support;
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
class Jpay implements GatewayApplicationInterface {

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
     * Const mode_service.
     */
    const MODE_SERVICE = 'service';

    /**
     * Const url.
     */
    const URL = [
        self::MODE_NORMAL => 'https://pay007.net/gateway/index/checkpoint.do',
        self::MODE_DEV => 'https://pay007.net/gateway/index/checkpoint.do',
        self::MODE_QUERY => '',
    ];

    /**
     * Jpay payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Jpay gateway.
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
            'account_id' => $config->get('app_id'),
            'success_url' => $config->get('return_url'),
            'error_url' => $config->get('return_url'),
            'callback_url' => $config->get('notify_url'),
            'thoroughfare' => $config->get('pay_type'),
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
//        Events::dispatch(new Events\PayStarting('Jpay', $gateway, $params));
        $request = app('request');
        $request->setTrustedProxies($request->getClientIps(), Request::HEADER_X_FORWARDED_ALL);
        $this->payload['content_type'] = 'json';
        $this->payload['out_trade_no'] = $charge->getTradeNo();
        $this->payload['amount'] = sprintf("%.2f", intval($charge->getAmount()) / 100);
        $this->payload['timestamp'] = time();
        $this->payload['ip'] = $request->getClientIp();
        $this->payload['sign'] = Support::generateSign($this->payload);
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

        Events::dispatch(new Events\RequestReceived('Jpay', '', $data));

        if (Support::verifySign($data)) {
//            $find = $this->find($data['out_trade_no']);
            return new PurchaseResult('jpay', 
                    $data['out_trade_no'], 
                    $data['trade_no'], 
                    floatval($data['pay_amount'])*100, 
                    $data['pay_status'] == '4', 
                    date("Y-m-d H:i:s", $data['pay_time']), $data);
        }

        Events::dispatch(new Events\SignFailed('Jpay', '', $data));

        throw new InvalidSignException('Jpay Sign Verify FAILED', $data);
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
        $this->payload['order_sn'] = $order;
        $this->payload['order_type'] = $type == 'wap' ? 0 : 1;
        $this->payload['sign'] = Support::generateSign($this->payload, 'find');
        
        Events::dispatch(new Events\MethodCalled('Jpay', 'Find', $this->gateway, $this->payload));

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
        Events::dispatch(new Events\MethodCalled('Jpay', 'Success', $this->gateway));

        return Response::create('success');
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
