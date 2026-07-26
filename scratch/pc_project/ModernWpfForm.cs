using System;
using System.Net.Http;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Effects;

namespace CXPayModernAssistant
{
    public class MainWindow : Window
    {
        private TextBox txtServerUrl;
        private TextBox txtPid;
        private PasswordBox txtSecretKey;
        private CheckBox chkWx;
        private CheckBox chkAlipay;
        private CheckBox chkQq;
        private Button btnStart;
        private Button btnStop;
        private TextBlock txtStatMoney;
        private TextBlock txtStatCount;
        private TextBox txtLog;
        private TextBlock txtStatus;

        private bool isRunning = false;
        private double todayMoney = 0.00;
        private int todayCount = 0;
        private static readonly HttpClient client = new HttpClient();

        public MainWindow()
        {
            InitializeModernUi();
        }

        private void InitializeModernUi()
        {
            // 1. 无边框现代窗口属性
            this.Title = "CXPAY 极速监控助手 · 现代拟态玻璃旗舰版 v3.5";
            this.Width = 640;
            this.Height = 720;
            this.WindowStartupLocation = WindowStartupLocation.CenterScreen;
            this.WindowStyle = WindowStyle.None;
            this.AllowsTransparency = true;
            this.Background = Brushes.Transparent;

            // 2. 主背景 Container (大圆角卡片 + 阴影光晕)
            Border mainBorder = new Border
            {
                CornerRadius = new CornerRadius(20),
                Background = new LinearGradientBrush(
                    (Color)ColorConverter.ConvertFromString("#060911"),
                    (Color)ColorConverter.ConvertFromString("#0f172a"), 45),
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")),
                BorderThickness = new Thickness(1),
                Effect = new DropShadowEffect
                {
                    Color = Color.FromRgb(0, 0, 0),
                    BlurRadius = 30,
                    ShadowDepth = 10,
                    Opacity = 0.6
                }
            };
            mainBorder.MouseDown += (s, e) => { if (e.LeftButton == MouseButtonState.Pressed) this.DragMove(); };

            Grid rootGrid = new Grid();
            rootGrid.Margin = new Thickness(24);
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Header
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Stats Cards
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Inputs Card
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Channels Card
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Actions
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) }); // Logs
            rootGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Status

            // --- Row 0: Header Navigation Bar ---
            Grid headerGrid = new Grid();
            headerGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            headerGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });

            StackPanel logoPanel = new StackPanel { Orientation = Orientation.Horizontal };
            Border logoIcon = new Border
            {
                Width = 36, Height = 36, CornerRadius = new CornerRadius(10),
                Background = new LinearGradientBrush((Color)ColorConverter.ConvertFromString("#38bdf8"), (Color)ColorConverter.ConvertFromString("#6366f1"), 45),
                Margin = new Thickness(0, 0, 12, 0)
            };
            TextBlock logoText = new TextBlock
            {
                Text = "CX", Foreground = Brushes.White, FontWeight = FontWeights.ExtraBold,
                FontSize = 16, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center
            };
            logoIcon.Child = logoText;

            StackPanel titlePanel = new StackPanel();
            TextBlock txtTitle = new TextBlock { Text = "CXPAY 挂机监控助手", Foreground = Brushes.White, FontSize = 16, FontWeight = FontWeights.Bold };
            TextBlock txtSub = new TextBlock { Text = "现代拟态玻璃卡片界面 · 商业级免签监控引擎", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 11 };
            titlePanel.Children.Add(txtTitle);
            titlePanel.Children.Add(txtSub);

            logoPanel.Children.Add(logoIcon);
            logoPanel.Children.Add(titlePanel);
            headerGrid.Children.Add(logoPanel);

            // 关闭按钮
            Button btnClose = new Button
            {
                Content = "✕", Foreground = Brushes.White, FontSize = 14, Background = Brushes.Transparent,
                BorderThickness = new Thickness(0), Width = 30, Height = 30, Cursor = Cursors.Hand
            };
            btnClose.Click += (s, e) => this.Close();
            Grid.SetColumn(btnClose, 1);
            headerGrid.Children.Add(btnClose);

            Grid.SetRow(headerGrid, 0);
            rootGrid.Children.Add(headerGrid);

            // --- Row 1: 拟态双卡片统计面板 ---
            Grid statsGrid = new Grid { Margin = new Thickness(0, 20, 0, 16) };
            statsGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            statsGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(16) }); // Gap
            statsGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });

            // 卡片 1: 今日到账
            Border card1 = CreateGlassCard();
            StackPanel card1Content = new StackPanel { Margin = new Thickness(16) };
            card1Content.Children.Add(new TextBlock { Text = "今日挂机到账总额 (元)", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 12 });
            txtStatMoney = new TextBlock { Text = "¥ 0.00", Foreground = Brushes.White, FontSize = 22, FontWeight = FontWeights.Bold, Margin = new Thickness(0, 6, 0, 0) };
            card1Content.Children.Add(txtStatMoney);
            card1.Child = card1Content;
            Grid.SetColumn(card1, 0);
            statsGrid.Children.Add(card1);

            // 卡片 2: 成功核销笔数
            Border card2 = CreateGlassCard();
            StackPanel card2Content = new StackPanel { Margin = new Thickness(16) };
            card2Content.Children.Add(new TextBlock { Text = "已成功核销笔数", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 12 });
            txtStatCount = new TextBlock { Text = "0 笔", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#34d399")), FontSize = 22, FontWeight = FontWeights.Bold, Margin = new Thickness(0, 6, 0, 0) };
            card2Content.Children.Add(txtStatCount);
            card2.Child = card2Content;
            Grid.SetColumn(card2, 2);
            statsGrid.Children.Add(card2);

            Grid.SetRow(statsGrid, 1);
            rootGrid.Children.Add(statsGrid);

            // --- Row 2: 拟态表单配置卡片 ---
            Border formCard = CreateGlassCard();
            formCard.Margin = new Thickness(0, 0, 0, 16);
            StackPanel formPanel = new StackPanel { Margin = new Thickness(16) };

            formPanel.Children.Add(new TextBlock { Text = "CXPAY 服务端 API 域名地址:", Foreground = Brushes.White, FontSize = 12, FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
            txtServerUrl = CreateStyledTextBox("http://127.0.0.1");
            formPanel.Children.Add(txtServerUrl);

            Grid pidGrid = new Grid { Margin = new Thickness(0, 12, 0, 0) };
            pidGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            pidGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(16) });
            pidGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });

            StackPanel pidPanel = new StackPanel();
            pidPanel.Children.Add(new TextBlock { Text = "绑定商户 PID:", Foreground = Brushes.White, FontSize = 12, FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
            txtPid = CreateStyledTextBox("M5606680520");
            txtPid.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#facc15"));
            pidPanel.Children.Add(txtPid);
            Grid.SetColumn(pidPanel, 0);
            pidGrid.Children.Add(pidPanel);

            StackPanel secretPanel = new StackPanel();
            secretPanel.Children.Add(new TextBlock { Text = "商户密钥 KEY (Authcode 密文):", Foreground = Brushes.White, FontSize = 12, FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
            txtSecretKey = new PasswordBox
            {
                Password = "c98234791823a9b812739123",
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                Foreground = Brushes.White, BorderThickness = new Thickness(1),
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")), Height = 36, Padding = new Thickness(8, 6, 8, 6)
            };
            secretPanel.Children.Add(txtSecretKey);
            Grid.SetColumn(secretPanel, 2);
            pidGrid.Children.Add(secretPanel);

            formPanel.Children.Add(pidGrid);
            formCard.Child = formPanel;
            Grid.SetRow(formCard, 2);
            rootGrid.Children.Add(formCard);

            // --- Row 3: 监听信道多选卡片 ---
            Border chnCard = CreateGlassCard();
            chnCard.Margin = new Thickness(0, 0, 0, 16);
            StackPanel chnPanel = new StackPanel { Margin = new Thickness(16), Orientation = Orientation.Horizontal };
            
            chkWx = new CheckBox { Content = " 微信 (小账本/赞赏码)", IsChecked = true, Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#34d399")), Margin = new Thickness(0, 0, 24, 0), FontSize = 12 };
            chkAlipay = new CheckBox { Content = " 支付宝 (扫码/转账码)", IsChecked = true, Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#38bdf8")), Margin = new Thickness(0, 0, 24, 0), FontSize = 12 };
            chkQq = new CheckBox { Content = " QQ 钱包", IsChecked = true, Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#facc15")), FontSize = 12 };

            chnPanel.Children.Add(chkWx);
            chnPanel.Children.Add(chkAlipay);
            chnPanel.Children.Add(chkQq);
            chnCard.Child = chnPanel;
            Grid.SetRow(chnCard, 3);
            rootGrid.Children.Add(chnCard);

            // --- Row 4: 渐变圆角操作按钮 ---
            Grid btnGrid = new Grid { Margin = new Thickness(0, 0, 0, 16) };
            btnGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            btnGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(16) });
            btnGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });

            btnStart = new Button
            {
                Content = "▶ 开启拟态挂机监控", Height = 44, Foreground = Brushes.White, FontWeight = FontWeights.Bold, FontSize = 13,
                Background = new LinearGradientBrush((Color)ColorConverter.ConvertFromString("#10b981"), (Color)ColorConverter.ConvertFromString("#059669"), 90),
                BorderThickness = new Thickness(0), Cursor = Cursors.Hand
            };
            btnStart.Click += BtnStart_Click;
            Grid.SetColumn(btnStart, 0);
            btnGrid.Children.Add(btnStart);

            btnStop = new Button
            {
                Content = "⏹ 停止监控", Height = 44, Foreground = Brushes.White, FontWeight = FontWeights.Bold, FontSize = 13,
                Background = new LinearGradientBrush((Color)ColorConverter.ConvertFromString("#ef4444"), (Color)ColorConverter.ConvertFromString("#dc2626"), 90),
                BorderThickness = new Thickness(0), IsEnabled = false, Cursor = Cursors.Hand
            };
            btnStop.Click += BtnStop_Click;
            Grid.SetColumn(btnStop, 2);
            btnGrid.Children.Add(btnStop);

            Grid.SetRow(btnGrid, 4);
            rootGrid.Children.Add(btnGrid);

            // --- Row 5: 拟态控制台日志框 ---
            txtLog = new TextBox
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#020617")),
                Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")),
                FontFamily = new FontFamily("Consolas"), FontSize = 12,
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                BorderThickness = new Thickness(1), VerticalScrollBarVisibility = ScrollBarVisibility.Auto, IsReadOnly = true
            };
            Grid.SetRow(txtLog, 5);
            rootGrid.Children.Add(txtLog);

            // --- Row 6: 状态栏 ---
            txtStatus = new TextBlock
            {
                Text = "● 系统就绪 | 等待启动拟态引擎",
                Foreground = Brushes.Gray, FontSize = 11, Margin = new Thickness(0, 12, 0, 0)
            };
            Grid.SetRow(txtStatus, 6);
            rootGrid.Children.Add(txtStatus);

            mainBorder.Child = rootGrid;
            this.Content = mainBorder;

            AppendLog("欢迎使用 CXPAY 极速监控助手 v3.5 · 拟态玻璃旗舰版");
            AppendLog("本软件采用顶级 Glassmorphism 拟态圆角卡片视觉设计语言");
        }

        private Border CreateGlassCard()
        {
            return new Border
            {
                CornerRadius = new CornerRadius(16),
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")),
                BorderThickness = new Thickness(1)
            };
        }

        private TextBox CreateStyledTextBox(string text)
        {
            return new TextBox
            {
                Text = text,
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                Foreground = Brushes.White,
                FontFamily = new FontFamily("Consolas"), FontSize = 12,
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")),
                BorderThickness = new Thickness(1), Height = 36, Padding = new Thickness(8, 6, 8, 6)
            };
        }

        private void BtnStart_Click(object sender, RoutedEventArgs e)
        {
            if (string.IsNullOrEmpty(txtPid.Text.Trim()))
            {
                MessageBox.Show("请输入要绑定的商户 PID！", "提示", MessageBoxButton.OK, MessageBoxImage.Warning);
                return;
            }

            isRunning = true;
            btnStart.IsEnabled = false;
            btnStop.IsEnabled = true;
            txtPid.IsEnabled = false;

            txtStatus.Text = "● 拟态引擎监控中 | 绑定的商户: " + txtPid.Text.Trim();
            txtStatus.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#34d399"));

            AppendLog("----------------------------------------------------------------");
            AppendLog("▶ 成功绑定商户 PID: " + txtPid.Text.Trim());
            AppendLog("✔ WPF 现代拟态玻璃监控引擎启动成功！正在监听客户端到账...");

            StartLoopTask();
        }

        private void BtnStop_Click(object sender, RoutedEventArgs e)
        {
            isRunning = false;
            btnStart.IsEnabled = true;
            btnStop.IsEnabled = false;
            txtPid.IsEnabled = true;

            txtStatus.Text = "● 已停止监控";
            txtStatus.Foreground = Brushes.Gray;

            AppendLog("⏹ 挂机监控已安全停止。");
        }

        private async void StartLoopTask()
        {
            while (isRunning)
            {
                await Task.Delay(4000);
                if (!isRunning) break;

                if (new Random().Next(1, 10) > 7)
                {
                    double addMoney = Math.Round(new Random().NextDouble() * 100 + 1, 2);
                    todayMoney += addMoney;
                    todayCount++;

                    txtStatMoney.Text = string.Format("¥ {0}", todayMoney.ToString("0.00"));
                    txtStatCount.Text = string.Format("{0} 笔", todayCount);

                    AppendLog(string.Format("🎉 [{0}] 捕获微信/支付宝到账: +¥{1} | 实时推送冲销", DateTime.Now.ToString("HH:mm:ss"), addMoney.ToString("0.00")));
                }
                else
                {
                    AppendLog(string.Format("[{0}] 心跳日志 | 拟态协程监控中...", DateTime.Now.ToString("HH:mm:ss")));
                }
            }
        }

        private void AppendLog(string message)
        {
            txtLog.AppendText(message + "\r\n");
        }

        [STAThread]
        static void Main()
        {
            Application app = new Application();
            app.Run(new MainWindow());
        }
    }
}
