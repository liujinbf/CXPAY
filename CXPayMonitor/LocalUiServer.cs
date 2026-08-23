using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Threading.Tasks;
using System.Web.Script.Serialization;

namespace CXPayMonitor
{
    public sealed class LocalUiServer : IDisposable
    {
        private readonly TcpListener listener;
        private readonly ConfigStore configStore = new ConfigStore();
        private readonly JavaScriptSerializer json = new JavaScriptSerializer();
        private readonly List<object> pendingLogs = new List<object>();
        private readonly object logLock = new object();

        private MonitorEngine engine;
        private int currentQueueCount = 0;
        private bool isDisposed = false;

        public const int Port = 28788;

        public LocalUiServer()
        {
            listener = new TcpListener(IPAddress.Loopback, Port);
        }

        public void Start()
        {
            listener.Start();
            Task.Run(new Func<Task>(ListenLoopAsync));
        }

        private async Task ListenLoopAsync()
        {
            while (!isDisposed)
            {
                try
                {
                    TcpClient client = await listener.AcceptTcpClientAsync();
                    Task.Run(() => HandleClient(client));
                }
                catch (Exception)
                {
                    if (isDisposed) break;
                }
            }
        }

        private void HandleClient(TcpClient client)
        {
            using (client)
            using (NetworkStream stream = client.GetStream())
            {
                stream.ReadTimeout = 3000;
                byte[] buffer = new byte[8192];
                int read = 0;
                try
                {
                    read = stream.Read(buffer, 0, buffer.Length);
                }
                catch { return; }
                if (read <= 0) return;

                string rawReq = Encoding.UTF8.GetString(buffer, 0, read);
                string[] lines = rawReq.Split(new[] { "\r\n" }, StringSplitOptions.None);
                if (lines.Length == 0) return;

                string[] firstLine = lines[0].Split(' ');
                if (firstLine.Length < 2) return;

                string method = firstLine[0].ToUpperInvariant();
                string path = firstLine[1].Split('?')[0].ToLowerInvariant();

                string body = string.Empty;
                int bodyIdx = rawReq.IndexOf("\r\n\r\n", StringComparison.Ordinal);
                if (bodyIdx >= 0)
                {
                    body = rawReq.Substring(bodyIdx + 4);
                }

                if (method == "OPTIONS")
                {
                    SendResponse(stream, 200, "text/plain", string.Empty);
                    return;
                }

                try
                {
                    if (path == "/" || path == "/index.html")
                    {
                        string htmlPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "ui", "index.html");
                        string html = File.Exists(htmlPath) ? File.ReadAllText(htmlPath, Encoding.UTF8) : "<h1>CXPayMonitor UI</h1>";
                        SendResponse(stream, 200, "text/html; charset=utf-8", html);
                        return;
                    }

                    if (path == "/api/config")
                    {
                        if (method == "GET")
                        {
                            AppConfig cfg = configStore.Load();
                            string modeStr = "0";
                            if (cfg.CaptureMode == AppConfig.WechatHookMode) modeStr = "1";
                            else if (cfg.CaptureMode == AppConfig.AuthorizedFeedMode) modeStr = "2";

                            var data = new Dictionary<string, object>
                            {
                                { "server_url", cfg.ServerUrl ?? "https://cs.fcwan.cn" },
                                { "channel_id", cfg.ChannelId <= 0 ? 1 : cfg.ChannelId },
                                { "device_id", cfg.DeviceId ?? "" },
                                { "pay_type", cfg.PayType ?? "wxpay" },
                                { "notify_secret", cfg.NotifySecret ?? "" },
                                { "capture_mode", modeStr },
                                { "poll_seconds", cfg.PollSeconds <= 0 ? 5 : cfg.PollSeconds },
                                { "feed_url", cfg.FeedUrl ?? "" },
                                { "feed_token", cfg.FeedToken ?? "" }
                            };
                            SendJsonResponse(stream, 200, data);
                        }
                        else if (method == "POST")
                        {
                            var dict = json.Deserialize<Dictionary<string, object>>(body);
                            string mode = AppConfig.WechatUiMode;
                            string modeVal = Convert.ToString(dict.ContainsKey("capture_mode") ? dict["capture_mode"] : "0");
                            if (modeVal == "1") mode = AppConfig.WechatHookMode;
                            else if (modeVal == "2") mode = AppConfig.AuthorizedFeedMode;

                            var cfg = new AppConfig
                            {
                                ServerUrl = Convert.ToString(dict["server_url"]).Trim(),
                                ChannelId = Convert.ToInt64(dict["channel_id"]),
                                DeviceId = Convert.ToString(dict["device_id"]).Trim(),
                                PayType = Convert.ToString(dict["pay_type"]),
                                NotifySecret = Convert.ToString(dict["notify_secret"]).Trim(),
                                CaptureMode = mode,
                                PollSeconds = Convert.ToInt32(dict["poll_seconds"]),
                                FeedUrl = Convert.ToString(dict["feed_url"]).Trim(),
                                FeedToken = Convert.ToString(dict["feed_token"]).Trim()
                            };
                            configStore.Save(cfg);
                            SendJsonResponse(stream, 200, new Dictionary<string, object> { { "success", true } });
                        }
                        return;
                    }

                    if (path == "/api/windows")
                    {
                        var collector = new WechatUiCollector();
                        var list = collector.ListWindows().Select(w => new Dictionary<string, object>
                        {
                            { "title", w.Title },
                            { "handle", w.Handle.ToString() },
                            { "process_name", w.ProcessName },
                            { "process_id", w.ProcessId }
                        }).ToList();
                        SendJsonResponse(stream, 200, list);
                        return;
                    }

                    if (path == "/api/inspect")
                    {
                        var dict = json.Deserialize<Dictionary<string, object>>(body);
                        long handle = Convert.ToInt64(dict["handle"]);

                        var collector = new WechatUiCollector();
                        var win = collector.ListWindows().FirstOrDefault(w => w.Handle == handle);
                        if (win == null)
                        {
                            SendJsonResponse(stream, 200, new Dictionary<string, object> { { "success", false }, { "message", "未找到指定窗口" } });
                            return;
                        }

                        WechatSnapshot snapshot = collector.Capture(win);
                        var parser = new WechatBillParser();
                        IList<BillEvent> bills = parser.Parse(snapshot);
                        string reason;
                        bool ok = parser.IsSupportedPage(snapshot, out reason);

                        string detectedType = "未知微信页面";
                        if (snapshot.Items.Any(x => (x.Text ?? "").Contains("小账本") || (x.Text ?? "").Contains("经营报表")))
                            detectedType = "微信收款小账本（高级模式）";
                        else if (snapshot.Items.Any(x => (x.Text ?? "").Contains("收款单") || (x.Text ?? "").Contains("收款助手")))
                            detectedType = "官方微信收款单（通用模式）";

                        var sb = new StringBuilder();
                        sb.AppendLine("【智能诊断报告】");
                        sb.AppendLine("页面类型: " + detectedType);
                        sb.AppendLine("可读文字数量: " + snapshot.Items.Count);
                        sb.AppendLine("是否有效记录页: " + (ok ? "✅ 是" : "❌ 否 (" + reason + ")"));
                        sb.AppendLine("解析到账记录: " + bills.Count + " 笔");

                        SendJsonResponse(stream, 200, new Dictionary<string, object>
                        {
                            { "success", true },
                            { "report", sb.ToString() },
                            { "report_summary", detectedType + "，" + bills.Count + "笔记录" }
                        });
                        return;
                    }

                    if (path == "/api/start")
                    {
                        if (engine == null)
                        {
                            AppConfig config = configStore.Load();
                            engine = new MonitorEngine(config, configStore);
                            engine.Log += msg => PushLog(msg, "info");
                            engine.QueueChanged += count => currentQueueCount = count;
                            engine.Start();
                            PushLog("监控服务已成功启动", "succ");
                        }
                        SendJsonResponse(stream, 200, new Dictionary<string, object> { { "success", true } });
                        return;
                    }

                    if (path == "/api/stop")
                    {
                        if (engine != null)
                        {
                            engine.Dispose();
                            engine = null;
                            PushLog("监控服务已停止", "warn");
                        }
                        SendJsonResponse(stream, 200, new Dictionary<string, object> { { "success", true } });
                        return;
                    }

                    if (path == "/api/status")
                    {
                        List<object> logs;
                        lock (logLock)
                        {
                            logs = new List<object>(pendingLogs);
                            pendingLogs.Clear();
                        }
                        SendJsonResponse(stream, 200, new Dictionary<string, object>
                        {
                            { "is_running", engine != null },
                            { "queue_count", currentQueueCount },
                            { "logs", logs }
                        });
                        return;
                    }

                    SendResponse(stream, 404, "text/plain", "Not Found");
                }
                catch (Exception ex)
                {
                    SendJsonResponse(stream, 500, new Dictionary<string, object> { { "success", false }, { "message", ex.Message } });
                }
            }
        }

        private void SendJsonResponse(NetworkStream stream, int statusCode, object data)
        {
            string jsonStr = json.Serialize(data);
            SendResponse(stream, statusCode, "application/json; charset=utf-8", jsonStr);
        }

        private static void SendResponse(NetworkStream stream, int statusCode, string contentType, string content)
        {
            byte[] bodyBytes = Encoding.UTF8.GetBytes(content);
            string statusText = statusCode == 200 ? "OK" : (statusCode == 404 ? "Not Found" : "Internal Server Error");

            string header = string.Format(
                "HTTP/1.1 {0} {1}\r\n" +
                "Content-Type: {2}\r\n" +
                "Content-Length: {3}\r\n" +
                "Access-Control-Allow-Origin: *\r\n" +
                "Connection: close\r\n\r\n",
                statusCode, statusText, contentType, bodyBytes.Length
            );

            byte[] headerBytes = Encoding.ASCII.GetBytes(header);
            stream.Write(headerBytes, 0, headerBytes.Length);
            if (bodyBytes.Length > 0)
            {
                stream.Write(bodyBytes, 0, bodyBytes.Length);
            }
        }

        private void PushLog(string msg, string type)
        {
            lock (logLock)
            {
                pendingLogs.Add(new Dictionary<string, string> { { "msg", msg }, { "type", type } });
                if (pendingLogs.Count > 100) pendingLogs.RemoveAt(0);
            }
        }

        public void Dispose()
        {
            isDisposed = true;
            if (engine != null) engine.Dispose();
            try { listener.Stop(); } catch { }
        }
    }
}
