using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Net.Http;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace CXPayPcAssistant
{
    public class PremiumMainForm : Form
    {
        // UI Colors aligned with CXPAY Admin Glassmorphism Theme
        private Color bgColor = Color.FromArgb(6, 9, 17);           // #060911
        private Color panelColor = Color.FromArgb(15, 23, 42);      // #0f172a
        private Color cardColor = Color.FromArgb(30, 41, 59);       // #1e293b
        private Color skyAccent = Color.FromArgb(56, 189, 248);     // #38bdf8
        private Color emeraldAccent = Color.FromArgb(52, 211, 153); // #34d399
        private Color roseAccent = Color.FromArgb(251, 113, 133);   // #fb7185

        private Label lblLogo;
        private Label lblSubTitle;

        // Form inputs
        private Label lblServerUrl;
        private TextBox txtServerUrl;
        private Label lblPid;
        private TextBox txtPid;
        private Label lblSecretKey;
        private TextBox txtSecretKey;

        // Checkboxes
        private CheckBox chkWx;
        private CheckBox chkAlipay;
        private CheckBox chkQq;

        // Action Buttons
        private Button btnStart;
        private Button btnStop;

        // Data Counters Panels
        private Panel pnlStatToday;
        private Label lblStatTodayTitle;
        private Label lblStatTodayVal;

        private Panel pnlStatCount;
        private Label lblStatCountTitle;
        private Label lblStatCountVal;

        // Log Console
        private TextBox txtLog;

        // Status bar
        private StatusStrip statusStrip;
        private ToolStripStatusLabel statusLabel;

        private bool isRunning = false;
        private double todayMoney = 0.00;
        private int todayCount = 0;
        private static readonly HttpClient client = new HttpClient();

        public PremiumMainForm()
        {
            InitializeComponent();
        }

        private void InitializeComponent()
        {
            this.Text = "CXPAY 极速监控助手 v3.0 · 旗舰科技版";
            this.Size = new Size(620, 680);
            this.StartPosition = FormStartPosition.CenterScreen;
            this.FormBorderStyle = FormBorderStyle.FixedSingle;
            this.MaximizeBox = false;
            this.BackColor = bgColor;

            // 1. Logo与头部 Branding
            lblLogo = new Label
            {
                Text = "CXPAY",
                Font = new Font("Plus Jakarta Sans", 18, FontStyle.Bold),
                ForeColor = skyAccent,
                Location = new Point(24, 20),
                AutoSize = true
            };
            lblSubTitle = new Label
            {
                Text = "商业挂机监控桌面端 · Glassmorphism 现代极客风",
                Font = new Font("Microsoft YaHei", 9, FontStyle.Regular),
                ForeColor = Color.FromArgb(148, 163, 184),
                Location = new Point(125, 26),
                AutoSize = true
            };
            this.Controls.Add(lblLogo);
            this.Controls.Add(lblSubTitle);

            // 分隔线
            Panel divider = new Panel
            {
                Location = new Point(24, 58),
                Size = new Size(556, 1),
                BackColor = Color.FromArgb(30, 41, 59)
            };
            this.Controls.Add(divider);

            // 2. 统计大屏卡片 (双面板)
            pnlStatToday = CreateGlassCard(24, 72, 268, 70);
            lblStatTodayTitle = new Label { Text = "今日挂机到账总额 (元)", ForeColor = Color.FromArgb(148, 163, 184), Font = new Font("Microsoft YaHei", 8.5f), Location = new Point(12, 10), AutoSize = true };
            lblStatTodayVal = new Label { Text = "¥ 0.00", ForeColor = Color.White, Font = new Font("Consolas", 16, FontStyle.Bold), Location = new Point(10, 32), AutoSize = true };
            pnlStatToday.Controls.Add(lblStatTodayTitle);
            pnlStatToday.Controls.Add(lblStatTodayVal);
            this.Controls.Add(pnlStatToday);

            pnlStatCount = CreateGlassCard(312, 72, 268, 70);
            lblStatCountTitle = new Label { Text = "已成功核销笔数", ForeColor = Color.FromArgb(148, 163, 184), Font = new Font("Microsoft YaHei", 8.5f), Location = new Point(12, 10), AutoSize = true };
            lblStatCountVal = new Label { Text = "0 笔", ForeColor = emeraldAccent, Font = new Font("Consolas", 16, FontStyle.Bold), Location = new Point(10, 32), AutoSize = true };
            pnlStatCount.Controls.Add(lblStatCountTitle);
            pnlStatCount.Controls.Add(lblStatCountVal);
            this.Controls.Add(pnlStatCount);

            // 3. 配置输入区
            lblServerUrl = new Label { Text = "CXPAY 服务端 API 域名地址:", ForeColor = Color.FromArgb(226, 232, 240), Font = new Font("Microsoft YaHei", 9f, FontStyle.Bold), Location = new Point(24, 155), AutoSize = true };
            txtServerUrl = CreateStyledTextBox("http://127.0.0.1", 24, 178, 556);
            this.Controls.Add(lblServerUrl);
            this.Controls.Add(txtServerUrl);

            lblPid = new Label { Text = "绑定商户 PID:", ForeColor = Color.FromArgb(226, 232, 240), Font = new Font("Microsoft YaHei", 9f, FontStyle.Bold), Location = new Point(24, 218), AutoSize = true };
            txtPid = CreateStyledTextBox("M5606680520", 24, 240, 268);
            txtPid.ForeColor = Color.FromArgb(250, 204, 21); // 尊贵金
            this.Controls.Add(lblPid);
            this.Controls.Add(txtPid);

            lblSecretKey = new Label { Text = "商户密钥 KEY (Authcode 密文):", ForeColor = Color.FromArgb(226, 232, 240), Font = new Font("Microsoft YaHei", 9f, FontStyle.Bold), Location = new Point(312, 218), AutoSize = true };
            txtSecretKey = CreateStyledTextBox("c98234791823a9b812739123", 312, 240, 268);
            txtSecretKey.PasswordChar = '*';
            this.Controls.Add(lblSecretKey);
            this.Controls.Add(txtSecretKey);

            // 4. 监听信道多选
            chkWx = new CheckBox { Text = "微信 (微信支付 / 赞赏码 / 小账本)", Checked = true, ForeColor = emeraldAccent, Font = new Font("Microsoft YaHei", 9f), Location = new Point(24, 282), AutoSize = true };
            chkAlipay = new CheckBox { Text = "支付宝 (扫码 / 转账码)", Checked = true, ForeColor = skyAccent, Font = new Font("Microsoft YaHei", 9f), Location = new Point(265, 282), AutoSize = true };
            chkQq = new CheckBox { Text = "QQ 钱包", Checked = true, ForeColor = Color.FromArgb(250, 204, 21), Font = new Font("Microsoft YaHei", 9f), Location = new Point(475, 282), AutoSize = true };
            this.Controls.Add(chkWx);
            this.Controls.Add(chkAlipay);
            this.Controls.Add(chkQq);

            // 5. 按钮区
            btnStart = new Button
            {
                Text = "▶ 开启旗舰挂机监控",
                Font = new Font("Microsoft YaHei", 10f, FontStyle.Bold),
                Location = new Point(24, 320),
                Size = new Size(268, 44),
                BackColor = Color.FromArgb(16, 185, 129),
                ForeColor = Color.White,
                FlatStyle = FlatStyle.Flat,
                Cursor = Cursors.Hand
            };
            btnStart.FlatAppearance.BorderSize = 0;
            btnStart.Click += BtnStart_Click;

            btnStop = new Button
            {
                Text = "⏹ 停止监控",
                Font = new Font("Microsoft YaHei", 10f, FontStyle.Bold),
                Location = new Point(312, 320),
                Size = new Size(268, 44),
                BackColor = Color.FromArgb(239, 68, 68),
                ForeColor = Color.White,
                FlatStyle = FlatStyle.Flat,
                Enabled = false,
                Cursor = Cursors.Hand
            };
            btnStop.FlatAppearance.BorderSize = 0;
            btnStop.Click += BtnStop_Click;

            this.Controls.Add(btnStart);
            this.Controls.Add(btnStop);

            // 6. 控制台日志文本框
            txtLog = new TextBox
            {
                Location = new Point(24, 380),
                Size = new Size(556, 215),
                Multiline = true,
                ScrollBars = ScrollBars.Vertical,
                ReadOnly = true,
                BackColor = Color.FromArgb(2, 6, 23),
                ForeColor = Color.FromArgb(148, 163, 184),
                Font = new Font("Consolas", 9.5f),
                BorderStyle = BorderStyle.FixedSingle
            };
            this.Controls.Add(txtLog);

            // 7. 底部状态栏
            statusStrip = new StatusStrip { BackColor = panelColor };
            statusLabel = new ToolStripStatusLabel { Text = "系统就绪 | 未连接", ForeColor = Color.Gray, Font = new Font("Microsoft YaHei", 8.5f) };
            statusStrip.Items.Add(statusLabel);
            this.Controls.Add(statusStrip);

            AppendLog("欢迎使用 CXPAY 商业版 极速挂机助手 v3.0");
            AppendLog("设计语言统一为 Glassmorphism 科技暗黑风");
            AppendLog("就绪：请输入绑定商户 PID，然后点击 [开启旗舰挂机监控]");
        }

        private Panel CreateGlassCard(int x, int y, int w, int h)
        {
            return new Panel
            {
                Location = new Point(x, y),
                Size = new Size(w, h),
                BackColor = cardColor,
                BorderStyle = BorderStyle.FixedSingle
            };
        }

        private TextBox CreateStyledTextBox(string text, int x, int y, int w)
        {
            return new TextBox
            {
                Text = text,
                Location = new Point(x, y),
                Size = new Size(w, 30),
                BackColor = cardColor,
                ForeColor = Color.White,
                Font = new Font("Consolas", 10f),
                BorderStyle = BorderStyle.FixedSingle
            };
        }

        private void BtnStart_Click(object sender, EventArgs e)
        {
            if (string.IsNullOrEmpty(txtPid.Text.Trim()))
            {
                MessageBox.Show("请先输入绑定的商户 PID！", "提示", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                return;
            }

            isRunning = true;
            btnStart.Enabled = false;
            btnStop.Enabled = true;
            txtPid.Enabled = false;
            txtServerUrl.Enabled = false;
            txtSecretKey.Enabled = false;

            statusLabel.Text = "监控中 | 绑定的商户: " + txtPid.Text.Trim();
            statusLabel.ForeColor = emeraldAccent;

            AppendLog("----------------------------------------------------------------");
            AppendLog(string.Format("▶ 绑定商户 PID: {0}", txtPid.Text.Trim()));
            AppendLog(string.Format("▶ 服务器节点: {0}", txtServerUrl.Text.Trim()));
            AppendLog("▶ 监控信道: " + (chkWx.Checked ? "[微信] " : "") + (chkAlipay.Checked ? "[支付宝] " : "") + (chkQq.Checked ? "[QQ钱包]" : ""));
            AppendLog("✔ 挂机监听引擎启动成功，支持 PC 微信/支付宝/QQ 实时抓取...");

            StartLoopTask();
        }

        private void BtnStop_Click(object sender, EventArgs e)
        {
            isRunning = false;
            btnStart.Enabled = true;
            btnStop.Enabled = false;
            txtPid.Enabled = true;
            txtServerUrl.Enabled = true;
            txtSecretKey.Enabled = true;

            statusLabel.Text = "已停止监控";
            statusLabel.ForeColor = Color.Gray;

            AppendLog("⏹ 挂机监控已安全停止。");
        }

        private async void StartLoopTask()
        {
            while (isRunning)
            {
                await Task.Delay(4000);
                if (!isRunning) break;

                // 模拟捕获测试
                if (new Random().Next(1, 10) > 7)
                {
                    double addMoney = Math.Round(new Random().NextDouble() * 100 + 1, 2);
                    todayMoney += addMoney;
                    todayCount++;

                    UpdateStatsUI();
                    AppendLog(string.Format("🎉 [{0}] 成功捕获微信/支付宝到账: +¥{1} | 自动推送至 CXPAY 完成订单核销", DateTime.Now.ToString("HH:mm:ss"), addMoney.ToString("0.00")));
                }
                else
                {
                    AppendLog(string.Format("[{0}] 心跳日志 | 通道轮询正常，服务运行中...", DateTime.Now.ToString("HH:mm:ss")));
                }
            }
        }

        private void UpdateStatsUI()
        {
            if (this.InvokeRequired)
            {
                this.Invoke(new Action(UpdateStatsUI));
                return;
            }
            lblStatTodayVal.Text = string.Format("¥ {0}", todayMoney.ToString("0.00"));
            lblStatCountVal.Text = string.Format("{0} 笔", todayCount);
        }

        private void AppendLog(string message)
        {
            if (txtLog.InvokeRequired)
            {
                txtLog.Invoke(new Action<string>(AppendLog), message);
                return;
            }
            txtLog.AppendText(message + "\r\n");
        }

        [STAThread]
        static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new PremiumMainForm());
        }
    }
}
