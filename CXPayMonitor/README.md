# CXPAY PC 微信收款监控端

这是参考旧 `pc` 目录业务流程后重新实现的干净版本。程序不会加载旧版 `Wechat_Pc.exe`、`Sunny.dll` 或 `zip.dll`，不会修改系统 `hosts`、注入微信、抓取 Cookie、设置系统代理或调用微信小程序私有接口。

## 微信界面采集模式

1. 用户在官方电脑版微信中正常登录，并手动打开“收款单”“小账本”“经营账户”或“收款助手”的记录页。
2. 客户端选择“微信收款单/小账本窗口”，点击“刷新窗口”并选中对应窗口。
3. 点击“检测可读性”。程序通过 Windows `PrintWindow` 按窗口句柄获取画面，并用本地中文 OCR 检查页面，不会抢占前台焦点。
4. 启动监控后，“收款小账本”的“收款记录”窗口需要保持打开且不能最小化，但可以放在其他软件后面；电脑仍可正常用于其他工作。截图仅在内存中处理。
5. OCR 同时使用原始分辨率和三倍放大结果，按文字坐标重建日期分组、单笔金额和时间；“共收款 N 笔，累计￥…”会被排除。
6. 首次快照只建立历史基线，不回调已有记录；后续新记录进入本地可靠队列，再按 CXPAY v2 HMAC 协议上报。

此模式不需要手机挂机，也不会在第三方界面登录微信。它依赖页面视觉布局和 Windows OCR，因此不能承诺任意微信版本通用。检测结果必须同时通过窗口标题、页面特征和账单候选校验。Windows 10/11 需要安装“中文（简体）”OCR语言组件；窗口允许被遮挡，但关闭、最小化、锁屏或远程桌面断开仍可能暂停采集，不会冒险回调。

本次实机验证环境为微信 `4.1.10.27`、`WeChatAppEx 2.4.4.20079`、Windows 10 `19045`。其他版本应先点击“检测可读性”，不能仅以程序能够启动作为兼容性结论。

后台遮挡验证已通过：当“收款小账本”位于其他应用后方时，程序仍能识别页面特征并重建账单候选。若未来微信版本不再响应 `PrintWindow`，客户端只会在目标窗口处于前台时使用屏幕截图降级，不会截取其他软件画面并生成回调。

同一分钟内若出现两笔金额完全相同、页面又没有显示交易单号的记录，客户端会优先合并而不是重复回调。建议让记录页显示交易单号或进入账单详情页，以获得稳定唯一标识。

当前解析规则已按照 PC 微信“收款小账本”的真实页面结构适配：日期使用分组标题，单笔金额和时间按屏幕坐标配对；OCR 对冒号等小号字符的有限纠错还会经过合法时分秒范围校验。

“复制小账本链接”按钮复制 `#小程序://收款小账本/xXTEbezAZGgBEee`，请将它粘贴到微信聊天后点击，再手动进入“收款记录”。直接调用 `weixin://` 在部分版本会落到不可访问页面，因此不作为可靠入口。

## 授权账单源模式

原有 HTTPS 账单源仍可作为兼容模式使用：

```http
GET https://your-domain.example/api/bill-source/poll?cursor=...&pay_type=wxpay&channel_id=12&device_id=PC_DEVICE_01
Authorization: Bearer YOUR_TOKEN
Accept: application/json
```

```json
{
  "code": 1,
  "message": "ok",
  "cursor": "next-cursor",
  "data": [
    {
      "source_bill_id": "WX202607281234567890",
      "money": "12.34",
      "occurred_at": 1785211200,
      "remark": "真实到账备注",
      "pay_type": "wxpay"
    }
  ]
}
```

`source_bill_id` 必须是稳定唯一标识，重试时不得变化。账单源必须由商户自行控制或获得合法授权，不能使用随机数据模拟到账。

## 安全与可靠性

- CXPAY 服务地址和授权账单源地址强制使用 HTTPS。
- 上报密钥和账单源令牌使用 Windows DPAPI 按当前用户加密。
- 待上报账单持久化到 `%LOCALAPPDATA%\CXPAY\PcMonitor`，程序重启后继续重试。
- 服务端通过时间戳、随机数、设备绑定、支付类型绑定和 HMAC-SHA256 防伪造与防重放。
- UI 模式只允许枚举 `Weixin`、`WeChat`、`WeChatAppEx` 等微信客户端进程的可见窗口。

## 构建与测试

使用 Visual Studio 2019/2022 打开 `CXPayMonitor.csproj`，以 Release 模式构建，目标框架为 .NET Framework 4.8。项目已包含 Windows SDK Contracts 和 Windows Runtime 的本地引用，用于调用系统 OCR，不需要联网识别。

解析器冒烟测试位于 `tests/pc/WechatBillParserSmoke.cs`；OCR 图片探测位于 `tests/pc/WechatOcrCollectorSmoke.cs`；窗口兼容性探测位于 `tests/pc/WechatUiCollectorSmoke.cs`。探测程序只输出窗口标题和统计数量，不输出账单正文。
