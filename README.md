# CXPAY 商业级高并发聚合支付平台

> **项目简介**：基于 Webman 2.x 常驻协程架构打造的高并发、低延迟聚合支付/易支付 (EPay) 平台。支持官方 API 直连、易支付网关、微信小账本/收款单协议云端免挂、支付宝扫码 Cookie 免挂以及个人微信/支付宝/QQ 钱包全自动挂机冲销。

---

## 📖 目录

1. [项目简介与核心亮点](#一-项目简介与核心亮点)
2. [极速 3 步安装指南](#二-极速-3-步安装指南)
3. [商业配套客户端软件下载 (Windows & Android)](#三-商业配套客户端软件下载-windows--android)
4. [常见问题与使用帮助 (FAQ)](#四-常见问题与使用帮助-faq)
5. [技术团队二次开发说明](#五-技术团队二次开发说明)

---

## 一、 项目简介与核心亮点

- **高并发协程架构**：基于 Webman 2.x + PHP 8.1+ 内存常驻协程，性能较传统 ThinkPHP/PHP-FPM 提升 5-10 倍。
- **免挂零图片出码**：微信小账本/收款单协议云端免挂、支付宝扫码 Base64 免挂、QQ 钱包 ptlogin 免挂。
- **智能防封与隔离**：内置动态住宅 IP 代理池与设备 User-Agent 指纹离散，单通道日封顶自动熔断休眠。
- **全套 9 大精美 UI**：内置 3 套官网旗舰科技风模版、商户控制中心、超级管理员控制台与极速收银台。

---

## 二、 极速 3 步安装指南

### 1. 环境依赖要求
- 服务器操作系统：Linux (Ubuntu / CentOS / Debian) 或 Windows Server
- 环境要求：PHP 8.1+、`pcntl` 多进程扩展、PDO MySQL 与 Redis 扩展

### 2. 可视化一键安装步骤 (/install)
1. 将项目部署至服务器后，在浏览器访问：`http://您的域名/install`。
2. **第一步 环境检测**：系统自动检测 PHP 版本及所需扩展状态。
3. **第二步 数据库配置**：填入 MySQL 数据库地址、数据库名、端口以及超级管理员账号密码。
4. **第三步 点击安装**：点击“一键安装导入”，系统自动完成数据库建表与安全锁配置 (`install.lock`)。

### 3. 生产环境后台启动指令
```bash
# 生产环境常驻后台启动
php start.php start -d

# 停止进程
php start.php stop

# 平滑重载代码
php start.php reload
```

---

## 三、 商业配套客户端软件下载 (Windows & Android)

- **Windows 桌面挂机监控助手 (v4.0 拟态旗舰版)**：  
  文件路径：[scratch/pc_project/CXPayAssistant_v4.exe](file:///c:/Users/Administrator/Desktop/m.fcwan.cn_cJxd7/CXPAY/scratch/pc_project/CXPayAssistant_v4.exe)  
  *特点：带左侧侧边栏、实时订单与到账流水表格、全局独立 Toggle 监控开关。*

- **Android 手机挂机助手工程**：  
  文件路径：[scratch/android_project/](file:///c:/Users/Administrator/Desktop/m.fcwan.cn_cJxd7/CXPAY/scratch/android_project/)

---

## 四、 常见问题与使用帮助 (FAQ)

1. **商户如何添加个人收款码？**  
   商户登录后进入“商户服务中心”，点击【📷 微信/支付宝/QQ 个人码绑定】，直接上传二维码图片，系统会自动完成 URL 解码并绑定。
2. **如何防止微信/支付宝账号被封？**  
   系统内置了【轮询组 (PollGroup)】功能，建议商户绑定 3 个以上的收款账号开启轮询分流；同时在通道配置中设置“单日最大额度（如 3000 元）”，超出后系统会自动熔断切换到下一个账号。

---

## 五、 技术团队二次开发说明

> 🛠️ **技术团队二开专用文档**：  
> 本项目已将架构设计图、时序图、编码红线、数据库建模与驱动扩展开发规范完全独立收录于 **[DEVELOPMENT.md](file:///c:/Users/Administrator/Desktop/m.fcwan.cn_cJxd7/CXPAY/DEVELOPMENT.md)** 中。  
> 二开开发者请直接查阅 `DEVELOPMENT.md` 获取完整的底层设计说明。
