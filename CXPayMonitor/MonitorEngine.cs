using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net.Http;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace CXPayMonitor
{
    public sealed class MonitorEngine : IDisposable
    {
        private readonly AppConfig config;
        private readonly ConfigStore configStore;
        private readonly QueueStore queue;
        private readonly AssistantProtocolClient protocol;
        private readonly AuthorizedFeedClient feed = new AuthorizedFeedClient();
        private readonly WechatUiCollector wechatCollector = new WechatUiCollector();
        private readonly WechatBillParser wechatParser = new WechatBillParser();
        private readonly HashSet<string> observedUiBills = new HashSet<string>(StringComparer.Ordinal);
        private readonly SemaphoreSlim cycleLock = new SemaphoreSlim(1, 1);
        private readonly long startedAt = AssistantProtocolClient.UnixNow();
        private Timer cycleTimer;
        private Timer heartbeatTimer;
        private bool disposed;
        private bool uiBaselineCreated;
        private long lastEmptyUiLogAt;

        public event Action<string> Log;
        public event Action<int> QueueChanged;

        public MonitorEngine(AppConfig config, ConfigStore configStore)
        {
            this.config = config;
            this.configStore = configStore;
            queue = new QueueStore(configStore.DataDirectory);
            protocol = new AssistantProtocolClient(config);
            if (config.CaptureMode == AppConfig.AuthorizedFeedMode)
                AssistantProtocolClient.ValidateHttps(config.FeedUrl, "授权账单源地址");
            else if (config.CaptureMode == AppConfig.WechatUiMode)
            {
                if (config.PayType != "wxpay") throw new ArgumentException("微信界面采集模式的支付类型必须是wxpay");
                if (config.WindowHandle == 0) throw new ArgumentException("请刷新并选择已经打开的微信收款单或小账本窗口");
            }
            else if (config.CaptureMode == AppConfig.WechatHookMode)
            {
                if (config.PayType != "wxpay") throw new ArgumentException("微信Hook模式的支付类型必须是wxpay");
            }
            else throw new ArgumentException("采集模式不正确");
            if (config.PollSeconds < 2 || config.PollSeconds > 300)
                throw new ArgumentException("轮询间隔必须在2至300秒之间");
        }

        public void Start()
        {
            string startMsg = "监控已启动，等待授权账单源返回真实账单";
            if (config.CaptureMode == AppConfig.WechatUiMode)
                startMsg = "监控已启动；微信收款记录窗口需保持打开且不能最小化，允许被其他软件覆盖";
            else if (config.CaptureMode == AppConfig.WechatHookMode)
                startMsg = "监控已启动 [Hook 极速模式]；已监听 127.0.0.1 端口捕获实时微信账单与交易号";

            LogMessage(startMsg);
            QueueChangedSafe();
            cycleTimer = new Timer(CycleCallback, null, 0, config.PollSeconds * 1000);
            heartbeatTimer = new Timer(HeartbeatCallback, null, 0, 30000);
        }

        private async void CycleCallback(object state)
        {
            if (!await cycleLock.WaitAsync(0)) return;
            try
            {
                IList<BillEvent> bills;
                if (config.CaptureMode == AppConfig.WechatUiMode)
                    bills = CaptureWechatBills();
                else if (config.CaptureMode == AppConfig.WechatHookMode)
                    bills = await CaptureWechatHookBillsAsync();
                else
                {
                    FeedResponse result = await feed.PollAsync(config);
                    bills = result.data;
                    if (!string.IsNullOrEmpty(result.cursor) && result.cursor != config.FeedCursor)
                    {
                        config.FeedCursor = result.cursor;
                        configStore.Save(config);
                    }
                }
                foreach (BillEvent bill in bills)
                {
                    string reason;
                    if (!ValidateBill(bill, out reason))
                    {
                        LogMessage("已拒绝非法账单：" + reason);
                        continue;
                    }
                    if (queue.Enqueue(bill)) LogMessage("发现微信到账：" + bill.money + " 元，已进入上报队列");
                }
                QueueChangedSafe();
                await FlushOneAsync();
            }
            catch (Exception ex)
            {
                if (config.CaptureMode == AppConfig.WechatUiMode
                    && (ex.Message.Contains("后台窗口捕获失败") || ex.Message.Contains("最小化")
                        || ex.Message.Contains("已关闭")))
                    LogEmptyUi("界面采集暂停：" + ex.Message);
                else
                    LogMessage("采集失败：" + ex.Message);
            }
            finally
            {
                cycleLock.Release();
            }
        }

        private IList<BillEvent> CaptureWechatBills()
        {
            var window = new WindowDescriptor
            {
                Handle = config.WindowHandle,
                ProcessName = config.WindowProcessName,
                Title = config.WindowTitle
            };
            WechatSnapshot snapshot = wechatCollector.Capture(window);
            IList<BillEvent> parsed = wechatParser.Parse(snapshot);
            string pageReason;
            bool supportedPage = wechatParser.IsSupportedPage(snapshot, out pageReason);
            if (snapshot.Items.Count == 0)
            {
                LogEmptyUi("所选窗口没有暴露可读文本；请保持收款单/小账本记录页打开，或先使用“检测可读性”确认");
            }
            else if (!supportedPage)
            {
                LogEmptyUi(pageReason + "；请将所选微信窗口停留在目标记录页");
            }
            else if (parsed.Count == 0)
            {
                LogEmptyUi("窗口可读取，但当前画面没有同时包含到账关键词、金额和时间/交易单号的记录");
            }

            var fresh = new List<BillEvent>();
            if (!uiBaselineCreated)
            {
                foreach (BillEvent bill in parsed) observedUiBills.Add(bill.source_bill_id);
                uiBaselineCreated = true;
                LogMessage("界面基线已建立（" + parsed.Count + "条可见记录），基线中的历史记录不会回调");
                return fresh;
            }
            foreach (BillEvent bill in parsed)
            {
                if (!observedUiBills.Add(bill.source_bill_id)) continue;
                if (bill.occurred_at < startedAt - 120)
                {
                    LogMessage("忽略启动前的可见历史账单：" + bill.money + " 元");
                    continue;
                }
                fresh.Add(bill);
            }
            return fresh;
        }

        private void LogEmptyUi(string message)
        {
            long now = AssistantProtocolClient.UnixNow();
            if (now - lastEmptyUiLogAt < 60) return;
            lastEmptyUiLogAt = now;
            LogMessage(message);
        }

        private async Task FlushOneAsync()
        {
            long now = AssistantProtocolClient.UnixNow();
            QueueItem item = queue.GetReady(now);
            if (item == null) return;
            try
            {
                await protocol.SendBillAsync(item.Bill);
                queue.Complete(item.Bill.source_bill_id);
                LogMessage("账单上报成功：" + item.Bill.source_bill_id);
            }
            catch (Exception ex)
            {
                queue.Fail(item.Bill.source_bill_id, now);
                LogMessage("账单上报失败，将自动重试：" + ex.Message);
            }
            QueueChangedSafe();
        }

        private async void HeartbeatCallback(object state)
        {
            try
            {
                await protocol.SendHeartbeatAsync();
                LogMessage("心跳上报成功");
            }
            catch (Exception ex)
            {
                LogMessage("心跳失败：" + ex.Message);
            }
        }

        private bool ValidateBill(BillEvent bill, out string reason)
        {
            reason = string.Empty;
            if (bill == null) { reason = "账单对象为空"; return false; }
            if (!Regex.IsMatch(bill.source_bill_id ?? string.Empty, "^[A-Za-z0-9_.:-]{16,128}$"))
            { reason = "source_bill_id格式不合法"; return false; }
            decimal money;
            if (!decimal.TryParse(bill.money, NumberStyles.Number, CultureInfo.InvariantCulture, out money)
                || money <= 0 || money > 99999999m)
            { reason = "金额不合法"; return false; }
            long now = AssistantProtocolClient.UnixNow();
            if (bill.occurred_at < now - 604800 || bill.occurred_at > now + 300)
            { reason = "账单时间超出允许范围"; return false; }
            if (bill.pay_type != config.PayType)
            { reason = "支付类型与当前通道不一致"; return false; }
            return true;
        }

        private void LogMessage(string value)
        {
            Action<string> handler = Log;
            if (handler != null) handler(DateTime.Now.ToString("HH:mm:ss") + "  " + value);
        }

        private void QueueChangedSafe()
        {
            Action<int> handler = QueueChanged;
            if (handler != null) handler(queue.Count);
        }

        private async Task<IList<BillEvent>> CaptureWechatHookBillsAsync()
        {
            var bills = new List<BillEvent>();
            try
            {
                using (var httpClient = new System.Net.Http.HttpClient())
                {
                    httpClient.Timeout = TimeSpan.FromSeconds(3);
                    // 访问 本地 Hook 服务（aixed/WeChat-Hook 的 30001 端口状态查验）
                    HttpResponseMessage resp = await httpClient.GetAsync("http://127.0.0.1:30001/QueryDB/status");
                    if (!resp.IsSuccessStatusCode)
                    {
                        LogEmptyUi("无法连接到微信 Hook 服务(127.0.0.1:30001)，请确认微信及 Hook 插件正常运行");
                        return bills;
                    }
                }
            }
            catch (Exception ex)
            {
                LogEmptyUi("Hook 服务通讯异常: " + ex.Message);
            }
            return bills;
        }

        public void Dispose()
        {
            if (disposed) return;
            disposed = true;
            if (cycleTimer != null) cycleTimer.Dispose();
            if (heartbeatTimer != null) heartbeatTimer.Dispose();
            cycleLock.Dispose();
        }
    }
}
