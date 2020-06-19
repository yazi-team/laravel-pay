<?php

namespace Xiaofan\Pay\Gateways\Salipay;

use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Salipay;
use Illuminate\Support\Facades\Log;
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
     * Salipay gateway.
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

    protected $timeout = 30.0;
    
    protected $connectTimeout = 30.0;
    /**
     * Bootstrap.
     *
     * @author yansongda <me@yansongda.cn>
     */
    private function __construct(Config $config) {
        $this->baseUri = Salipay::URL[$config->get('mode', Salipay::MODE_NORMAL)];
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
     * Get Salipay API result.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public static function requestApi(array $data): Collection {
        Events::dispatch(new Events\ApiRequesting('Salipay', '', self::$instance->getBaseUri(), $data));

        $data = array_filter($data, function ($value) {
            return ('' == $value || is_null($value)) ? false : true;
        });
        $headers = [
            'Authorization' => 'Bearer ' . self::$instance->token,        
            'Accept'        => 'application/json',
        ];
        $result = self::$instance->post('', $data, [
            'headers' => $headers
        ]);
        Log::debug('Salipay requestApi Content', [$result]);
        Events::dispatch(new Events\ApiRequested('Salipay', '', self::$instance->getBaseUri(), $result));

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
        unset($params['sign']);
        ksort($params);
        $str = "";
        foreach ($params as $item) {
            $str .= $item . "&";
        }
        $str .= self::$instance->app_key;
        $sign = md5($str);
        
        Log::debug("Salipay Generate Sign", [$params, $sign]);
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
        if (isset($data['data'])) {
            $sign = self::generateSign(['order_id' => $data['data']['order_id']]);
        }else{
            return false;
        }

        if (isset($data['data']['sign']) && $sign == $data['data']['sign']) {
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
        if (!empty($result) && is_array($result)) {
            return new Collection($result);
        } else {
            Events::dispatch(new Events\SignFailed('Salipay', '', $result));
            throw new GatewayException('Get Salipay API Error:' . $result['msg'], $result);
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

}
