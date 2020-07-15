<?php

namespace Xiaofan\Pay\Gateways\Xfpay;

use Xiaofan\Pay\Events;
use Xiaofan\Pay\Exceptions\GatewayException;
use Xiaofan\Pay\Exceptions\InvalidArgumentException;
use Xiaofan\Pay\Exceptions\InvalidConfigException;
use Xiaofan\Pay\Exceptions\InvalidSignException;
use Xiaofan\Pay\Gateways\Xfpay;
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
     * Xfpay gateway.
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
        $this->baseUri = Xfpay::URL[$config->get('mode', Xfpay::MODE_NORMAL)];
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
     * Get Xfpay API result.
     *
     * @author yansongda <me@yansongda.cn>
     *
     * @throws GatewayException
     * @throws InvalidConfigException
     * @throws InvalidSignException
     */
    public static function requestApi(array $data, $method = 'post'): Collection {
        Events::dispatch(new Events\ApiRequesting('Xfpay', '', self::$instance->getBaseUri(), $data));

        $data = array_filter($data, function ($value) {
            return ('' == $value || is_null($value)) ? false : true;
        });
        $result = json_decode(self::$instance->{$method}('', $data), true);
        Log::debug('Xfpay requestApi Content', [$result]);
        Events::dispatch(new Events\ApiRequested('Xfpay', '', self::$instance->getBaseUri(), $result));

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
        $sign = strtoupper(md5(self::json_encode_ex($params) . self::$instance->app_key));
        
        Log::debug('Xfpay Generate Sign', [$params, $sign]);

        return $sign;
    }

    public static function json_encode_ex($value) {
        if (version_compare(PHP_VERSION, '5.4.0', '<')) {
            $str = json_encode($value);
            $str = preg_replace_callback("#\\\u([0-9a-f]{4})#i", "replace_unicode_escape_sequence", $str);
            $str = stripslashes($str);
            return $str;
        } else {
            return json_encode($value, 320);
        }
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
        $sign = self::generateSign($data);
        Log::debug('Xfpay notify Generate Sign', [$data, $sign]);
        if (isset($data['sign']) && $sign == $data['sign']) {
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
        if (!empty($result) && is_array($result) && $result['stateCode'] === "00") {
            return new Collection($result);
        } else {
            Events::dispatch(new Events\SignFailed('Xfpay', '', $result));
            throw new GatewayException('Get Xfpay API Error:' . 
                    (isset($result['msg']) ? $result['msg'] : 'unkown'), 
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
    
    
    public static function encode_pay($data) {#加密
        $publicKey = self::$instance->public_key;

        if (is_null($publicKey)) {
            throw new InvalidConfigException('Missing Xfpay Config -- [public_key]');
        }

        $public_key = "-----BEGIN PUBLIC KEY-----\n".
            wordwrap($publicKey, 64, "\n", true).
            "\n-----END PUBLIC KEY-----";
        
        
        $pu_key = openssl_pkey_get_public($public_key);
        if ($pu_key == false) {
            throw new InvalidConfigException('Missing Xfpay Config -- [public_key]');
        }
        $encryptData = '';
        $crypto = '';
        foreach (str_split($data, 117) as $chunk) {
            openssl_public_encrypt($chunk, $encryptData, $pu_key);
            $crypto = $crypto . $encryptData;
        }

        $crypto = base64_encode($crypto);
        return $crypto;
    }
    
    public static function decode($data) {#加密
        $privateKey = self::$instance->private_key;
        if (is_null($privateKey)) {
            throw new InvalidConfigException('Missing Xfpay Config -- [private_key]');
        }

        $private_key = "-----BEGIN PRIVATE KEY-----\n".
            wordwrap($privateKey, 64, "\n", true).
            "\n-----END PRIVATE KEY-----";
        
        $pr_key = openssl_get_privatekey($private_key);
        if ($pr_key == false) {
            throw new InvalidConfigException('Missing Xfpay Config -- [private_key]');
        }
        
        $data = base64_decode($data);
        $crypto = '';
        foreach (str_split($data, 128) as $chunk) {
            openssl_private_decrypt($chunk, $decryptData, $pr_key);
            $crypto .= $decryptData;
        }
        return json_decode($crypto, true);
    }

    public static function rsa_encrypt($str) {
        
        $privateKey = self::$instance->private_key;

        if (is_null($privateKey)) {
            throw new InvalidConfigException('Missing Xfpay Config -- [private_key]');
        }

        $privateKey = "-----BEGIN PRIVATE KEY-----\n".
            wordwrap($privateKey, 64, "\n", true).
            "\n-----END PRIVATE KEY-----";
        
        $encrypted = '';
        $plainData = str_split($str, 117);
        foreach ($plainData as $chunk) {
            $partialEncrypted = '';

            //using for example OPENSSL_PKCS1_PADDING as padding
            $encryptionOk = openssl_private_encrypt($chunk, $partialEncrypted, $privateKey, OPENSSL_PKCS1_PADDING);

            if ($encryptionOk === false) {
                return false;
            }//also you can return and error. If too big this will be false
            $encrypted .= $partialEncrypted;
        }
        return base64_encode($encrypted); //encoding the whole binary String as MIME base 64
    }

    public static function rsa_decrypt($str) {
        
        $publicKey = self::$instance->public_key;

        if (is_null($publicKey)) {
            throw new InvalidConfigException('Missing Xfpay Config -- [public_key]');
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
    
    public static function getBankCode($card_num, $bank_name = "") {
        $bank_code = [
            "工商银行" => "ICBC",
            "建设银行" => "CCB",
            "农业银行" => "ABC",
            "邮政储蓄银行" => "PSBC",
            "中国银行" => "BOC",
            "交通银行" => "BCM",
            "招商银行" => "CMB",
            "光大银行" => "CEB",
            "浦发银行" => "SPDB",
            "华夏银行" => "HXB",
            "广东发展银行" => "GDB",
            "中信银行" => "CNCB",
            "兴业银行" => "CIB",
            "民生银行" => "CMBC",
            "杭州银行" => "HZB",
            "上海银行" => "SHB",
            "宁波银行" => "NBB",
            "平安银行" => "PAB",
            "上海农商银行" => "SHNSB",
            "企业银行" => "QYB",
            "云南省农村信用社" => "YNNXB",
            "海南省农村信用社" => "HNNXB",
            "广西农村信用社" => "GXNXB",
            "湖北农信" => "HBNXB",
            "福建省农村信用社" => "FJNXB",
            "江苏省农村信用社联合社" => "JSNXB",
            "徽商银行" => "WSB",
            "恒丰银行" => "HFB",
            "重庆农村商业银行" => "CQNSB",
            "常熟农村商业银行" => "CSNSB",
            "吴江农村商业银行" => "WJNSB",
            "昆仑银行" => "KLB",
            "乌鲁木齐市商业银行" => "WLMQSB",
            "宁夏银行" => "NXB",
            "青海银行" => "QHB",
            "兰州银行" => "LANZB",
            "富滇银行" => "FDB",
            "贵阳银行" => "GYB",
            "绵阳市商业银行" => "MYSB",
            "德阳银行" => "DYB",
            "攀枝花市商业银行" => "PZHSB",
            "重庆银行" => "CQB",
            "柳州银行" => "LIUZB",
            "汉口银行" => "HANKB",
            "天津农商银行" => "TJNSB",
            "武汉农村商业银行" => "WHNSB",
            "洛阳银行" => "LUOYB",
            "郑州银行" => "ZZB",
            "北京银行" => "BJB",
            "天津银行" => "TJB",
            "广州银行" => "GZB",
            "珠海华润银行" => "ZHHRB",
            "东莞银行" => "DGB",
            "广州农村商业银行" => "GZNSB",
            "顺德农村商业银行" => "SDNSB",
            "德州银行" => "DEZB",
            "潍坊银行" => "LNGFB",
            "赣州银行" => "GANZB",
            "福建海峡银行" => "FJHXB",
            "浙江民泰商业银行" => "ZJMTSB",
            "平顶山银行" => "PDSB",
            "渤海银行" => "BHB",
            "北京农村商业银行" => "BJNSB",
            "太仓农商行" => "TCNSB",
            "东莞农村商业银行" => "DGNSB",
            "四川省联社" => "SCLS",
            "新韩银行" => "XHB",
            "韩亚银行" => "HYB",
            "大连银行" => "DLB",
            "鞍山市商业银行" => "ANSSB",
            "锦州银行" => "JINZB",
            "葫芦岛银行" => "HLDB",
            "温州银行" => "WENZB",
            "湖州银行" => "HUZB",
            "浙江稠州商业银行" => "ZJZZSB",
            "浙江泰隆商业银行" => "ZJLTSB",
            "厦门银行" => "XMB",
            "南昌银行" => "NANCB",
            "上饶银行" => "SHNGRB",
            "青岛银行" => "QDB",
            "齐商银行" => "QISB",
            "东营银行" => "DNGYB",
            "烟台银行" => "YANTB",
            "济宁银行" => "JINB",
            "泰安市商业银行" => "TAIASB",
            "莱商银行" => "LAISB",
            "威海市商业银行" => "WEIHSB",
            "临商银行" => "LINTB",
            "日照银行" => "RIZB",
            "长沙银行" => "CSB",
            "广西北部湾银行" => "GXBBWB",
            "自贡市商业银行" => "ZGSB",
            "昆山农村商业银行" => "KUNSNSB",
            "张家港农村商业银行" => "ZJGNSB",
            "浙商银行" => "ZSB",
            "苏州银行" => "SZB",
            "鄞州银行" => "JNZB",
            "安徽省农村信用社" => "AHNXS",
            "黄河农村商业银行" => "HHNSB",
            "河北银行" => "HBB",
            "邯郸市商业银行" => "HANDSB",
            "邢台银行" => "XNGTB",
            "张家口市商业银行" => "ZJKSB",
            "承德银行" => "CHGDEB",
            "沧州银行" => "CNGZB",
            "晋商银行网上银行" => "JINSB",
            "晋城银行" => "JINCB",
            "内蒙古银行" => "NMGB",
            "包商银行" => "BAOSB",
            "鄂尔多斯银行" => "EEDSB",
            "营口银行" => "YNGKB",  
        ];
        if (isset($bank_code[$bank_name])) {
            return $bank_code[$bank_name];
        }
        
        $result = file_get_contents("https://ccdcapi.alipay.com/validateAndCacheCardInfo.json?_input_charset=utf-8&cardNo={$card_num}&cardBinCheck=true");
        $result = json_decode($result);
        if (empty($result) || !$result->validated) {
            return $bank_name;
        }else{
            return $result->bank;
        }
    }
}
