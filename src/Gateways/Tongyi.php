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
use Xiaofan\Pay\Gateways\Tongyi\Support;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Xiaofan\Pay\Contracts\Payable;
use Xiaofan\Pay\Contracts\Transferable;
use Xiaofan\Pay\Entity\PurchaseResult;
use Xiaofan\Pay\Entity\TransferResult;
use Illuminate\Support\Facades\Log;
/**
 * @method Response   wap(array $config)      手机网站支付
 */
class Tongyi implements GatewayApplicationInterface {

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
        self::MODE_NORMAL => '',
        self::MODE_DEV => '',
        self::MODE_QUERY => '',
        self::MODE_TRANSFER => 'http://daifu.zhoumuming.com/api/returndata.php',
        self::MODE_TRANSFER_QUERY => 'http://daifu.zhoumuming.com/api/returndata.php',
    ];

    /**
     * Tongyi payload.
     *
     * @var array
     */
    protected $payload;

    /**
     * Tongyi gateway.
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
            'client_id' => $config->get('app_id'),
            'notify_url' => $config->get('notify_url'),
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
        
        $this->payload['type'] = 'get_withdraws';
        
        $body = array(
            "money" => sprintf("%.2f", intval($transfer->getAmount()) / 100),//金额，单位元,保留2位小数
            "alipayname" => $transfer->getRealName(),//收款人姓名
            "alipaynum" => $transfer->getAccount(),//收款人银行账号
            "out_logno" => $transfer->getTransferNo(),//商户的支付key,用于标识商户身份。可登陆商户门户，在账户信息中可以查看（对应商户号）
            "notifyurl" => $this->payload['notify_url'],//收款银行编码,具体见接口文档
        );
        unset($this->payload['notify_url']);
        
        $this->payload['sign'] = Support::generateSign($body);
        $this->payload['body'] = json_encode($body);
//        dump($this->payload);die;
        $gateway = get_class($this) . '\\' . Str::studly($gateway) . 'Gateway';
        Log::info($gateway);
        try {
            if (class_exists($gateway)) {
                return $this->makePay($gateway);
            }
        } catch (\Exception $exc) {
            $find = $this->find($body['out_logno'], 'transfer');
            if ($find['code'] != 200) {
                Log::error($exc->getMessage());
                throw new InvalidGatewayException($find['message']);
            }
            $data = $find['data'];
            return new TransferResult($data['clearno'], date('Y-m-d H:i:s'));
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

        Events::dispatch(new Events\RequestReceived('Tongyi', '', $data));

        if (Support::verifySign($data)) {
            $find = $this->find(['clearno' => $data['clearno']], 'transfer');
            if (empty($find)) {
                throw new InvalidSignException('Tongyi Sign Verify FAILED', $data);
            }
            $data['err_msg'] = '下单失败';
            $data['order_id'] = isset($find['data']['clearno']) ? $find['data']['clearno'] : '';
            $data['out_biz_no'] = isset($find['data']['out_logno']) ? $find['data']['out_logno'] : '';
            return new PurchaseResult('tongyi', 
                    $data['out_biz_no'], 
                    $data['order_id'],
                    0, 
                    false, 
                    date("Y-m-d H:i:s"), $data);
        }

        Events::dispatch(new Events\SignFailed('Tongyi', '', $data));

        throw new InvalidSignException('Tongyi Sign Verify FAILED', $data);
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
        
        $gateway = get_class($this).'\\'.Str::studly($type).'Gateway';

        if (!class_exists($gateway) || !is_callable([new $gateway(), 'find'])) {
            throw new GatewayException("{$gateway} Done Not Exist Or Done Not Has FIND Method");
        }

        $config = call_user_func([new $gateway(), 'find'], $order);
        
        unset($this->payload['notify_url']);
        $this->payload['type'] = $config['type'];
        
        if (is_array($order)) {
            $body = $order;
        }else{
            $body = [
                "out_logno" => $order,
            ];
        }
        $this->payload['sign'] = Support::generateSign($body);
        $this->payload['body'] = json_encode($body);
        $this->payload['find'] = '1';
        
        Events::dispatch(new Events\MethodCalled('Tongyi', 'Find', $this->gateway, $this->payload));

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
        Events::dispatch(new Events\MethodCalled('Tongyi', 'Success', $this->gateway));

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
