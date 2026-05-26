<?php

namespace App\Payments;

use App\Models\Order;
use \Curl\Curl;

class LantuPay {
    const ORDER_EXPIRE_SECONDS = 7200;

    private $config;
    private $apiBase = 'https://api.ltzf.cn';

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form()
    {
        return [
            'ltzf_mch_id' => [
                'label' => '商户号',
                'description' => '蓝兔支付商户号',
                'type' => 'input',
            ],
            'ltzf_key' => [
                'label' => '商户密钥',
                'description' => '蓝兔支付商户密钥',
                'type' => 'input',
            ],
            'ltzf_trade_type' => [
                'label' => '支付模式',
                'description' => 'wxpay_native 或 merge_native，默认 wxpay_native',
                'type' => 'input',
            ],
            'ltzf_product_name' => [
                'label' => '商品名称',
                'description' => '将会体现在支付订单中',
                'type' => 'input',
            ],
            'ltzf_developer_appid' => [
                'label' => '开发者应用ID',
                'description' => '可选',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order)
    {
        $tradeType = trim($this->config['ltzf_trade_type'] ?? '') ?: 'wxpay_native';
        $productName = trim($this->config['ltzf_product_name'] ?? '') ?: (config('v2board.app_name', 'V2Board') . ' - 订阅');
        $requiredParams = [
            'mch_id' => $this->config['ltzf_mch_id'],
            'out_trade_no' => $order['trade_no'],
            'total_fee' => number_format($order['total_amount'] / 100, 2, '.', ''),
            'body' => $productName,
            'timestamp' => (string)time(),
            'notify_url' => $order['notify_url']
        ];
        $params = $requiredParams;
        $params['time_expire'] = $this->getTimeExpire($order['trade_no']);

        if (!empty($this->config['ltzf_developer_appid'])) {
            $params['developer_appid'] = $this->config['ltzf_developer_appid'];
        }

        switch ($tradeType) {
            case 'merge_native':
                $url = $this->apiBase . '/api/merge/native';
                $params['return_url'] = $order['return_url'];
                $params['type'] = 'link_url';
                break;
            case 'wxpay_native':
            default:
                $url = $this->apiBase . '/api/wxpay/native';
                $tradeType = 'wxpay_native';
        }

        $params['sign'] = $this->sign($requiredParams);
        $result = $this->post($url, $params);

        $code = $this->value($result, 'code');
        if ($code === null || (int)$code !== 0) {
            abort(500, $this->message($result));
        }

        $payUrl = $this->getPayUrl($tradeType, $result);
        if (!$payUrl) {
            abort(500, '接口请求失败');
        }

        return [
            'type' => 0,
            'data' => $payUrl
        ];
    }

    public function notify($params)
    {
        if (empty($params['sign'])) {
            return false;
        }

        $signParams = [];
        foreach (['code', 'timestamp', 'mch_id', 'order_no', 'out_trade_no', 'pay_no', 'total_fee'] as $key) {
            if (!isset($params[$key]) || $params[$key] === '') {
                return false;
            }
            $signParams[$key] = $params[$key];
        }

        if (!hash_equals($this->sign($signParams), strtoupper($params['sign']))) {
            return false;
        }
        if ((string)$params['code'] !== '0') {
            return false;
        }
        if ((string)$params['mch_id'] !== (string)$this->config['ltzf_mch_id']) {
            return false;
        }
        if (!$this->amountMatches($params)) {
            return false;
        }

        return [
            'trade_no' => $params['out_trade_no'],
            'callback_no' => $params['pay_no'],
            'custom_result' => 'SUCCESS'
        ];
    }

    private function sign($params)
    {
        unset($params['sign']);
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                unset($params[$key]);
            }
        }
        ksort($params);
        reset($params);
        return strtoupper(md5(urldecode(http_build_query($params)) . '&key=' . $this->config['ltzf_key']));
    }

    private function post($url, $params)
    {
        $curl = new Curl();
        $curl->setUserAgent('LantuPay');
        $curl->setOpt(CURLOPT_SSL_VERIFYPEER, 0);
        $curl->setOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $curl->post($url, http_build_query($params));
        $result = $curl->response;
        $error = $curl->error;
        $errorMessage = $curl->errorMessage;
        $curl->close();

        if (!$result) {
            abort(500, '网络异常');
        }
        if (is_string($result)) {
            $decoded = json_decode($result);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result = $decoded;
            }
        }
        if ($error) {
            abort(500, $this->message($result, $errorMessage ?: '网络异常'));
        }

        return $result;
    }

    private function getPayUrl($tradeType, $result)
    {
        $data = $this->value($result, 'data');
        if ($tradeType === 'merge_native') {
            return is_string($data) ? $data : $this->value($data, 'link_url');
        }

        return $this->value($data, 'code_url');
    }

    private function getTimeExpire($tradeNo)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order || (int)$order->status !== 0) {
            abort(500, '订单状态异常');
        }

        // 保持和 OrderHandleJob 中未支付订单 2 小时取消规则一致。
        $remainingSeconds = ($order->created_at + self::ORDER_EXPIRE_SECONDS) - time();
        if ($remainingSeconds < 60) {
            abort(500, '订单已过期，请重新创建订单');
        }

        return min(120, (int)floor($remainingSeconds / 60)) . 'm';
    }

    private function amountMatches($params)
    {
        $order = Order::where('trade_no', $params['out_trade_no'])->first();
        if (!$order) {
            return false;
        }
        if (!in_array((int)$order->status, [0, 1, 3], true)) {
            return false;
        }

        $amount = $order->total_amount + ($order->handling_amount ?: 0);
        return (int)$amount === (int)round(((float)$params['total_fee']) * 100);
    }

    private function message($result, $default = '接口请求失败')
    {
        $message = $this->value($result, 'msg') ?: $this->value($result, 'message');
        $requestId = $this->value($result, 'request_id');
        if ($message && $requestId) {
            return $message . ' request_id:' . $requestId;
        }
        return $message ?: $default;
    }

    private function value($data, $key)
    {
        if (is_object($data) && isset($data->{$key})) {
            return $data->{$key};
        }
        if (is_array($data) && isset($data[$key])) {
            return $data[$key];
        }
        return null;
    }
}
