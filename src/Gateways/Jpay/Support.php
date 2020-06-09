<?php

namespace Xiaofan\Pay\Gateways\Jpay;

use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Jpay;
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
     * Jpay gateway.
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
        $this->baseUri = Jpay::URL[$config->get('mode', Jpay::MODE_NORMAL)];
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
     * Get Jpay API result.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public static function requestApi(array $data): Collection {
        Events::dispatch(new Events\ApiRequesting('Jpay', '', self::$instance->getBaseUri(), $data));

        $data = array_filter($data, function ($value) {
            return ('' == $value || is_null($value)) ? false : true;
        });

        $result = self::$instance->post('', $data);
        
        Events::dispatch(new Events\ApiRequested('Jpay', '', self::$instance->getBaseUri(), $result));

        return self::processingApiResult($data, $result);
    }

    /**
     * Generate sign.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws InvalidConfigException
     */
    public static function generateSign(array $params, $type = 'pay'): string {
        $key_id = self::$instance->app_key;
        $arr = [
            'amount' => $params['amount'],
            'out_trade_no' => $params['out_trade_no'],
            'timestamp' => $params['timestamp'],
        ]; 
        if($type == 'pay') {
            $arr['thoroughfare'] = $params['thoroughfare']; 
        }
        
        ksort($arr);
        $values = array_values($arr);
        $str = implode('', $values);
        $data = md5($str);
        $cipher = '';
        $key[] = "";
        $box[] = "";
        $pwd_length = strlen($key_id);
        $data_length = strlen($data);
        for ($i = 0; $i < 256; $i++) {
            $key[$i] = ord($key_id[$i % $pwd_length]);
            $box[$i] = $i;
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j = ($j + $box[$i] + $key[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $data_length; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;

            $tmp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;

            $k = $box[(($box[$a] + $box[$j]) % 256)];
            $cipher .= chr(ord($data[$i]) ^ $k);
        }

        $sign = md5($cipher);
        Log::debug("Jpay Generate {$type} Sign", [$params, $sign]);

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
        $sign = self::generateSign($data, 'notify');
        if ($sign == $data["sign"]) {
            return true;
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
//        dump($result);die;
        if ( isset($result['code']) && 200 === $result['code'] ) {
            return new Collection($result['data']);
        }else{
            Events::dispatch(new Events\SignFailed('Jpay', '', $result));
            throw new GatewayException('Get Jpay API Error:' . $result['msg'], $result);
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
