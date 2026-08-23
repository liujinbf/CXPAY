# CXPAY 项目开发与架构全局规则 (AGENTS.md)

## 核心架构原则：本地与云端协同 (Local + Cloud Architecture)

1. **体系架构**：
   - 本系统为「本地授权实例 + 官方云端授权中心」双层架构；
   - 核心高级插件、驱动包、客户端软件（Android APK / PC 监控软件）由官方云端授权中心统一管控与分发。

2. **软件加密与发布铁律**：
   - 所有对外发布的软件（Android 监控助手 APK、PC 监控端等）在发布至授权站前，**必须先完成代码混淆、签名与加密加固**；
   - 软件安装包统一存放在官方云端授权分发源，供所有授权站点统一安全拉取；
   - 严禁在代码中写死临时测试域名，必须支持动态域名（`window.location.origin`）或官方云端分发源配置。

3. **插件开发规范**：
   - 插件统一在官方云端注册并对接 `PluginLicenseService`；
   - 授权站点通过 `CloudInstanceClient` 验签后由 `PluginPackageInstaller` 安装与加载。
