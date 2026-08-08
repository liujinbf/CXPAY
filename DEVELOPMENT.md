# CXPAY 架构设计与二次开发指导文档

> **版本**: v2.5 Enterprise Architecture Specification  
> **更新时间**: 2026-08-08  
> **适用对象**: 核心开发团队、二次开发人员、代理端/云端运维人员  

---

## 一、系统整体架构概述

CXPAY 是一套专为多租户聚合支付打造的 **三层 SaaS 商业架构** 系统：

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           三层 SaaS 商业架构                                  │
│                                                                              │
│  ┌─────────────────────────┐                                                  │
│  │ 1. 官方云端 (Central Server)│  官方运营的主站 / 授权中心                     │
│  │                         │  - 域名 License 授权管理                         │
│  │                         │  - 云端免挂/协议模块订阅管理                       │
│  │                         │  - 支付通道插件 (.cxpay-plugin) 上架与发售        │
│  │                         │  - 代码动态水印注入与盗版泄露一键封禁             │
│  └──────────┬──────────────┘                                                 │
│             │ 购买 License + 模块订阅 + 支付通道插件                          │
│             ▼                                                                 │
│  ┌─────────────────────────┐                                                  │
│  │ 2. 代理端 (Agent Master) │  代理商独立部署的 CXPAY 站点                       │
│  │                         │  - 从云端插件商城购买并在线下载/安装支付插件       │
│  │                         │  - 创建与发布不同的商户 VIP 套餐 (Plan)            │
│  │                         │  - 控制商户可用通道白名单 (allowed_channels)        │
│  └──────────┬──────────────┘                                                 │
│             │ 商户注册 + 购买/订阅套餐                                         │
│             ▼                                                                 │
│  ┌─────────────────────────┐                                                  │
│  │ 3. 商户端 (Merchant Side)│  代理端下辖的普通商户                             │
│  │                         │  - 浏览并购买代理端发布的套餐                     │
│  │                         │  - 在套餐许可范围内自主配置对应的收款通道        │
│  │                         │  - 发起收单与账单结算                             │
│  └─────────────────────────┘                                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 二、核心数据库模型设计

### 1. 官方云端表结构

#### `cx_license`（站点域名授权表）
| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT | 主键 ID |
| `domain` | VARCHAR(255) | 代理站点绑定域名（唯一） |
| `auth_key` | VARCHAR(64) | 代理站点授权通讯密钥 |
| `watermark_id` | VARCHAR(64) | 独立生成的源码水印 ID |
| `modules` | JSON | 云端服务模块订阅状态（如 `{"ipad_cloud": 1800000000}`） |
| `status` | TINYINT | 1=正常，0=封禁冻结 |

#### `cx_cloud_plugin`（云端插件商品表）
| 字段 | 类型 | 说明 |
|------|------|------|
| `plugin_id` | VARCHAR(100) | 插件唯一标识（如 `cxpay.wxpay.clerk`） |
| `name` | VARCHAR(100) | 插件名称 |
| `payment_type` | VARCHAR(20) | 支付分类：`wxpay` / `alipay` / `qqpay` |
| `price_month` | DECIMAL(10,2)| 月付价格（0 表示免费） |
| `price_quarter`| DECIMAL(10,2)| 季付价格 |
| `price_year` | DECIMAL(10,2)| 年付价格 |
| `price_forever`| DECIMAL(10,2)| 永久买断价格（-1 表示不支持买断） |
| `status` | TINYINT | 1=上架销售，0=下架 |

#### `cx_agent_plugin_license`（代理端插件购买授权记录表）
| 字段 | 类型 | 说明 |
|------|------|------|
| `domain` | VARCHAR(255) | 代理站点域名 |
| `plugin_id` | VARCHAR(100) | 插件标识 |
| `pkg_type` | VARCHAR(20) | 套期类型：`month` / `quarter` / `year` / `forever` |
| `expire_time` | INT | 授权到期时间戳（`-1` 为永久） |

---

### 2. 代理端与商户端表结构

#### `cx_plan`（商户 VIP 套餐表）
| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT | 套餐 ID |
| `name` | VARCHAR(100) | 套餐名称 |
| `allowed_channels` | TEXT | **允许使用的通道驱动白名单（逗号分隔，如 `wxpay_clerk,alipay_scan`）** |
| `channel_quota` | INT | 最大允许配置通道数量 |
| `price` | DECIMAL(10,2)| 套餐价格 |

#### `cx_merchant`（商户主表）
| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT | 商户 ID |
| `pid` | VARCHAR(32) | 商户 PID |
| `plan_id` | INT | 当前生效的套餐 ID（`0` 表示无套餐） |
| `plan_expire_time` | INT | 套餐到期时间戳（`0` 表示永久/未到期） |

---

## 三、核心逻辑控制门（安全与越权防护）

### 1. 商户套餐通道控制门 (`MerchantChannelController`)

后续针对商户端通道管理进行修改或扩充时，**必须严格遵守以下控制门原则**：

```php
// 1. 套餐到期与状态校验
$planId = (int)($merchant->plan_id ?? 0);
$planExpire = (int)($merchant->plan_expire_time ?? 0);
if ($planId <= 0 || ($planExpire > 0 && $planExpire < time())) {
    return json(['code' => -100, 'msg' => '未开通套餐或套餐已到期']);
}

// 2. 允许通道白名单校验 (allowed_channels)
$currentPlan = Plan::find($planId);
if ($currentPlan) {
    $allowedChannels = array_filter(array_map('trim', explode(',', (string)$currentPlan->allowed_channels)));
    if ($allowedChannels !== [] && !in_array($cType, $allowedChannels, true)) {
        return json(['code' => -101, 'msg' => '当前套餐不支持此通道类型']);
    }
}
```

### 2. 插件安装二次联网授权校验 (`PluginPackageInstaller`)

任何支付插件在本地解包安装时，必须经过如下两重校验：
1. **本地 RSA 签名与哈希校验**：防止安装被篡改的第三种非官方包。
2. **云端授权状态比对**：若配置了 `site_domain` 与 `auth_key`，自动连云端查验该域名是否拥有该 `plugin_id` 的有效授权。

---

## 四、主要 API 接口目录

### 1. 代理端 ↔ 官方云端 交互接口

| 接口 | 方法 | 权限 | 说明 |
|------|------|------|------|
| `/api/cloud/site_info` | GET | 公开 | 获取当前站点域名 License 状态及模块到期情况 |
| `/api/cloud/plugin/market_list` | GET | 域名+Key | 拉取官方云端可购买插件列表及本地授权状态 |
| `/api/cloud/plugin/buy` | POST | 域名+Key | 向云端购买指定插件授权 |
| `/api/cloud/plugin/download` | GET | 域名+Key | 验证授权后下发 `.cxpay-plugin` 签名安装包二进制 |

### 2. 代理端后台 插件管理接口

| 接口 | 方法 | 权限 | 说明 |
|------|------|------|------|
| `/api/admin/plugin/market_list` | GET | Admin | 查看本地已安装的驱动与插件 |
| `/api/admin/plugin/cloud_market` | GET | Admin | 代理端发起：拉取云端在线插件商城列表 |
| `/api/admin/plugin/cloud_buy` | POST | Admin | 代理端发起：向云端购买插件授权 |
| `/api/admin/plugin/cloud_download` | POST | Admin | 代理端发起：从云端下载并一键安装插件 |

### 3. 商户端 套餐与通道管理接口

| 接口 | 方法 | 权限 | 说明 |
|------|------|------|------|
| `/api/merchant/plan/list` | GET | Merchant | 获取代理商上架的套餐广场列表 |
| `/api/merchant/plan/buy` | POST | Merchant | 购买/订阅指定套餐 |
| `/api/merchant/channel/drivers` | GET | Merchant | 获取可选支付驱动（受商户套餐 `allowed_channels` 自动过滤） |
| `/api/merchant/channel/save` | POST | Merchant | 保存/配置通道（含套餐到期与白名单强制校验） |

---

## 五、后续二次开发与迭代规范

1. **不可绕过白名单**：在新增任何通道配置接口或批量导入通道功能时，必须调用 `allowed_channels` 进行合法性过滤。
2. **驱动命名规范**：插件与内置驱动 `cType` 必须遵循 `{pay_category}_{specific_name}` 规则（如 `wxpay_clerk`、`alipay_scan`）。
3. **数据库迁移规范**：涉及结构变更时，必须在 `database/` 下创建新的补丁脚本（如 `patch_v10.sql`），禁止直接改动历史 SQL 文件。
4. **统一错误响应**：
   - `-100`：需购买/订阅套餐（`ErrorCode::PLAN_REQUIRED`）
   - `-101`：通道类型超出套餐白名单范围（`ErrorCode::CHANNEL_NOT_IN_PLAN`）
   - `-1`：常规业务逻辑报错（`ErrorCode::FAIL`）

---

## 六、数据库补丁版本记录

| 版本 | 文件 | 作用 |
|------|------|------|
| v7 | `database/patch_v7.sql` | 管理员二次验证配置、Token 版本号 |
| v8 | `database/patch_v8.sql` | 子管理员 RBAC 表（`cx_admin` / `cx_admin_permission`）|
| v9 | `database/patch_v9.sql` | 云端插件商城体系（`cx_cloud_plugin` / `cx_agent_plugin_license`）|
| v10 | `database/patch_v10.sql` | 主备通道字段（`cx_pay_channel.fallback_channel_id`）+ 差异化费率（`cx_merchant.rate_config`）|

---

## 七、已完成的关键功能清单（v2.5 版本）

| 功能 | 实现位置 |
|------|----------|
| 商户套餐控制门 `-100`/`-101` | `MerchantChannelController::save()` |
| 插件 RSA + 云端二次校验 | `PluginPackageInstaller::install()` |
| 主备通道自动故障转移 | `OrderService::selectChannel()` |
| 差异化费率 rate_config | `OrderService::createOrder()` / `markAsPaid()` |
| 管理员二次验证码 | `AdminController::login()` / `verifyLoginCode()` |
| Admin Token 版本号吊销 | `AdminAuthMiddleware::verifyToken()` |
| RBAC 子管理员 | `AdminAuthMiddleware::checkPermission()` |
| 商户 IP 白名单 | `ApiAuthMiddleware::process()` |
| 下单限频 Redis 窗口 | `OrderService::enforceOrderRateLimit()` |
| 重发通知限频 | `MerchantApiController::resendOrderNotify()` |
| site_info 返回已购插件列表 | `CloudLicenseController::getSiteInfo()` |
| drivers 返回套餐到期信息 | `MerchantChannelController::drivers()` |
| 统一错误码常量 | `support/ErrorCode.php` |
