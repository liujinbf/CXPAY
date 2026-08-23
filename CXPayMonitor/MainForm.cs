using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Net;
using System.Windows.Forms;
using Microsoft.Win32;

namespace CXPayMonitor
{
    public sealed class MainForm : Form
    {
        private readonly WebBrowser browser = new WebBrowser();
        private LocalUiServer uiServer;

        public MainForm()
        {
            Text = "CXPAY 微信/支付宝 PC 收款监控端 v2.3 Pro";
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(1080, 800);
            MinimumSize = new Size(960, 720);
            Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath);

            SetWebBrowserEmulation();

            try
            {
                uiServer = new LocalUiServer();
                uiServer.Start();
            }
            catch (Exception ex)
            {
                MessageBox.Show("启动本地微服务失败: " + ex.Message);
            }

            browser.Dock = DockStyle.Fill;
            browser.ScriptErrorsSuppressed = true;
            browser.IsWebBrowserContextMenuEnabled = false;
            browser.AllowWebBrowserDrop = false;

            Controls.Add(browser);

            Load += (s, e) =>
            {
                string url = string.Format("http://127.0.0.1:{0}/index.html", LocalUiServer.Port);
                browser.Navigate(url);
            };

            FormClosing += (s, e) =>
            {
                if (uiServer != null) uiServer.Dispose();
            };
        }

        private static void SetWebBrowserEmulation()
        {
            try
            {
                string appName = Path.GetFileName(Application.ExecutablePath);
                using (RegistryKey key = Registry.CurrentUser.CreateSubKey(@"Software\Microsoft\Internet Explorer\Main\FeatureControl\FEATURE_BROWSER_EMULATION"))
                {
                    if (key != null)
                    {
                        key.SetValue(appName, 11001, RegistryValueKind.DWord);
                    }
                }
            }
            catch { }
        }
    }
}
