using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;

namespace CXPayMonitor
{
    public partial class MainWindow : Window
    {
        private readonly ConfigStore configStore = new ConfigStore();
        private MonitorEngine engine;
        private bool isRunning = false;

        public MainWindow()
        {
            InitializeComponent();
            LoadConfig();
            RefreshWechatWindows(null, null);
            Closing += (s, e) => StopEngine();
        }

        private void LoadConfig()
        {
            try
            {
                AppConfig config = configStore.Load();
                TxtServerUrl.Text = string.IsNullOrEmpty(config.ServerUrl) ? "https://cs.fcwan.cn" : config.ServerUrl;
                TxtChannelId.Text = (config.ChannelId <= 0 ? 1 : config.ChannelId).ToString(CultureInfo.InvariantCulture);
                TxtDeviceId.Text = config.DeviceId ?? string.Empty;
                TxtNotifySecret.Password = config.NotifySecret ?? string.Empty;

                if (config.PayType == "alipay") CmbPayType.SelectedIndex = 1;
                else if (config.PayType == "qqpay") CmbPayType.SelectedIndex = 2;
                else CmbPayType.SelectedIndex = 0;

                TxtPollSeconds.Text = (config.PollSeconds <= 0 ? 5 : config.PollSeconds).ToString(CultureInfo.InvariantCulture);

                if (config.CaptureMode == AppConfig.AuthorizedFeedMode) CmbCaptureMode.SelectedIndex = 2;
                else if (config.CaptureMode == AppConfig.WechatHookMode) CmbCaptureMode.SelectedIndex = 1;
                else CmbCaptureMode.SelectedIndex = 0;

                TxtFeedUrl.Text = config.FeedUrl ?? string.Empty;
                TxtFeedToken.Password = config.FeedToken ?? string.Empty;

                AppendLog("配置文件已安全加载: " + configStore.DataDirectory);
            }
            catch (Exception ex)
            {
                AppendLog("加载配置发生异常: " + ex.Message);
            }
        }

        private AppConfig ReadConfigFromUi()
        {
            string url = TxtServerUrl.Text.Trim();
            if (string.IsNullOrEmpty(url)) throw new ArgumentException("CXPAY 服务地址不能为空");

            long chId;
            if (!long.TryParse(TxtChannelId.Text.Trim(), out chId) || chId <= 0)
                throw new ArgumentException("支付通道 ID 必须为大于 0 的数字");

            string devId = TxtDeviceId.Text.Trim();
            if (!Regex.IsMatch(devId, "^[A-Za-z0-9_.:-]{1,64}$"))
                throw new ArgumentException("设备 ID 只允许字母、数字及 _ . : -，长度为 1 至 64 位");

            string secret = TxtNotifySecret.Password.Trim();
            if (secret.Length < 32 || secret.Length > 128)
                throw new ArgumentException("账单上报密钥长度必须在 32 至 128 位之间");

            int pollSec;
            if (!int.TryParse(TxtPollSeconds.Text.Trim(), out pollSec) || pollSec < 2 || pollSec > 300)
                pollSec = 5;

            string payType = "wxpay";
            ComboBoxItem selItem = CmbPayType.SelectedItem as ComboBoxItem;
            if (selItem != null && selItem.Tag != null)
            {
                payType = selItem.Tag.ToString();
            }

            string mode = AppConfig.WechatUiMode;
            if (CmbCaptureMode.SelectedIndex == 1) mode = AppConfig.WechatHookMode;
            else if (CmbCaptureMode.SelectedIndex == 2) mode = AppConfig.AuthorizedFeedMode;

            WindowDescriptor win = CmbWindowList.SelectedItem as WindowDescriptor;

            return new AppConfig
            {
                ServerUrl = url,
                ChannelId = chId,
                DeviceId = devId,
                PayType = payType,
                NotifySecret = secret,
                CaptureMode = mode,
                PollSeconds = pollSec,
                WindowTitle = win == null ? string.Empty : win.Title,
                WindowProcessName = win == null ? string.Empty : win.ProcessName,
                WindowHandle = win == null ? 0 : win.Handle,
                FeedUrl = TxtFeedUrl.Text.Trim(),
                FeedToken = TxtFeedToken.Password.Trim()
            };
        }

        private void RefreshWechatWindows(string preferredTitle, string preferredProcess)
        {
            CmbWindowList.Items.Clear();
            try
            {
                var collector = new WechatUiCollector();
                var list = collector.ListWindows().ToList();
                foreach (var w in list)
                {
                    CmbWindowList.Items.Add(w);
                }

                var preferred = list.FirstOrDefault(x =>
                    string.Equals(x.Title, preferredTitle, StringComparison.Ordinal)
                    && string.Equals(x.ProcessName, preferredProcess, StringComparison.OrdinalIgnoreCase));

                if (preferred != null) CmbWindowList.SelectedItem = preferred;
                else if (CmbWindowList.Items.Count > 0) CmbWindowList.SelectedIndex = 0;

                AppendLog("扫描到 " + list.Count + " 个微信相关窗口");
            }
            catch (Exception ex)
            {
                AppendLog("扫描微信窗口失败: " + ex.Message);
            }
        }

        private async void BtnInspectWindow_Click(object sender, RoutedEventArgs e)
        {
            var selected = CmbWindowList.SelectedItem as WindowDescriptor;
            if (selected == null)
            {
                MessageBox.Show(this, "请先在微信中打开收款单或小账本记录页，然后点击刷新并选择窗口。", "未选择窗口", MessageBoxButton.OK, MessageBoxImage.Information);
                return;
            }

            AppendLog("正在进行 OCR 智能诊断与可读性检测...");
            try
            {
                WechatSnapshot snapshot = await Task.Run(() =>
                {
                    var collector = new WechatUiCollector();
                    return collector.Capture(selected);
                });

                var parser = new WechatBillParser();
                IList<BillEvent> bills = parser.Parse(snapshot);
                string pageReason;
                bool supportedPage = parser.IsSupportedPage(snapshot, out pageReason);

                string detectedType = "未知微信页面";
                if (snapshot.Items.Any(x => (x.Text ?? "").Contains("小账本") || (x.Text ?? "").Contains("经营报表")))
                    detectedType = "微信收款小账本（高级模式）";
                else if (snapshot.Items.Any(x => (x.Text ?? "").Contains("收款单") || (x.Text ?? "").Contains("收款助手")))
                    detectedType = "官方微信收款单（通用模式）";

                var sb = new StringBuilder();
                sb.AppendLine("【智能诊断报告】");
                sb.AppendLine("识别页面类型: " + detectedType);
                sb.AppendLine("可读文字数量: " + snapshot.Items.Count);
                sb.AppendLine("是否有效记录页: " + (supportedPage ? "✅ 是" : "❌ 否 (" + pageReason + ")"));
                sb.AppendLine("解析到账记录: " + bills.Count + " 笔");
                sb.AppendLine();
                foreach (var item in snapshot.Items.Take(25))
                {
                    string val = (item.Text ?? "").Replace("\r", " ").Replace("\n", " ").Trim();
                    if (val.Length > 100) val = val.Substring(0, 100) + "…";
                    sb.AppendLine("[" + item.ControlType + "] " + val);
                }

                MessageBox.Show(this, sb.ToString(), "微信窗口可读性诊断报告", MessageBoxButton.OK, MessageBoxImage.Information);
                AppendLog("诊断完成: " + detectedType + "，捕获到 " + bills.Count + " 笔到账记录");
            }
            catch (Exception ex)
            {
                MessageBox.Show(this, "检测失败: " + ex.Message, "错误", MessageBoxButton.OK, MessageBoxImage.Warning);
                AppendLog("检测失败: " + ex.Message);
            }
        }

        private void BtnToggleEngine_Click(object sender, RoutedEventArgs e)
        {
            if (isRunning)
            {
                StopEngine();
            }
            else
            {
                StartEngine();
            }
        }

        private void StartEngine()
        {
            try
            {
                AppConfig config = ReadConfigFromUi();
                configStore.Save(config);

                engine = new MonitorEngine(config, configStore);
                engine.Log += msg => AppendLog(msg);
                engine.QueueChanged += count => UpdateQueueCount(count);
                engine.Start();

                isRunning = true;
                UpdateRunningUi(true);
                AppendLog("挂机监控引擎已启动！正在实时监听微信收款...");
            }
            catch (Exception ex)
            {
                if (engine != null) { engine.Dispose(); engine = null; }
                MessageBox.Show(this, ex.Message, "配置错误", MessageBoxButton.OK, MessageBoxImage.Warning);
                AppendLog("启动失败: " + ex.Message);
            }
        }

        private void StopEngine()
        {
            if (engine != null)
            {
                engine.Dispose();
                engine = null;
            }
            isRunning = false;
            UpdateRunningUi(false);
            AppendLog("监控服务已安全停止");
        }

        private void UpdateRunningUi(bool running)
        {
            Dispatcher.Invoke(() =>
            {
                if (running)
                {
                    BtnToggleEngine.Content = "停止挂机监控";
                    BtnToggleEngine.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#DC2626"));

                    StatusPill.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#DCFCE7"));
                    StatusDot.Fill = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#16A34A"));
                    StatusText.Text = "正在监听 (运行中)";
                    StatusText.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#15803D"));
                }
                else
                {
                    BtnToggleEngine.Content = "启动挂机监控";
                    BtnToggleEngine.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#2563EB"));

                    StatusPill.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#F1F5F9"));
                    StatusDot.Fill = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94A3B8"));
                    StatusText.Text = "未启动";
                    StatusText.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#64748B"));
                }

                BtnSaveConfig.IsEnabled = !running;
                TxtServerUrl.IsEnabled = !running;
                TxtChannelId.IsEnabled = !running;
                TxtDeviceId.IsEnabled = !running;
                TxtNotifySecret.IsEnabled = !running;
                CmbPayType.IsEnabled = !running;
                CmbCaptureMode.IsEnabled = !running;
                TxtPollSeconds.IsEnabled = !running;
            });
        }

        private void UpdateQueueCount(int count)
        {
            Dispatcher.Invoke(() =>
            {
                QueueText.Text = "待上报: " + count;
            });
        }

        private void AppendLog(string message)
        {
            Dispatcher.Invoke(() =>
            {
                if (TxtLog.Text.Length > 80000) TxtLog.Clear();
                string timeStr = DateTime.Now.ToString("HH:mm:ss");
                TxtLog.AppendText(string.Format("[{0}] {1}{2}", timeStr, message, Environment.NewLine));
                TxtLog.ScrollToEnd();
            });
        }

        private void BtnSaveConfig_Click(object sender, RoutedEventArgs e)
        {
            try
            {
                AppConfig config = ReadConfigFromUi();
                configStore.Save(config);
                AppendLog("配置已保存成功，上报密钥已使用 Windows DPAPI 本地加密存储");
                MessageBox.Show(this, "配置保存成功！", "提示", MessageBoxButton.OK, MessageBoxImage.Information);
            }
            catch (Exception ex)
            {
                MessageBox.Show(this, ex.Message, "保存错误", MessageBoxButton.OK, MessageBoxImage.Warning);
            }
        }

        private void BtnRefreshWindows_Click(object sender, RoutedEventArgs e)
        {
            RefreshWechatWindows(null, null);
        }

        private void BtnCopyLink_Click(object sender, RoutedEventArgs e)
        {
            string link = "#小程序://收款小账本/xXTEbezAZGgBEee";
            if (ClipboardHelper.CopyText(link))
            {
                AppendLog("小程序链接已成功写入剪贴板！");
                MessageBox.Show(this, "小程序链接已成功复制到剪贴板！\n\n请在电脑微信中粘贴发送到任意聊天（如“文件传输助手”），点击即可进入【收款小账本 - 收款记录】。", "复制成功", MessageBoxButton.OK, MessageBoxImage.Information);
            }
            else
            {
                MessageBox.Show(this, "系统剪贴板当前正被其他应用占用。\n请手动复制以下小账本链接：\n\n" + link, "小程序链接", MessageBoxButton.OK, MessageBoxImage.Information);
            }
        }

        private void BtnClearLog_Click(object sender, RoutedEventArgs e)
        {
            TxtLog.Clear();
            AppendLog("=== 日志已清空 ===");
        }

        private void CmbCaptureMode_SelectionChanged(object sender, SelectionChangedEventArgs e)
        {
            if (PnlUiMode == null || PnlFeedMode == null) return;
            bool isFeed = CmbCaptureMode.SelectedIndex == 2;
            PnlUiMode.Visibility = isFeed ? Visibility.Collapsed : Visibility.Visible;
            PnlFeedMode.Visibility = isFeed ? Visibility.Visible : Visibility.Collapsed;
        }
    }
}
