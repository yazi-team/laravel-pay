<?php

namespace Xiaofan\Pay\Gateways\Xincheng;

use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Xincheng;
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
     * Xincheng gateway.
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
        $this->baseUri = Xincheng::URL[$config->get('mode', Xincheng::MODE_NORMAL)];
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
     * Get Xincheng API result.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public static function requestApi(array $data): Collection {
        Events::dispatch(new Events\ApiRequesting('Xincheng', '', self::$instance->getBaseUri(), $data));

        $data = array_filter($data, function ($value) {
            return ('' == $value || is_null($value)) ? false : true;
        });
        $headers = [
            'Token' => self::$instance->token,        
        ];
        $secretData = self::public_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE));
        $result = self::$instance->post('', $secretData, [
            'headers' => $headers
        ]);
        Log::debug('Xincheng requestApi Content', [$result]);
        Events::dispatch(new Events\ApiRequested('Xincheng', '', self::$instance->getBaseUri(), $result));

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
        $sign = md5($md5str . "&" . self::$instance->app_key);
        Log::debug('Xincheng Generate Sign', [$params, $sign]);

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
        if (!empty($data)) {
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
        if (!empty($result) && is_array($result) && isset($result['status']) && $result['status'] == 1) {
            return new Collection($result);
        } else {
            Events::dispatch(new Events\SignFailed('Xincheng', '', $result));
            throw new GatewayException('Get Xincheng API Error:' . 
                    (isset($result['msg']) ? 
                    (is_array($result['msg']) ? json_encode($result['msg']) : $result['msg']) : 
                    'unkown'), 
                $result);
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
    
    public static function public_encrypt($str) {
        
        $publicKey = self::$instance->public_key;

        if (is_null($publicKey)) {
            throw new InvalidConfigException('Missing Xincheng Config -- [public_key]');
        }

        $public_key = "-----BEGIN PUBLIC KEY-----\n".
            wordwrap($publicKey, 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
        
        $encrypted = '';
        $plainData = str_split($str, 100);
        foreach ($plainData as $chunk) {
            $partialEncrypted = '';

            //using for example OPENSSL_PKCS1_PADDING as padding
            $encryptionOk = openssl_public_encrypt($chunk, $partialEncrypted, $public_key, OPENSSL_PKCS1_PADDING);

            if ($encryptionOk === false) {
                return false;
            }//also you can return and error. If too big this will be false
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted); //encoding the whole binary String as MIME base 64
    }

    public static function public_decrypt($str) {
        
        $publicKey = self::$instance->public_key;

        if (is_null($publicKey)) {
            throw new InvalidConfigException('Missing Xincheng Config -- [public_key]');
        }

        $public_key = "-----BEGIN PUBLIC KEY-----\n".
            wordwrap($publicKey, 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
        
        
        $decrypted = '';

        //decode must be done before spliting for getting the binary String
        $data = str_split(base64_decode($str), 128);

        foreach ($data as $chunk) {
            $partial = '';

            //be sure to match padding
            $decryptionOK = openssl_public_decrypt($chunk, $partial, $public_key, OPENSSL_PKCS1_PADDING);

            if ($decryptionOK === false) {
                return false;
            }//here also processed errors in decryption. If too big this will be false
            $decrypted .= $partial;
        }
        return $decrypted;
    }

}
