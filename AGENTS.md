# CXPAY 项目开发与架构全局规则 (AGENTS.md)

## 核心架构原则：本地与云端协同 (Local + Cloud Architecture)

1. **双层体系架构**：
   - 本系统为「本地授权实例 (CXPAY Runtime) + 官方云端授权中心 (CXPAY Cloud)」双层架构；
   - 核心高级插件、支付驱动包、客户端软件（Android APK / PC 监控软件）由官方云端授权中心统一管控、签名、加密与分发。

2. **支付插件纯云端分发与动态加载铁律**：
   - **主站源码零内置驱动**：主站源码（包括对外发布的源码包）**严禁硬编码或物理内置核心支付通道驱动源码**，主站定位为纯净轻量化聚合支付网关框架；
   - **云端统一定义与数字签名**：所有支付驱动统一在云端或开发端（`plugins-src/`）通过 RSA-SHA256 私钥数字签名并打包为标准的 `.cxpay-plugin` 交付包；
   - **公钥验签与沙箱热加载**：授权站点通过 `CloudInstanceClient` 建立安全通信从云端拉取插件包，经 `PluginPackageInstaller` 验证官方公钥签名完整性后，解压至 `runtime/plugins/cxpay/` 动态热加载；
   - **开箱安装自动化**：主站安装脚本 `setup.sh` 在新站初始化完成时，自动调用 `CloudInstanceClient` 从官方云端静默下载并安装官方免费基础插件，确保新站部署后开箱即可出码；
   - **商户端严格继承**：商户端（`Merchant Center`）不直接连接云端，所有可用支付通道驱动 100% 来自当前主站已成功安装并启用的插件，并受商户所购套餐（`cx_plan` 的 `allowed_channels`）白名单与配额控制。

3. **软件加密与发布铁律**：
   - 所有对外发布的软件（Android 监控助手 APK、PC 监控端等）在发布至授权站前，**必须先完成代码混淆、签名与加密加固**；
   - 软件安装包统一存放在官方云端授权分发源，供所有授权站点统一安全拉取；
   - 严禁在代码中写死临时测试域名，必须支持动态域名（`window.location.origin`）或官方云端分发源配置。

4. **插件开发规范**：
   - 插件统一在官方云端注册并对接 `PluginLicenseService`；
   - 授权站点通过 `CloudInstanceClient` 验签后由 `PluginPackageInstaller` 安装与加载。

5. **构建与生产运行环境物理隔离铁律 (Build Isolation vs Production Runtime)**：
   - **严禁就地污染线上生产环境**：所有云端打包、插件签名构建、代码混淆加密、架构验证等操作，**严禁直接修改或临时移走线上已部署生产站点 (`/www/apps/cxpay-runtime/current/`) 中的任何物理目录与文件**；
   - **独立 Staging 构建沙箱**：所有发布包打包与构建任务必须在独立的临时构建工作区（如 `/www/apps/cxpay-cloud/shared/build/` 或 `/tmp/cxpay_build_*`）中封闭执行，构建完成后仅将产物 `.zip` / `.cxpay-plugin` 交付至分发源；
   - **生产数据与运行状态零侵入**：进行代码升级或补丁演进时，必须确保已部署站点的已配置通道数据（`cx_pay_channel`）、商户专属配置（`merchant_id`）、本地授权凭据（`entitlements.json`）及正在运行的 Webman 会话得到严格保护，严禁任何测试指令导致线上业务中断或数据丢失；
   - **预发布语法与 DOM 闭环检验**：任何代码在打入发布包前，必须先在独立构建临时目录中完成全量 `php -l` 语法校验与 HTML DOM 标签栈平衡解析，确保交付包 100% 具备开箱即用稳定性。


