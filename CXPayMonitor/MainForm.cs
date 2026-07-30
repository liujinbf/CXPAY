using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace CXPayMonitor
{
    public sealed class MainForm : Form
    {
        private readonly ConfigStore configStore = new ConfigStore();
        private readonly TextBox serverUrl = NewTextBox();
        private readonly NumericUpDown channelId = new NumericUpDown { Minimum = 1, Maximum = 999999999, Dock = DockStyle.Fill };
        private readonly TextBox deviceId = NewTextBox();
        private readonly ComboBox payType = new ComboBox { Dock = DockStyle.Fill, DropDownStyle = ComboBoxStyle.DropDownList };
        private readonly TextBox notifySecret = NewTextBox(true);
        private readonly ComboBox captureMode = new ComboBox { Dock = DockStyle.Fill, DropDownStyle = ComboBoxStyle.DropDownList };
        private readonly ComboBox windowList = new ComboBox { Width = 520, DropDownStyle = ComboBoxStyle.DropDownList };
        private readonly Button refreshWindowsButton = new Button { Text = "刷新窗口", AutoSize = true };
        private readonly Button inspectWindowButton = new Button { Text = "检测可读性", AutoSize = true };
        private readonly TextBox feedUrl = NewTextBox();
        private readonly TextBox feedToken = NewTextBox(true);
        private readonly NumericUpDown pollSeconds = new NumericUpDown { Minimum = 2, Maximum = 300, Value = 5, Dock = DockStyle.Fill };
        private readonly Button startButton = new Button { Text = "启动监控", AutoSize = true };
        private readonly Button saveButton = new Button { Text = "保存配置", AutoSize = true };
        private readonly Label statusLabel = new Label { Text = "未启动", AutoSize = true, ForeColor = Color.DimGray, Padding = new Padding(8, 7, 0, 0) };
        private readonly Label queueLabel = new Label { Text = "待上报：0", AutoSize = true, Padding = new Padding(8, 7, 0, 0) };
        private readonly TextBox logBox = new TextBox
        {
            Dock = DockStyle.Fill, Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical,
            BackColor = Color.FromArgb(248, 249, 250), Font = new Font("Consolas", 9F)
        };
        private MonitorEngine engine;

        public MainForm()
        {
            Text = "CXPAY PC 收款监控端 2.3";
            StartPosition = FormStartPosition.CenterScreen;
            MinimumSize = new Size(760, 640);
            Size = new Size(900, 720);
            Font = new Font("Microsoft YaHei UI", 9F);
            payType.Items.AddRange(new object[] { "wxpay", "alipay", "qqpay" });
            captureMode.Items.AddRange(new object[] { "微信收款单/小账本窗口(OCR视觉)", "微信 Hook 极速模式(后台/免保持窗口)", "授权账单源" });
            BuildLayout();
            LoadConfig();
            startButton.Click += StartButtonClick;
            saveButton.Click += delegate { SaveConfigFromUi(); AppendLog("配置已保存，敏感字段已使用Windows用户密钥加密"); };
            captureMode.SelectedIndexChanged += delegate { UpdateModeUi(); };
            refreshWindowsButton.Click += delegate { RefreshWechatWindows(null, null); };
            inspectWindowButton.Click += async delegate { await InspectSelectedWindowAsync(); };
            FormClosing += delegate { StopEngine(); };
        }

        private void BuildLayout()
        {
            var root = new TableLayoutPanel { Dock = DockStyle.Fill, Padding = new Padding(18), RowCount = 4, ColumnCount = 1 };
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

            var title = new Label
            {
                Text = "CXPAY PC 收款监控端", AutoSize = true,
                Font = new Font("Microsoft YaHei UI", 17F, FontStyle.Bold), ForeColor = Color.FromArgb(26, 74, 138),
                Margin = new Padding(0, 0, 0, 12)
            };
            root.Controls.Add(title, 0, 0);

            var form = new TableLayoutPanel { Dock = DockStyle.Top, AutoSize = true, ColumnCount = 2, RowCount = 10 };
            form.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 150));
            form.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
            AddRow(form, 0, "CXPAY 服务地址", serverUrl);
            AddRow(form, 1, "支付通道 ID", channelId);
            AddRow(form, 2, "设备 ID", deviceId);
            AddRow(form, 3, "支付类型", payType);
            AddRow(form, 4, "账单上报密钥", notifySecret);
            AddRow(form, 5, "采集模式", captureMode);
            var windowArea = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, WrapContents = false };
            windowArea.Controls.Add(windowList);
            windowArea.Controls.Add(refreshWindowsButton);
            windowArea.Controls.Add(inspectWindowButton);
            AddRow(form, 6, "微信记录窗口", windowArea);
            AddRow(form, 7, "授权账单源 URL", feedUrl);
            AddRow(form, 8, "账单源令牌", feedToken);
            AddRow(form, 9, "轮询间隔（秒）", pollSeconds);
            root.Controls.Add(form, 0, 1);

            var actionArea = new FlowLayoutPanel { Dock = DockStyle.Top, AutoSize = true, Padding = new Padding(0, 12, 0, 10) };
            actionArea.Controls.Add(startButton);
            actionArea.Controls.Add(saveButton);
            actionArea.Controls.Add(NewMiniProgramLinkButton());
            actionArea.Controls.Add(statusLabel);
            actionArea.Controls.Add(queueLabel);
            root.Controls.Add(actionArea, 0, 2);

            var logs = new GroupBox { Text = "运行日志", Dock = DockStyle.Fill, Padding = new Padding(8) };
            logs.Controls.Add(logBox);
            root.Controls.Add(logs, 0, 3);
            Controls.Add(root);
        }

        private static TextBox NewTextBox(bool password)
        {
            return new TextBox { Dock = DockStyle.Fill, UseSystemPasswordChar = password };
        }

        private static TextBox NewTextBox()
        {
            return NewTextBox(false);
        }

        private static void AddRow(TableLayoutPanel panel, int row, string label, Control input)
        {
            panel.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            var text = new Label { Text = label, AutoSize = true, Anchor = AnchorStyles.Left, Padding = new Padding(0, 7, 0, 0) };
            input.Margin = new Padding(3, 4, 3, 4);
            panel.Controls.Add(text, 0, row);
            panel.Controls.Add(input, 1, row);
        }

        private Button NewMiniProgramLinkButton()
        {
            var button = new Button { Text = "复制小账本链接", AutoSize = true };
            button.Click += delegate
            {
                try
                {
                    Clipboard.SetText("#小程序://收款小账本/xXTEbezAZGgBEee");
                    AppendLog("小程序链接已复制，请粘贴到微信聊天并点击进入‘收款记录’");
                }
                catch (Exception ex)
                {
                    MessageBox.Show(this, "无法复制小程序链接：" + ex.Message, "复制失败", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                }
            };
            return button;
        }

        private void LoadConfig()
        {
            AppConfig config = configStore.Load();
            serverUrl.Text = config.ServerUrl ?? string.Empty;
            channelId.Value = Math.Max(channelId.Minimum, Math.Min(channelId.Maximum, config.ChannelId <= 0 ? 1 : config.ChannelId));
            deviceId.Text = config.DeviceId ?? string.Empty;
            payType.SelectedItem = string.IsNullOrEmpty(config.PayType) ? "wxpay" : config.PayType;
            notifySecret.Text = config.NotifySecret ?? string.Empty;
            if (config.CaptureMode == AppConfig.AuthorizedFeedMode) captureMode.SelectedIndex = 2;
            else if (config.CaptureMode == AppConfig.WechatHookMode) captureMode.SelectedIndex = 1;
            else captureMode.SelectedIndex = 0;
            feedUrl.Text = config.FeedUrl ?? string.Empty;
            feedToken.Text = config.FeedToken ?? string.Empty;
            pollSeconds.Value = Math.Max(pollSeconds.Minimum, Math.Min(pollSeconds.Maximum, config.PollSeconds <= 0 ? 5 : config.PollSeconds));
            RefreshWechatWindows(config.WindowTitle, config.WindowProcessName);
            UpdateModeUi();
            AppendLog("配置目录：" + configStore.DataDirectory);
        }

        private AppConfig ReadConfig()
        {
            string cleanDeviceId = deviceId.Text.Trim();
            if (!Regex.IsMatch(cleanDeviceId, "^[A-Za-z0-9_.:-]{1,64}$"))
                throw new ArgumentException("设备ID只允许字母、数字及 _ . : -，长度1至64位");
            AppConfig oldConfig = configStore.Load();
            string newFeedUrl = feedUrl.Text.Trim();
            string newPayType = Convert.ToString(payType.SelectedItem);
            WindowDescriptor selectedWindow = windowList.SelectedItem as WindowDescriptor;
            string mode = AppConfig.WechatUiMode;
            if (captureMode.SelectedIndex == 1) mode = AppConfig.WechatHookMode;
            else if (captureMode.SelectedIndex == 2) mode = AppConfig.AuthorizedFeedMode;

            return new AppConfig
            {
                ServerUrl = serverUrl.Text.Trim(),
                ChannelId = (long)channelId.Value,
                DeviceId = cleanDeviceId,
                PayType = newPayType,
                NotifySecret = notifySecret.Text,
                CaptureMode = mode,
                WindowTitle = selectedWindow == null ? string.Empty : selectedWindow.Title,
                WindowProcessName = selectedWindow == null ? string.Empty : selectedWindow.ProcessName,
                WindowHandle = selectedWindow == null ? 0 : selectedWindow.Handle,
                FeedUrl = newFeedUrl,
                FeedToken = feedToken.Text,
                FeedCursor = oldConfig.FeedUrl == newFeedUrl && oldConfig.PayType == newPayType
                    ? oldConfig.FeedCursor : string.Empty,
                PollSeconds = (int)pollSeconds.Value
            };
        }

        private void RefreshWechatWindows(string preferredTitle, string preferredProcess)
        {
            WindowDescriptor oldSelection = windowList.SelectedItem as WindowDescriptor;
            if (string.IsNullOrEmpty(preferredTitle) && oldSelection != null)
            {
                preferredTitle = oldSelection.Title;
                preferredProcess = oldSelection.ProcessName;
            }
            windowList.Items.Clear();
            try
            {
                var collector = new WechatUiCollector();
                foreach (WindowDescriptor window in collector.ListWindows()) windowList.Items.Add(window);
                WindowDescriptor preferred = windowList.Items.Cast<WindowDescriptor>().FirstOrDefault(x =>
                    string.Equals(x.Title, preferredTitle, StringComparison.Ordinal)
                    && string.Equals(x.ProcessName, preferredProcess, StringComparison.OrdinalIgnoreCase));
                if (preferred != null) windowList.SelectedItem = preferred;
                else if (windowList.Items.Count > 0) windowList.SelectedIndex = 0;
                AppendLog("发现可选择的微信窗口：" + windowList.Items.Count + "个");
            }
            catch (Exception ex)
            {
                AppendLog("刷新微信窗口失败：" + ex.Message);
            }
        }

        private async Task InspectSelectedWindowAsync()
        {
            WindowDescriptor selected = windowList.SelectedItem as WindowDescriptor;
            if (selected == null)
            {
                MessageBox.Show(this, "请先在微信中打开收款单或小账本记录页，然后刷新并选择窗口。", "未选择窗口",
                    MessageBoxButtons.OK, MessageBoxIcon.Information);
                return;
            }
            inspectWindowButton.Enabled = false;
            try
            {
                WechatSnapshot snapshot = await Task.Run(delegate
                {
                    var collector = new WechatUiCollector();
                    return collector.Capture(selected);
                });
                Activate();
                var parser = new WechatBillParser();
                IList<BillEvent> bills = parser.Parse(snapshot);
                string pageReason;
                bool supportedPage = parser.IsSupportedPage(snapshot, out pageReason);
                string detectedType = "未知微信页面";
                if (snapshot.Items.Any(x => (x.Text ?? "").Contains("小账本") || (x.Text ?? "").Contains("经营报表")))
                    detectedType = "微信收款小账本（高级模式）";
                else if (snapshot.Items.Any(x => (x.Text ?? "").Contains("收款单") || (x.Text ?? "").Contains("收款助手")))
                    detectedType = "官方微信收款单（通用模式）";

                var preview = new StringBuilder();
                preview.AppendLine("智能诊断：已自动识别为【" + detectedType + "】");
                preview.AppendLine("可读文本项：" + snapshot.Items.Count);
                preview.AppendLine("目标记录页：" + (supportedPage ? "是" : "否（" + pageReason + "）"));
                preview.AppendLine("识别到账记录：" + bills.Count);
                preview.AppendLine();
                foreach (AutomationTextItem item in snapshot.Items.Take(60))
                {
                    string value = (item.Text ?? string.Empty).Replace("\r", " ").Replace("\n", " ").Trim();
                    if (value.Length > 180) value = value.Substring(0, 180) + "…";
                    preview.AppendLine("[" + item.ControlType + "] " + value);
                    if (preview.Length > 7000) break;
                }
                if (snapshot.Items.Count == 0)
                    preview.AppendLine("Windows OCR没有识别到文字，请放大微信记录窗口后重试。");
                MessageBox.Show(this, preview.ToString(), "微信窗口可读性检测", MessageBoxButtons.OK,
                    snapshot.Items.Count == 0 ? MessageBoxIcon.Warning : MessageBoxIcon.Information);
            }
            catch (Exception ex)
            {
                MessageBox.Show(this, ex.Message, "检测失败", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
            finally { UpdateModeUi(); }
        }

        private void UpdateModeUi()
        {
            bool uiMode = captureMode.SelectedIndex == 0;
            bool hookMode = captureMode.SelectedIndex == 1;
            bool feedMode = captureMode.SelectedIndex == 2;

            windowList.Enabled = uiMode && engine == null;
            refreshWindowsButton.Enabled = uiMode && engine == null;
            inspectWindowButton.Enabled = uiMode && engine == null;
            feedUrl.Enabled = feedMode && engine == null;
            feedToken.Enabled = feedMode && engine == null;
            if (uiMode || hookMode) payType.SelectedItem = "wxpay";
            payType.Enabled = feedMode && engine == null;
        }

        private AppConfig SaveConfigFromUi()
        {
            AppConfig config = ReadConfig();
            configStore.Save(config);
            return config;
        }

        private void StartButtonClick(object sender, EventArgs e)
        {
            if (engine != null)
            {
                StopEngine();
                return;
            }
            try
            {
                AppConfig config = SaveConfigFromUi();
                engine = new MonitorEngine(config, configStore);
                engine.Log += AppendLog;
                engine.QueueChanged += UpdateQueue;
                engine.Start();
                ToggleRunning(true);
            }
            catch (Exception ex)
            {
                if (engine != null) { engine.Dispose(); engine = null; }
                MessageBox.Show(this, ex.Message, "配置错误", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
        }

        private void StopEngine()
        {
            MonitorEngine current = engine;
            engine = null;
            if (current != null) current.Dispose();
            ToggleRunning(false);
            AppendLog("监控已停止");
        }

        private void ToggleRunning(bool running)
        {
            startButton.Text = running ? "停止监控" : "启动监控";
            statusLabel.Text = running ? "运行中" : "未启动";
            statusLabel.ForeColor = running ? Color.ForestGreen : Color.DimGray;
            saveButton.Enabled = !running;
            captureMode.Enabled = !running;
            UpdateModeUi();
        }

        private void UpdateQueue(int count)
        {
            SafeUi(delegate { queueLabel.Text = "待上报：" + count; });
        }

        private void AppendLog(string message)
        {
            SafeUi(delegate
            {
                if (logBox.TextLength > 50000) logBox.Clear();
                logBox.AppendText(message + Environment.NewLine);
            });
        }

        private void SafeUi(Action action)
        {
            if (IsDisposed || Disposing) return;
            if (InvokeRequired)
            {
                try { BeginInvoke(action); } catch (InvalidOperationException) { }
            }
            else action();
        }
    }
}
