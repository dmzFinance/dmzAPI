<?php
//20250707 阿里云kms
defined('SYSTEM_FLAG') or exit('Access Invalid!');

use AlibabaCloud\Dkms\Gcs\Sdk\Client as AlibabaCloudDkmsGcsSdkClient;
use AlibabaCloud\Dkms\Gcs\OpenApi\Models\Config as AlibabaCloudDkmsGcsOpenApiConfig;
use AlibabaCloud\Dkms\Gcs\OpenApi\Util\Models\RuntimeOptions;
use AlibabaCloud\Dkms\Gcs\Sdk\Models\GetSecretValueRequest;

class aliyun_kms {
    public $client = null;
    public $config = null;
    public $respose_log = [];
    public $errors = [];

    //取得实例 @return object
    public static function getInstance(){
        $args = func_get_args();
        return get_obj_instance(__CLASS__,null, $args);
    }

    public function __construct(){
        $this->config = config::get('aliyun_kms');
        $this->init();
    }

    public function init(){
        $this->client = $this->getDkmsGcsSdkClient();
    }

    function getSecretValue($secretName){
        //构建获取凭据请求
        $getSecretValueRequest = new GetSecretValueRequest([
            'secretName' => $secretName,
        ]);

        $runtimeOptions = new RuntimeOptions();
        //$runtimeOptions->ignoreSSL = true;  //忽略服务端证书

        try {
            // 调用获取凭据接口
            $getSecretValueResponse = $this->client->getSecretValueWithOptions($getSecretValueRequest, $runtimeOptions);
            //凭据值
            $_secretData = $getSecretValueResponse->secretData;
            $this->respose_log[$secretName] = $getSecretValueResponse->toMap();
            return $_secretData;
        } catch (\Exception $error) {
            if ($error instanceof \AlibabaCloud\Tea\Exception\TeaError) {
                $this->errors[$secretName]['error_info'] = $error->getErrorInfo();
            }
            $this->errors[$secretName]['message'] = $error->getMessage();
            $this->errors[$secretName]['trace'] = $error->getTraceAsString();
        }
        return null;
    }

    function getDkmsGcsSdkClient(){
        // 构建KMS实例SDK Client配置
        $config = new AlibabaCloudDkmsGcsOpenApiConfig();
        //连接协议请设置为"https"。KMS实例服务仅允许通过HTTPS协议访问。
        $config->protocol = 'https';
        //Client Key。
        $config->clientKeyContent = $this->config['clientKeyContent'];
        //Client Key口令。
        $config->password = $this->config['password'];
        //设置endpoint为<your KMS Instance Id>.cryptoservice.kms.aliyuncs.com。
        $config->endpoint = $this->config['endpoint'];
        // 实例CA证书
        $config->caFilePath = $this->config['caFilePath'];

        // 构建KMS实例SDK Client对象
        return new AlibabaCloudDkmsGcsSdkClient($config);
    }

}
