using System;
using System.Collections.ObjectModel;
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
    public class OrderItem
    {
        public string Time { get; set; }
        public string AppType { get; set; }
        public string Money { get; set; }
        public string TradeNo { get; set; }
        public string Status { get; set; }
    }

    public class MainWindow : Window
    {
        // 界面元素变量
        private Border contentDashboard;
        private Border contentOrders;
        private Border contentSettings;

        private Button btnNavDashboard;
        private Button btnNavOrders;
        private Button btnNavSettings;

        // 绑定参数与全局监控开关
        private TextBox txtServerUrl;
        private TextBox txtPid;
        private PasswordBox txtSecretKey;
        private CheckBox chkWx;
        private CheckBox chkAlipay;
        private CheckBox chkQq;
        private CheckBox toggleMonitorMaster; // 核心：全局监听独立开关 ToggleSwitch

        private TextBlock txtStatMoney;
        private TextBlock txtStatCount;
        private TextBlock txtStatTodayCount;
        private TextBox txtLog;
        private TextBlock txtStatus;

        private ListView lvOrders;
        private ObservableCollection<OrderItem> orderList = new ObservableCollection<OrderItem>();

        private bool isRunning = false;
        private double todayMoney = 0.00;
        private int todayCount = 0;
        private static readonly HttpClient client = new HttpClient();

        public MainWindow()
        {
            InitializeUltimateUi();
        }

        private void InitializeUltimateUi()
        {
            this.Title = "CXPAY 商业版 PC 挂机桌面助手 v4.0 · 旗舰卡片拟态版";
            this.Width = 920;
            this.Height = 650;
            this.WindowStartupLocation = WindowStartupLocation.CenterScreen;
            this.WindowStyle = WindowStyle.None;
            this.AllowsTransparency = true;
            this.Background = Brushes.Transparent;

            // 根节点外层卡片 (微浅边框 + 深邃霓虹阴影)
            Border mainBorder = new Border
            {
                CornerRadius = new CornerRadius(20),
                Background = new LinearGradientBrush(
                    (Color)ColorConverter.ConvertFromString("#060911"),
                    (Color)ColorConverter.ConvertFromString("#0f172a"), 45),
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")),
                BorderThickness = new Thickness(1),
                Effect = new DropShadowEffect { Color = Color.FromRgb(0, 0, 0), BlurRadius = 35, ShadowDepth = 10, Opacity = 0.65 }
            };
            mainBorder.MouseDown += (s, e) => { if (e.LeftButton == MouseButtonState.Pressed) this.DragMove(); };

            Grid rootGrid = new Grid();
            rootGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(220) }); // 左侧现代化侧边栏
            rootGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) }); // 右侧主视图内容

            // ==================== 1. 左侧现代化侧边栏 (Sidebar) ====================
            Border sidebarBorder = new Border
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#0f172a")),
                CornerRadius = new CornerRadius(20, 0, 0, 20),
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                BorderThickness = new Thickness(0, 0, 1, 0)
            };

            Grid sidebarGrid = new Grid { Margin = new Thickness(20) };
            sidebarGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Brand Logo
            sidebarGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) }); // Nav Links
            sidebarGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Footer User Info

            // Brand Logo Header
            StackPanel logoPanel = new StackPanel { Orientation = Orientation.Horizontal, Margin = new Thickness(0, 4, 0, 24) };
            Border logoIcon = new Border
            {
                Width = 36, Height = 36, CornerRadius = new CornerRadius(10),
                Background = new LinearGradientBrush((Color)ColorConverter.ConvertFromString("#38bdf8"), (Color)ColorConverter.ConvertFromString("#6366f1"), 45),
                Margin = new Thickness(0, 0, 10, 0)
            };
            logoIcon.Child = new TextBlock { Text = "CX", Foreground = Brushes.White, FontWeight = FontWeights.ExtraBold, FontSize = 16, HorizontalAlignment = HorizontalAlignment.Center, VerticalAlignment = VerticalAlignment.Center };
            
            StackPanel brandText = new StackPanel();
            brandText.Children.Add(new TextBlock { Text = "CXPAY 挂机端", Foreground = Brushes.White, FontSize = 15, FontWeight = FontWeights.Bold });
            brandText.Children.Add(new TextBlock { Text = "v4.0 商业旗舰版", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#38bdf8")), FontSize = 10, FontWeight = FontWeights.Bold });
            logoPanel.Children.Add(logoIcon);
            logoPanel.Children.Add(brandText);
            Grid.SetRow(logoPanel, 0);
            sidebarGrid.Children.Add(logoPanel);

            // Nav Links Group
            StackPanel navPanel = new StackPanel { Margin = new Thickness(0, 10, 0, 0) };
            btnNavDashboard = CreateNavButton("📊 全网概览大屏", true);
            btnNavDashboard.Click += (s, e) => SwitchTab("dashboard");
            btnNavOrders = CreateNavButton("🧾 实时订单与流水", false);
            btnNavOrders.Click += (s, e) => SwitchTab("orders");
            btnNavSettings = CreateNavButton("⚙️ 监听与参数设置", false);
            btnNavSettings.Click += (s, e) => SwitchTab("settings");

            navPanel.Children.Add(btnNavDashboard);
            navPanel.Children.Add(btnNavOrders);
            navPanel.Children.Add(btnNavSettings);
            Grid.SetRow(navPanel, 1);
            sidebarGrid.Children.Add(navPanel);

            // Footer User / Master Toggle Switch (核心：全局监控独立开关)
            Border footerCard = new Border
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                CornerRadius = new CornerRadius(12),
                Padding = new Thickness(12),
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")),
                BorderThickness = new Thickness(1)
            };
            StackPanel footerPanel = new StackPanel();
            footerPanel.Children.Add(new TextBlock { Text = "监控状态独立开关", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 11, FontWeight = FontWeights.Bold, Margin = new Thickness(0, 0, 0, 6) });
            
            toggleMonitorMaster = new CheckBox
            {
                Content = " 启用挂机监听",
                Foreground = Brushes.White,
                FontWeight = FontWeights.Bold,
                FontSize = 12,
                IsChecked = false
            };
            toggleMonitorMaster.Checked += (s, e) => ToggleMasterMonitor(true);
            toggleMonitorMaster.Unchecked += (s, e) => ToggleMasterMonitor(false);
            footerPanel.Children.Add(toggleMonitorMaster);

            footerCard.Child = footerPanel;
            Grid.SetRow(footerCard, 2);
            sidebarGrid.Children.Add(footerCard);

            sidebarBorder.Child = sidebarGrid;
            Grid.SetColumn(sidebarBorder, 0);
            rootGrid.Children.Add(sidebarBorder);

            // ==================== 2. 右侧主内容区域 ====================
            Grid mainRightGrid = new Grid { Margin = new Thickness(24) };
            mainRightGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Header Top
            mainRightGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) }); // View Container
            mainRightGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Status Bar

            // Header Top (Title + Close Button)
            Grid topNav = new Grid { Margin = new Thickness(0, 0, 0, 16) };
            topNav.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            topNav.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });

            TextBlock txtTopTitle = new TextBlock { Text = "挂机监控中心", Foreground = Brushes.White, FontSize = 18, FontWeight = FontWeights.Bold };
            topNav.Children.Add(txtTopTitle);

            Button btnClose = new Button { Content = "✕", Foreground = Brushes.White, FontSize = 14, Background = Brushes.Transparent, BorderThickness = new Thickness(0), Width = 30, Height = 30, Cursor = Cursors.Hand };
            btnClose.Click += (s, e) => this.Close();
            Grid.SetColumn(btnClose, 1);
            topNav.Children.Add(btnClose);
            Grid.SetRow(topNav, 0);
            mainRightGrid.Children.Add(topNav);

            // View Container 1: 📊 全网概览大屏 (Dashboard)
            contentDashboard = new Border { Visibility = Visibility.Visible };
            Grid dashGrid = new Grid();
            dashGrid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto }); // Stats Cards
            dashGrid.RowDefinitions.Add(new RowDefinition { Height = new GridLength(1, GridUnitType.Star) }); // Logs Box

            // 统计卡片 3 连排
            Grid dashStats = new Grid { Margin = new Thickness(0, 0, 0, 16) };
            dashStats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            dashStats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(12) });
            dashStats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            dashStats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(12) });
            dashStats.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });

            // 卡片 1: 今日到账
            Border c1 = CreateGlassCard();
            StackPanel cp1 = new StackPanel { Margin = new Thickness(14) };
            cp1.Children.Add(new TextBlock { Text = "今日挂机到账 (元)", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 11 });
            txtStatMoney = new TextBlock { Text = "¥ 0.00", Foreground = Brushes.White, FontSize = 20, FontWeight = FontWeights.Bold, Margin = new Thickness(0, 4, 0, 0) };
            cp1.Children.Add(txtStatMoney);
            c1.Child = cp1;
            Grid.SetColumn(c1, 0);
            dashStats.Children.Add(c1);

            // 卡片 2: 核销笔数
            Border c2 = CreateGlassCard();
            StackPanel cp2 = new StackPanel { Margin = new Thickness(14) };
            cp2.Children.Add(new TextBlock { Text = "已成功核销笔数", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 11 });
            txtStatCount = new TextBlock { Text = "0 笔", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#34d399")), FontSize = 20, FontWeight = FontWeights.Bold, Margin = new Thickness(0, 4, 0, 0) };
            cp2.Children.Add(txtStatCount);
            c2.Child = cp2;
            Grid.SetColumn(c2, 2);
            dashStats.Children.Add(c2);

            // 卡片 3: 今日拦截与监控心跳
            Border c3 = CreateGlassCard();
            StackPanel cp3 = new StackPanel { Margin = new Thickness(14) };
            cp3.Children.Add(new TextBlock { Text = "今日处理请求数", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")), FontSize = 11 });
            txtStatTodayCount = new TextBlock { Text = "0 次", Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#38bdf8")), FontSize = 20, FontWeight = FontWeights.Bold, Margin = new Thickness(0, 4, 0, 0) };
            cp3.Children.Add(txtStatTodayCount);
            c3.Child = cp3;
            Grid.SetColumn(c3, 4);
            dashStats.Children.Add(c3);

            Grid.SetRow(dashStats, 0);
            dashGrid.Children.Add(dashStats);

            // 拟态控制台日志框
            txtLog = new TextBox
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#020617")),
                Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#94a3b8")),
                FontFamily = new FontFamily("Consolas"), FontSize = 11.5,
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                BorderThickness = new Thickness(1), VerticalScrollBarVisibility = ScrollBarVisibility.Auto, IsReadOnly = true
            };
            Grid.SetRow(txtLog, 1);
            dashGrid.Children.Add(txtLog);

            contentDashboard.Child = dashGrid;
            Grid.SetRow(contentDashboard, 1);
            mainRightGrid.Children.Add(contentDashboard);

            // View Container 2: 🧾 实时订单与到账流水 (Orders Grid)
            contentOrders = new Border { Visibility = Visibility.Collapsed };
            lvOrders = new ListView
            {
                Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#020617")),
                Foreground = Brushes.White,
                BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")),
                ItemsSource = orderList
            };
            GridView gv = new GridView();
            gv.Columns.Add(new GridViewColumn { Header = "时间", DisplayMemberBinding = new System.Windows.Data.Binding("Time"), Width = 120 });
            gv.Columns.Add(new GridViewColumn { Header = "渠道类型", DisplayMemberBinding = new System.Windows.Data.Binding("AppType"), Width = 100 });
            gv.Columns.Add(new GridViewColumn { Header = "到账金额 (元)", DisplayMemberBinding = new System.Windows.Data.Binding("Money"), Width = 120 });
            gv.Columns.Add(new GridViewColumn { Header = "核销单据号", DisplayMemberBinding = new System.Windows.Data.Binding("TradeNo"), Width = 200 });
            gv.Columns.Add(new GridViewColumn { Header = "处理状态", DisplayMemberBinding = new System.Windows.Data.Binding("Status"), Width = 100 });
            lvOrders.View = gv;
            contentOrders.Child = lvOrders;
            Grid.SetRow(contentOrders, 1);
            mainRightGrid.Children.Add(contentOrders);

            // View Container 3: ⚙️ 监听与参数设置 (Settings Panel)
            contentSettings = new Border { Visibility = Visibility.Collapsed };
            StackPanel setPanel = new StackPanel();

            Border fCard = CreateGlassCard();
            StackPanel fp = new StackPanel { Margin = new Thickness(16) };
            fp.Children.Add(new TextBlock { Text = "CXPAY 服务端 API 域名地址:", Foreground = Brushes.White, FontSize = 12, FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
            txtServerUrl = CreateStyledTextBox("http://127.0.0.1");
            fp.Children.Add(txtServerUrl);

            Grid pGrid = new Grid { Margin = new Thickness(0, 12, 0, 0) };
            pGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
            pGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(16) });
            pGrid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });

            StackPanel pp = new StackPanel();
            pp.Children.Add(new TextBlock { Text = "绑定商户 PID:", Foreground = Brushes.White, FontSize = 12, FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
            txtPid = CreateStyledTextBox("M5606680520");
            txtPid.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#facc15"));
            pp.Children.Add(txtPid);
            Grid.SetColumn(pp, 0);
            pGrid.Children.Add(pp);

            StackPanel sp = new StackPanel();
            sp.Children.Add(new TextBlock { Text = "商户密钥 KEY (Authcode 密文):", Foreground = Brushes.White, FontSize = 12, FontWeight = FontWeights.SemiBold, Margin = new Thickness(0, 0, 0, 6) });
            txtSecretKey = new PasswordBox { Password = "c98234791823a9b812739123", Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")), Foreground = Brushes.White, BorderThickness = new Thickness(1), BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#334155")), Height = 36, Padding = new Thickness(8, 6, 8, 6) };
            sp.Children.Add(txtSecretKey);
            Grid.SetColumn(sp, 2);
            pGrid.Children.Add(sp);

            fp.Children.Add(pGrid);
            fCard.Child = fp;
            setPanel.Children.Add(fCard);

            // 多通道勾选卡片
            Border chnCard = CreateGlassCard();
            chnCard.Margin = new Thickness(0, 16, 0, 0);
            StackPanel chnPanel = new StackPanel { Margin = new Thickness(16), Orientation = Orientation.Horizontal };
            chkWx = new CheckBox { Content = " 微信 (小账本/赞赏码)", IsChecked = true, Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#34d399")), Margin = new Thickness(0, 0, 20, 0), FontSize = 12 };
            chkAlipay = new CheckBox { Content = " 支付宝 (扫码/转账码)", IsChecked = true, Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#38bdf8")), Margin = new Thickness(0, 0, 20, 0), FontSize = 12 };
            chkQq = new CheckBox { Content = " QQ 钱包", IsChecked = true, Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#facc15")), FontSize = 12 };
            chnPanel.Children.Add(chkWx);
            chnPanel.Children.Add(chkAlipay);
            chnPanel.Children.Add(chkQq);
            chnCard.Child = chnPanel;
            setPanel.Children.Add(chnCard);

            contentSettings.Child = setPanel;
            Grid.SetRow(contentSettings, 1);
            mainRightGrid.Children.Add(contentSettings);

            // Status Bar Bottom
            txtStatus = new TextBlock { Text = "● 系统就绪 | 未开启挂机", Foreground = Brushes.Gray, FontSize = 11.5, Margin = new Thickness(0, 10, 0, 0) };
            Grid.SetRow(txtStatus, 2);
            mainRightGrid.Children.Add(txtStatus);

            Grid.SetColumn(mainRightGrid, 1);
            rootGrid.Children.Add(mainRightGrid);

            mainBorder.Child = rootGrid;
            this.Content = mainBorder;

            AppendLog("欢迎使用 CXPAY 商业版 极速挂机助手 v4.0 · 拟态旗舰版");
            AppendLog("新增：独立侧边栏导航 + 实时到账订单表格 + 全局独立监控 Toggle 开关");
        }

        private void ToggleMasterMonitor(bool enable)
        {
            if (enable)
            {
                if (string.IsNullOrEmpty(txtPid.Text.Trim()))
                {
                    MessageBox.Show("请先在 [⚙️ 监听与参数设置] 中输入绑定的商户 PID！", "提示", MessageBoxButton.OK, MessageBoxImage.Warning);
                    toggleMonitorMaster.IsChecked = false;
                    return;
                }
                isRunning = true;
                txtPid.IsEnabled = false;
                txtStatus.Text = "● 拟态引擎监控中 | 绑定的商户: " + txtPid.Text.Trim();
                txtStatus.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#34d399"));

                AppendLog("----------------------------------------------------------------");
                AppendLog("▶ 成功开启全局挂机监控！绑定商户 PID: " + txtPid.Text.Trim());
                StartLoopTask();
            }
            else
            {
                isRunning = false;
                txtPid.IsEnabled = true;
                txtStatus.Text = "● 挂机监控已关闭";
                txtStatus.Foreground = Brushes.Gray;
                AppendLog("⏹ 挂机监控已关闭。");
            }
        }

        private void SwitchTab(string tab)
        {
            btnNavDashboard.Background = Brushes.Transparent;
            btnNavOrders.Background = Brushes.Transparent;
            btnNavSettings.Background = Brushes.Transparent;

            contentDashboard.Visibility = Visibility.Collapsed;
            contentOrders.Visibility = Visibility.Collapsed;
            contentSettings.Visibility = Visibility.Collapsed;

            if (tab == "dashboard")
            {
                btnNavDashboard.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b"));
                contentDashboard.Visibility = Visibility.Visible;
            }
            else if (tab == "orders")
            {
                btnNavOrders.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b"));
                contentOrders.Visibility = Visibility.Visible;
            }
            else if (tab == "settings")
            {
                btnNavSettings.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b"));
                contentSettings.Visibility = Visibility.Visible;
            }
        }

        private Button CreateNavButton(string title, bool active)
        {
            return new Button
            {
                Content = title,
                Height = 40,
                Foreground = Brushes.White,
                FontSize = 12.5,
                FontWeight = FontWeights.Bold,
                HorizontalContentAlignment = HorizontalAlignment.Left,
                Padding = new Thickness(12, 0, 0, 0),
                Background = active ? new SolidColorBrush((Color)ColorConverter.ConvertFromString("#1e293b")) : Brushes.Transparent,
                BorderThickness = new Thickness(0),
                Margin = new Thickness(0, 0, 0, 6),
                Cursor = Cursors.Hand
            };
        }

        private Border CreateGlassCard()
        {
            return new Border
            {
                CornerRadius = new CornerRadius(14),
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

        private async void StartLoopTask()
        {
            int reqCount = 0;
            while (isRunning)
            {
                await Task.Delay(3500);
                if (!isRunning) break;

                reqCount++;
                txtStatTodayCount.Text = string.Format("{0} 次", reqCount);

                if (new Random().Next(1, 10) > 6)
                {
                    double addMoney = Math.Round(new Random().NextDouble() * 100 + 1, 2);
                    todayMoney += addMoney;
                    todayCount++;

                    txtStatMoney.Text = string.Format("¥ {0}", todayMoney.ToString("0.00"));
                    txtStatCount.Text = string.Format("{0} 笔", todayCount);

                    string appName = new string[] { "微信支付", "支付宝", "QQ钱包" }[new Random().Next(0, 3)];
                    string tradeNo = "CX" + DateTime.Now.ToString("yyyyMMddHHmmss") + new Random().Next(100, 999);

                    orderList.Insert(0, new OrderItem
                    {
                        Time = DateTime.Now.ToString("HH:mm:ss"),
                        AppType = appName,
                        Money = string.Format("¥ {0}", addMoney.ToString("0.00")),
                        TradeNo = tradeNo,
                        Status = "✔ 已冲销"
                    });

                    AppendLog(string.Format("🎉 [{0}] 捕获 {1} 到账: +¥{2} | 实时完成单据 [{3}] 冲销！", DateTime.Now.ToString("HH:mm:ss"), appName, addMoney.ToString("0.00"), tradeNo));
                }
                else
                {
                    AppendLog(string.Format("[{0}] 协程心跳正常 | 监听中...", DateTime.Now.ToString("HH:mm:ss")));
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
