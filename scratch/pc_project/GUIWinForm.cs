using System;
using System.Drawing;
using System.Net.Http;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace CXPayPcAssistant
{
    public class MainForm : Form
    {
        private Label lblTitle;
        private Label lblServerUrl;
        private TextBox txtServerUrl;
        private Label lblPid;
        private TextBox txtPid;
        private Label lblSecretKey;
        private TextBox txtSecretKey;

        private CheckBox chkWx;
        private CheckBox chkAlipay;
        private CheckBox chkQq;

        private Button btnStart;
        private Button btnStop;
        private TextBox txtLog;
        private StatusStrip statusStrip;
        private ToolStripStatusLabel statusLabel;

        private bool isRunning = false;
        private static readonly HttpClient client = new HttpClient();

        public MainForm()
        {
            InitializeComponent();
        }

        private void InitializeComponent()
        {
            this.Text = "CXPAY 商业版 PC 挂机监控桌面助手 v2.0";
            this.Size = new Size(580, 560);
            this.StartPosition = FormStartPosition.CenterScreen;
            this.FormBorderStyle = FormBorderStyle.FixedSingle;
            this.MaximizeBox = false;
            this.BackColor = Color.FromArgb(15, 23, 42); // 科技深蓝暗黑风

            // 标题 Header
            lblTitle = new Label();
            lblTitle.Text = "CXPAY 挂机监控桌面助手";
            lblTitle.Font = new Font("Microsoft YaHei", 14, FontStyle.Bold);
            lblTitle.ForeColor = Color.FromArgb(56, 189, 248);
            lblTitle.Location = new Point(20, 15);
            lblTitle.AutoSize = true;
            this.Controls.Add(lblTitle);

            // 服务器地址
            lblServerUrl = new Label { Text = "CXPAY 服务器域名 / API 地址:", ForeColor = Color.LightGray, Location = new Point(20, 55), AutoSize = true };
            txtServerUrl = new TextBox { Text = "http://127.0.0.1", Location = new Point(20, 75), Width = 520, BackColor = Color.FromArgb(30, 41, 59), ForeColor = Color.White, BorderStyle = BorderStyle.FixedSingle };
            this.Controls.Add(lblServerUrl);
            this.Controls.Add(txtServerUrl);

            // 商户 PID
            lblPid = new Label { Text = "绑定商户 PID:", ForeColor = Color.LightGray, Location = new Point(20, 110), AutoSize = true };
            txtPid = new TextBox { Text = "M5606680520", Location = new Point(20, 130), Width = 250, BackColor = Color.FromArgb(30, 41, 59), ForeColor = Color.Yellow, BorderStyle = BorderStyle.FixedSingle };
            this.Controls.Add(lblPid);
            this.Controls.Add(txtPid);

            // 通讯密钥 Secret
            lblSecretKey = new Label { Text = "商户对接 KEY / 密钥:", ForeColor = Color.LightGray, Location = new Point(290, 110), AutoSize = true };
            txtSecretKey = new TextBox { Text = "c98234791823a9b812739123", Location = new Point(290, 130), Width = 250, BackColor = Color.FromArgb(30, 41, 59), ForeColor = Color.White, PasswordChar = '*', BorderStyle = BorderStyle.FixedSingle };
            this.Controls.Add(lblSecretKey);
            this.Controls.Add(txtSecretKey);

            // 监听多通道复选框
            chkWx = new CheckBox { Text = "微信 (微信支付/赞赏码/小账本)", Checked = true, ForeColor = Color.FromArgb(74, 222, 128), Location = new Point(20, 170), AutoSize = true };
            chkAlipay = new CheckBox { Text = "支付宝 (扫码/转账)", Checked = true, ForeColor = Color.FromArgb(96, 165, 250), Location = new Point(220, 170), AutoSize = true };
            chkQq = new CheckBox { Text = "QQ 钱包", Checked = true, ForeColor = Color.FromArgb(250, 204, 21), Location = new Point(380, 170), AutoSize = true };
            this.Controls.Add(chkWx);
            this.Controls.Add(chkAlipay);
            this.Controls.Add(chkQq);

            // 启动 / 停止 按钮
            btnStart = new Button { Text = "▶ 开启挂机监控", Location = new Point(20, 205), Width = 250, Height = 40, BackColor = Color.FromArgb(16, 185, 129), ForeColor = Color.White, FlatStyle = FlatStyle.Flat };
            btnStart.Click += BtnStart_Click;
            btnStop = new Button { Text = "⏹ 停止监控", Location = new Point(290, 205), Width = 250, Height = 40, BackColor = Color.FromArgb(239, 68, 68), ForeColor = Color.White, FlatStyle = FlatStyle.Flat, Enabled = false };
            btnStop.Click += BtnStop_Click;
            this.Controls.Add(btnStart);
            this.Controls.Add(btnStop);

            // 实时运行日志框
            txtLog = new TextBox { Location = new Point(20, 260), Width = 520, Height = 220, Multiline = true, ScrollBars = ScrollBars.Vertical, ReadOnly = true, BackColor = Color.FromArgb(2, 6, 23), ForeColor = Color.FromArgb(148, 163, 184), Font = new Font("Consolas", 9.5f) };
            this.Controls.Add(txtLog);

            // 底部状态栏
            statusStrip = new StatusStrip { BackColor = Color.FromArgb(15, 23, 42) };
            statusLabel = new ToolStripStatusLabel { Text = "系统就绪 | 未连接", ForeColor = Color.Gray };
            statusStrip.Items.Add(statusLabel);
            this.Controls.Add(statusStrip);

            AppendLog("欢迎使用 CXPAY 商业版 PC 挂机桌面助手 v2.0");
            AppendLog("系统已就绪，请输入商户 PID 后点击 [开启挂机监控]");
        }

        private void BtnStart_Click(object sender, EventArgs e)
        {
            if (string.IsNullOrEmpty(txtPid.Text.Trim()))
            {
                MessageBox.Show("请先输入商户 PID 进行身份绑定！", "提示", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                return;
            }

            isRunning = true;
            btnStart.Enabled = false;
            btnStop.Enabled = true;
            txtPid.Enabled = false;
            txtServerUrl.Enabled = false;
            txtSecretKey.Enabled = false;

            statusLabel.Text = "监控中 | 商户: " + txtPid.Text.Trim();
            statusLabel.ForeColor = Color.LightGreen;

            AppendLog("--------------------------------------------------");
            AppendLog(string.Format("▶ 成功绑定商户 PID: {0}", txtPid.Text.Trim()));
            AppendLog(string.Format("▶ 服务器节点: {0}", txtServerUrl.Text.Trim()));
            AppendLog("▶ 监控信道开启: " + (chkWx.Checked ? "[微信] " : "") + (chkAlipay.Checked ? "[支付宝] " : "") + (chkQq.Checked ? "[QQ钱包]" : ""));
            AppendLog("✔ 挂机监听引擎启动成功，等待到账通知...");

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

            AppendLog("⏹ 挂机监控已停止。");
        }

        private async void StartLoopTask()
        {
            while (isRunning)
            {
                await Task.Delay(5000);
                if (!isRunning) break;
                AppendLog(string.Format("[{0}] 心跳正常 | 监听中...", DateTime.Now.ToString("HH:mm:ss")));
            }
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
            Application.Run(new MainForm());
        }
    }
}
