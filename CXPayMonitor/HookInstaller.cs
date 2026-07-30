using System;
using System.Diagnostics;
using System.IO;
using Microsoft.Win32;

namespace CXPayMonitor
{
    /// <summary>
    /// 微信 PC 自动化 Hook 管理器
    /// 负责自动检测微信安装位置、复制/卸载 version.dll，以及检测微信 Hook 服务状态。
    /// </summary>
    public static class HookInstaller
    {
        private const string VersionDllName = "version.dll";

        /// <summary>
        /// 从注册表或常见路径自动获取微信 PC 版安装目录
        /// </summary>
        public static string GetWechatInstallDirectory()
        {
            // 1. 尝试从 64位/32位 注册表获取
            string[] registryKeys = new[]
            {
                @"SOFTWARE\Tencent\WeChat",
                @"SOFTWARE\WOW6432Node\Tencent\WeChat",
                @"SOFTWARE\Tencent\Weixin",
                @"SOFTWARE\WOW6432Node\Tencent\Weixin"
            };

            foreach (string subKey in registryKeys)
            {
                using (RegistryKey key = Registry.LocalMachine.OpenSubKey(subKey))
                {
                    if (key != null)
                    {
                        object pathObj = key.GetValue("InstallPath");
                        if (pathObj != null && Directory.Exists(pathObj.ToString()))
                        {
                            return pathObj.ToString();
                        }
                    }
                }

                using (RegistryKey key = Registry.CurrentUser.OpenSubKey(subKey))
                {
                    if (key != null)
                    {
                        object pathObj = key.GetValue("InstallPath");
                        if (pathObj != null && Directory.Exists(pathObj.ToString()))
                        {
                            return pathObj.ToString();
                        }
                    }
                }
            }

            // 2. 检查运行中进程的路径
            Process[] processes = Process.GetProcessesByName("WeChat");
            if (processes.Length == 0) processes = Process.GetProcessesByName("Weixin");
            if (processes.Length > 0)
            {
                try
                {
                    string mainModulePath = processes[0].MainModule.FileName;
                    return Path.GetDirectoryName(mainModulePath);
                }
                catch { }
            }

            // 3. 常见默认路径兜底
            string[] commonPaths = new[]
            {
                @"C:\Program Files\Tencent\Weixin",
                @"C:\Program Files (x86)\Tencent\Weixin",
                @"C:\Program Files\Tencent\WeChat",
                @"C:\Program Files (x86)\Tencent\WeChat",
                @"D:\Program Files\Tencent\Weixin",
                @"D:\Program Files\Tencent\WeChat"
            };

            foreach (string p in commonPaths)
            {
                if (Directory.Exists(p)) return p;
            }

            return null;
        }

        /// <summary>
        /// 检查微信目录中是否已安装 Hook DLL
        /// </summary>
        public static bool IsHookInstalled(string wechatDir)
        {
            if (string.IsNullOrEmpty(wechatDir) || !Directory.Exists(wechatDir)) return false;
            string targetDll = Path.Combine(wechatDir, VersionDllName);
            return File.Exists(targetDll);
        }

        /// <summary>
        /// 卸载 Hook DLL（安全清理）
        /// </summary>
        public static bool UninstallHook(string wechatDir, out string error)
        {
            error = null;
            try
            {
                if (string.IsNullOrEmpty(wechatDir) || !Directory.Exists(wechatDir))
                {
                    error = "微信目录不存在";
                    return false;
                }

                string targetDll = Path.Combine(wechatDir, VersionDllName);
                if (File.Exists(targetDll))
                {
                    File.Delete(targetDll);
                }
                return true;
            }
            catch (Exception ex)
            {
                error = "无法清理 " + VersionDllName + "，可能微信正在运行中：" + ex.Message;
                return false;
            }
        }
    }
}
