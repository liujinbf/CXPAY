# Windows账单适配器状态

当前工程只提供与服务端一致的 v2 HMAC-SHA256 上报客户端，不包含伪造账单、随机金额或宣称已经监听成功的演示逻辑。

在接入经过账号持有人授权、能够提供稳定账单唯一ID和真实发生时间的数据源前，Windows通道不得作为生产通道启用。接入的数据源应调用 `AssistantProtocolClient.PushBillAsync`，并使用 `PushHeartbeatAsync` 维持通道在线状态。
