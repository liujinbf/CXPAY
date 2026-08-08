# 云端身份完成契约 v1

`identity-completion-v1` 是 M1A 身份核心向 M1B HTTP、Session 和授权层交付的唯一身份完成结果。该对象表示身份流程已经完成，不等同于已经签发登录 Session。

## 序列化格式

```json
{
  "version": "identity-completion-v1",
  "user_id": "018f0c80-4f6d-7f02-8b1a-9a65cfa19431",
  "audience": "PORTAL",
  "tenant_id": "018f0c81-1b75-7a5e-972f-f81d11a09c22",
  "totp_required": false,
  "completed_at": "2026-08-09T08:30:00.123456Z"
}
```

| 字段 | 类型 | 约束 |
| --- | --- | --- |
| `version` | string | 固定为 `identity-completion-v1` |
| `user_id` | UUID 字符串 | 已完成身份流程的云端用户 |
| `audience` | string | 仅允许 `PORTAL` 或 `OPS` |
| `tenant_id` | UUID 字符串或 null | 当前租户；多租户登录可延后选择 |
| `totp_required` | boolean | 是否必须在签发 Session 前完成 TOTP |
| `completed_at` | string | UTC、RFC3339、微秒精度，固定以 `Z` 结尾 |

序列化对象只能包含上述六个字段。字段新增、删除或语义变更必须发布新版本，不得静默改变 v1。

## 业务不变量

1. Portal 新用户完成邮箱验证和 QQ/微信绑定后，必须同时创建一个 `CUSTOMER` 租户和 `OWNER` 成员关系，因此注册激活结果的 `tenant_id` 不得为 null。
2. 已绑定身份的 Portal 登录如果关联多个租户，可以返回 `tenant_id=null`，由 M1B 在 Session 签发前完成租户选择。
3. Ops 结果必须为 `totp_required=true`。构造 `audience=OPS` 且 `totp_required=false` 的对象属于契约错误。
4. Ops 身份完成结果不能直接兑换为已认证 Session；M1B 必须先验证 TOTP、成员状态和后台权限。
5. 未知 QQ/微信身份不得借登录流程自动注册；必须先完成邮箱注册挑战，再显式绑定官方身份。
6. `completed_at` 记录身份核心完成处理的时刻，不能作为 Session 创建或授权通过时间。

## 数据最小化

该对象不得包含或派生输出以下数据：

- 邮箱验证码及其摘要
- 密码或密码摘要
- OAuth Access Token、Refresh Token、Authorization Code 或原始 State
- QQ OpenID、微信 OpenID/UnionID 等第三方 Subject
- TOTP Base32 密钥、加密密文、nonce 或动态码
- Session、Cookie、下载令牌或插件交付凭据

日志和审计事件引用此对象时也必须遵守相同限制。

## M1B 消费规则

M1B 接收对象后仍需重新读取数据库状态，确认用户、租户、成员和 TOTP 策略未在流程间隙失效。Session 层只能根据 `version` 显式分派处理器；无法识别的版本必须拒绝，不得按 v1 猜测解析。

Portal 注册可以在校验 `tenant_id` 后进入 Session 流程；Portal 多租户登录须先选择租户；Ops 必须完成 TOTP 和后台成员授权。任何分支都不得把 `IdentityCompletion` 本身当作 Bearer 凭据或跨网络长期保存。
