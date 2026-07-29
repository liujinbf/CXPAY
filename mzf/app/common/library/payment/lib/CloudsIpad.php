<?php

namespace app\common\library\payment\lib;

use app\core\CloudClient;

/**
 * 官方iPad免挂 云端客户端（clouds_ipad）
 *
 * 已改造为走 cloud 新协议：所有方法经 app\core\CloudClient::request('ipad.*') 请求授权站，
 * 由 cloud 端 CloudIpadService 转发上游 iPad 协议服务器。云端地址/授权码/域名统一由
 * ba_cloud_setting 提供（不再每通道单配 peak.h364.cn）。
 *
 * 返回结构与老 clouds_ipad 完全一致：供 wxpay_input/recpt/lkljyf_cloud_ipad 三个云免挂通道共用，
 * 也可被 api\Channel 的 getqrlogin/verifyqrlogin 复用。
 */
class CloudsIpad
{
    /**
     * 构造保留旧签名以兼容调用方（参数已弃用：云端地址/授权码/版本由 CloudClient 统一提供）。
     */
    public function __construct($CloudsUrl = null, $authcode = null, $vers = null, $host = null)
    {
        // no-op：单一 cloud 授权站，参数不再使用
    }

    /** 统一请求：成功返回业务 data(含 code/msg)，云端不可达返回老式异常结构 */
    protected function req(string $act, array $data = []): array
    {
        $res = CloudClient::request('ipad.' . $act, $data);
        if (!$res['ok']) {
            return ['code' => -1, 'msg' => $res['msg'] ?: '当前对接云端服务器异常,请选择其他服务器'];
        }
        return $res['data'];
    }

    /** 获取官方ipad云端服务器以及登录代理 */
    public function get_ipad_server()
    {
        return $this->req('get_ipad_server');
    }

    /** 唤醒登录 */
    public function wakeuplogin($url_id = '', $proxy_id = '', $qrlogin_id = '', $custom_proxy = '')
    {
        return $this->req('wakeuplogin', [
            'url_id'       => $url_id,
            'proxy_id'     => $proxy_id,
            'qrlogin_id'   => $qrlogin_id,
            'custom_proxy' => $custom_proxy,
        ]);
    }

    /** 获取登录二维码 授权Key */
    public function getqrlogin($url_id = '', $proxy_id = '', $qrlogin_id = '', $Way = '', $custom_proxy = '')
    {
        return $this->req('getqrlogin', [
            'url_id'       => $url_id,
            'proxy_id'     => $proxy_id,
            'qrlogin_id'   => $qrlogin_id,
            'Way'          => $Way,
            'custom_proxy' => $custom_proxy,
        ]);
    }

    /** 检测登录二维码登录状态 */
    public function verifyqrlogin($id = '', $url_id = '', $proxy_id = '', $c_type = '', $custom_proxy = '')
    {
        return $this->req('verifyqrlogin', [
            'id'           => $id,
            'url_id'       => $url_id,
            'proxy_id'     => $proxy_id,
            'c_type'       => $c_type,
            'custom_proxy' => $custom_proxy,
        ]);
    }

    /** 获取sid */
    public function getsid($id = '', $url_id = '', $c_type = '')
    {
        return $this->req('getsid', [
            'id'     => $id,
            'url_id' => $url_id,
            'c_type' => $c_type,
        ]);
    }

    /** 获取登录缓存 也是检测ipad是否在线 */
    public function getcacheinfo($id = '', $url_id = '')
    {
        return $this->req('getcacheinfo', [
            'id'     => $id,
            'url_id' => $url_id,
        ]);
    }

    /** 心跳 为了长时间在线 */
    public function heartbeat($id = '', $url_id = '')
    {
        return $this->req('heartbeat', [
            'id'     => $id,
            'url_id' => $url_id,
        ]);
    }

    /** 设置代理 */
    public function setproxy($id = '', $url_id = '', $proxy_id = '', $custom_proxy = '')
    {
        return $this->req('setproxy', [
            'id'           => $id,
            'url_id'       => $url_id,
            'proxy_id'     => $proxy_id,
            'custom_proxy' => $custom_proxy,
        ]);
    }

    /** 取消代理 */
    public function cancelproxy($id = '', $url_id = '')
    {
        return $this->req('cancelproxy', [
            'id'     => $id,
            'url_id' => $url_id,
        ]);
    }

    /** 获取免输个码 */
    public function getpayqrcode($id = '', $url_id = '', $money = 0.01, $remark = 'zero')
    {
        return $this->req('getpayqrcode', [
            'id'     => $id,
            'url_id' => $url_id,
            'money'  => $money,
            'remark' => $remark,
        ]);
    }

    /**
     * 生成随机店铺备注（供云免挂通道出码备注共用）— 保持原样。
     */
    public static function generateShopName($ShopName = '')
    {
        $adjectives = array("小风筝", "幸运", "小幸运", "风筝", "诺诺", "peak", "ZERO", "哨子", "小哨子", "早早", "荷花", "长情", "百顺", "全佳", "卡秋", "百丈", "健元", "信电", "英奇", "轩轩", "阳泰", "守感", "博世", "初仟", "西咏", "盈素", "彦蒂", "文艾", "亦梦", "诗梦", "蕊莺", "冰秀", "锦睿", "澜柚", "华斯", "梵丽", "忆优", "御雨", "飞西", "娜蒂", "千菲", "宜涵", "瑞宇", "羽蝶", "恒诗", "禾卓", "精选", "福瑞", "广西", "南宁", "北京", "重庆", "武鸣", "罗圩", "融安", "融水", "柳州", "河南", "郑州", "海外", "天上", "束河", "精品精选", "摆烂", "peak", "巴黎人", "巴黎", "埃尔", "苹果", "小富婆", "华为", "中国", "云南", "咖啡", "牛奶", "纽约", "浪漫", "可爱", "家乡", "回忆");
        $nouns = array("百货商店", "网络科技", "画廊", "设计工作室", "艺术品牌", "精品优选", "精选店", "工作室", "衣服设计", "五金店", "粉店", "烧烤", "广告设计", "音响改装", "小花店", "小餐馆", "小酒馆", "时尚女鞋", "布衣小店", "网店", "美甲", "美发", "百货", "菜鸟驿站", "顺丰快递", "韵达快递", "中通快递", "菜鸟快递", "申通快递", "极兔快递");

        $randomAdjectiveIndex = array_rand($adjectives);
        $randomNounIndex      = array_rand($nouns);

        return $adjectives[$randomAdjectiveIndex] . $ShopName . $nouns[$randomNounIndex];
    }
}
