<?php

namespace Xiaofan\Pay\Gateways\Jiupay;

use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Jiupay;
use Xiaofan\Pay\Log;
use Yansongda\Supports\Arr;
use Yansongda\Supports\Collection;
use Yansongda\Supports\Config;
use Yansongda\Supports\Str;
use Yansongda\Supports\Traits\HasHttpRequest;

/**
 * @author yansongda <me@yansongda.cn>
 *
 * @property string app_id alipay app_id
 * @property string app_key
 * @property string private_key
 * @property array http http options
 * @property string mode current mode
 * @property array log log options
 * @property string pid ali pid
 */
class Support {

    use HasHttpRequest;

    /**
     * Jiupay gateway.
     *
     * @var string
     */
    protected $baseUri;

    /**
     * Config.
     *
     * @var Config
     */
    protected $config;

    /**
     * Instance.
     *
     * @var Support
     */
    private static $instance;

    /**
     * Bootstrap.
     *
     * @author yansongda <me@yansongda.cn>
     */
    private function __construct(Config $config) {
        $this->baseUri = Jiupay::URL[$config->get('mode', Jiupay::MODE_NORMAL)];
        $this->config = $config;

        $this->setHttpOptions();
    }

    /**
     * __get.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param $key
     *
     * @return mixed|Config|null
     */
    public function __get($key) {
        return $this->getConfig($key);
    }

    /**
     * create.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return Support
     */
    public static function create(Config $config) {
        if ('cli' === php_sapi_name() || !(self::$instance instanceof self)) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * getInstance.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws InvalidArgumentException
     *
     * @return Support
     */
    public static function getInstance() {
        if (is_null(self::$instance)) {
            throw new InvalidArgumentException('You Should [Create] First Before Using');
        }

        return self::$instance;
    }

    /**
     * clear.
     *
     * @author yansongda <me@yansongda.cn>
     */
    public function clear() {
        self::$instance = null;
    }

    /**
     * Get Jiupay API result.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public static function requestApi(array $data): Collection {
        Events::dispatch(new Events\ApiRequesting('Jiupay', '', self::$instance->getBaseUri(), $data));

        $data = array_filter($data, function ($value) {
            return ('' == $value || is_null($value)) ? false : true;
        });

        $result = self::$instance->post('', $data);
        
        Events::dispatch(new Events\ApiRequested('Jiupay', '', self::$instance->getBaseUri(), $result));

        return self::processingApiResult($data, $result);
    }

    /**
     * Generate sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws InvalidConfigException
     */
    public static function generateSign(array $params): string {

        ksort($params);
        $md5str = urldecode(http_build_query($params));
        $sign = strtoupper(md5($md5str . "&key=" . self::$instance->app_key));
        Log::debug('Jiupay Generate Sign', [$params, $sign]);

        return $sign;
    }

    /**
     * Verify sign.
     *
     * @author yansongda <me@yansonga.cn>
     *
     * @param bool        $sync
     * @param string|null $sign
     *
     * @throws InvalidConfigException
     */
    public static function verifySign(array $data, $sync = false, $sign = null): bool {
        $Md5key = self::$instance->app_key;
        $ReturnArray = array( // 返回字段
            "memberid" => $data["memberid"], // 商户号[下游商户号]
            "orderid" => $data["orderid"], // 商户订单号[下游订单号]
            "transaction_id" => $data["transaction_id"], // 系统订单号[本系统订单号]
            "amount" => $data["amount"], // 交易金额[支付金额]
            "datetime" => $data["datetime"], // 交易时间[通知时间]
            "returncode" => $data["returncode"] // 交易状态[响应状态]
        );

        ksort($ReturnArray);
        reset($ReturnArray);
        $md5str = "";
        foreach ($ReturnArray as $key => $val) {
            $md5str = $md5str . $key . "=" . $val . "&";
        }
        $sign = strtoupper(md5($md5str . "key=" . $Md5key));
        if ($sign == $data["sign"]) {
            if ($data["returncode"] == "0000") {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convert encoding.
     *
     * @author yansongda <me@yansonga.cn>
     *
     * @param string|array $data
     * @param string       $to
     * @param string       $from
     */
    public static function encoding($data, $to = 'utf-8', $from = 'gb2312'): array {
        return Arr::encoding((array) $data, $to, $from);
    }

    /**
     * Get service config.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param string|null $key
     * @param mixed|null  $default
     *
     * @return mixed|null
     */
    public function getConfig($key = null, $default = null) {
        if (is_null($key)) {
            return $this->config->all();
        }

        if ($this->config->has($key)) {
            return $this->config[$key];
        }

        return $default;
    }

    /**
     * Get Base Uri.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @return string
     */
    public function getBaseUri() {
        return $this->baseUri;
    }

    /**
     * processingApiResult.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @param $data
     * @param $result
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    protected static function processingApiResult($data, $result): Collection {
//        dump($result);
        if ( (isset($result['code']) && '0000' === $result['code']) || (isset($data['find']) && !isset($result['code'])) ) {
            return new Collection($result);
        }else{
            Events::dispatch(new Events\SignFailed('Jiupay', '', $result));
            throw new GatewayException('Get Jiupay API Error:' . $result['msg'], $result);
        }
    }

    /**
     * Set Http options.
     *
     * @author yansongda <me@yansongda.cn>
     */
    protected function setHttpOptions(): self {
        if ($this->config->has('http') && is_array($this->config->get('http'))) {
            $this->config->forget('http.base_uri');
            $this->httpOptions = $this->config->get('http');
        }

        return $this;
    }

    public static function getPayType($method) {
        $types = [
            'wechat' => '901',
            'wechat_scan' => '902',
            'alipay' => '903',
            'alipay_scan' => '904',
            'qq' => '905',
            'qq_scan' => '906',
            'un' => '907',
            'un_kj' => '908',
            'un_v1' => '909',
            'un_scan' => '910',
        ];
        return isset($types[$method]) ? $types[$method] : '';
    }

    public static function getPayFormat($method) {
        $types = [
            'wechat' => 'pay_str',
            'wechat_scan' => 'pay_str',
            'alipay' => 'pay_str',
            'alipay_scan' => 'pay_str',
            'qq' => 'pay_str',
            'qq_scan' => 'pay_str',
            'un' => 'jump_page',
            'un_kj' => 'jump_page',
            'un_v1' => 'jump_page',
            'un_scan' => 'pay_str',
        ];
        return isset($types[$method]) ? $types[$method] : '';
    }

}
