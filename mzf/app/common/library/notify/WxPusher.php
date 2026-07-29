<?php

namespace app\common\library\notify;

/**
 * WxPusher 微信推送
 * 使用微信公众号作为通道的实时信息推送平台。
 * 全局 appToken 读取 get_sys_config('wxpusher_apptoken')，每用户绑定 uid。
 * 申请地址：https://wxpusher.zjiecode.com
 */
class WxPusher
{
    protected string $appToken;

    protected string $api = 'https://wxpusher.zjiecode.com';

    public function __construct(string $appToken = '')
    {
        $this->appToken = $appToken ?: (string)get_sys_config('wxpusher_apptoken');
    }

    public function configured(): bool
    {
        return $this->appToken !== '';
    }

    /**
     * 获取关注二维码
     * @param string|int $extra 附加参数（用于回填识别）
     * @return array{code:int,data?:array{code:string,url:string}}|null
     */
    public function qrcode($extra = ''): ?array
    {
        return $this->postJson('/api/fun/create/qrcode', [
            'appToken'  => $this->appToken,
            'extra'     => (string)$extra,
            'validTime' => 60 * 60 * 24,
        ]);
    }

    /**
     * 用二维码 code 查询扫码用户 UID（未扫码时 data 为空）
     * @return array{code:int,data?:string}|null
     */
    public function qrcodeUid(string $code): ?array
    {
        return $this->getJson('/api/fun/scan-qrcode-uid?code=' . urlencode($code));
    }

    /**
     * 发送消息（contentType=2 HTML）
     * @param string $uid     目标用户 UID
     * @param string $summary 摘要/标题
     * @param string $content HTML 内容
     * @return array{code:int}|null
     */
    public function send(string $uid, string $summary, string $content): ?array
    {
        if (!$this->appToken || !$uid) return null;
        return $this->postJson('/api/send/message', [
            'appToken'      => $this->appToken,
            'content'       => $content,
            'summary'       => mb_substr($summary, 0, 20),
            'contentType'   => 2,
            'uids'          => [$uid],
            'verifyPay'     => false,
            'verifyPayType' => 0,
        ]);
    }

    private function postJson(string $path, array $body): ?array
    {
        $ch = curl_init($this->api . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);
        $data = curl_exec($ch);
        curl_close($ch);
        if ($data === false) return null;
        return json_decode($data, true) ?: null;
    }

    private function getJson(string $path): ?array
    {
        $ch = curl_init($this->api . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $data = curl_exec($ch);
        curl_close($ch);
        if ($data === false) return null;
        return json_decode($data, true) ?: null;
    }
}
