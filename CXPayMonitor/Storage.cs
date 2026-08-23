using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;

namespace CXPayMonitor
{
    public sealed class PersistedConfig
    {
        public string ServerUrl { get; set; }
        public long ChannelId { get; set; }
        public string DeviceId { get; set; }
        public string PayType { get; set; }
        public string NotifySecret { get; set; }
        public string FeedUrl { get; set; }
        public string FeedToken { get; set; }
        public string FeedCursor { get; set; }
        public int PollSeconds { get; set; }
        public string CaptureMode { get; set; }
        public string WindowTitle { get; set; }
        public string WindowProcessName { get; set; }
        public long WindowHandle { get; set; }
    }

    public sealed class ConfigStore
    {
        private readonly JavaScriptSerializer json = new JavaScriptSerializer();
        public readonly string DataDirectory;
        private readonly string configPath;

        public ConfigStore()
        {
            DataDirectory = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "CXPAY", "PcMonitor");
            Directory.CreateDirectory(DataDirectory);
            configPath = Path.Combine(DataDirectory, "config.json");
        }

        public AppConfig Load()
        {
            // 1. 优先检测当前运行目录下是否存在商户后台打包附带的专属预装配置 config.json
            string localPresetPath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "config.json");
            if (File.Exists(localPresetPath))
            {
                try
                {
                    string content = File.ReadAllText(localPresetPath, Encoding.UTF8);
                    var dict = json.Deserialize<Dictionary<string, object>>(content);
                    if (dict != null && dict.ContainsKey("channel_id"))
                    {
                        var preset = new AppConfig
                        {
                            ServerUrl = dict.ContainsKey("server_url") ? Convert.ToString(dict["server_url"]).Trim() : "https://cs.fcwan.cn",
                            ChannelId = Convert.ToInt64(dict["channel_id"]),
                            DeviceId = dict.ContainsKey("device_id") ? Convert.ToString(dict["device_id"]).Trim() : ("PC_" + Environment.MachineName),
                            PayType = dict.ContainsKey("pay_type") ? Convert.ToString(dict["pay_type"]).Trim() : "wxpay",
                            NotifySecret = dict.ContainsKey("notify_secret") ? Convert.ToString(dict["notify_secret"]).Trim() : "",
                            CaptureMode = dict.ContainsKey("capture_mode") ? Convert.ToString(dict["capture_mode"]).Trim() : AppConfig.WechatUiMode,
                            PollSeconds = dict.ContainsKey("poll_seconds") ? Convert.ToInt32(dict["poll_seconds"]) : 5
                        };
                        Save(preset);
                        return preset;
                    }
                }
                catch { }
            }

            // 2. 读取本地 DPAPI 加密配置
            if (!File.Exists(configPath)) return AppConfig.CreateDefault();
            try
            {
                var persisted = json.Deserialize<PersistedConfig>(File.ReadAllText(configPath, Encoding.UTF8));
                return new AppConfig
                {
                    ServerUrl = persisted.ServerUrl,
                    ChannelId = persisted.ChannelId,
                    DeviceId = persisted.DeviceId,
                    PayType = persisted.PayType,
                    NotifySecret = Unprotect(persisted.NotifySecret),
                    FeedUrl = persisted.FeedUrl,
                    FeedToken = Unprotect(persisted.FeedToken),
                    FeedCursor = persisted.FeedCursor,
                    PollSeconds = persisted.PollSeconds <= 0 ? 5 : persisted.PollSeconds,
                    CaptureMode = string.IsNullOrEmpty(persisted.CaptureMode)
                        ? AppConfig.WechatUiMode : persisted.CaptureMode,
                    WindowTitle = persisted.WindowTitle,
                    WindowProcessName = persisted.WindowProcessName,
                    WindowHandle = persisted.WindowHandle
                };
            }
            catch
            {
                return AppConfig.CreateDefault();
            }
        }

        public void Save(AppConfig config)
        {
            var persisted = new PersistedConfig
            {
                ServerUrl = config.ServerUrl,
                ChannelId = config.ChannelId,
                DeviceId = config.DeviceId,
                PayType = config.PayType,
                NotifySecret = Protect(config.NotifySecret),
                FeedUrl = config.FeedUrl,
                FeedToken = Protect(config.FeedToken),
                FeedCursor = config.FeedCursor,
                PollSeconds = config.PollSeconds,
                CaptureMode = config.CaptureMode,
                WindowTitle = config.WindowTitle,
                WindowProcessName = config.WindowProcessName,
                WindowHandle = config.WindowHandle
            };
            File.WriteAllText(configPath, json.Serialize(persisted), Encoding.UTF8);
        }

        public void SaveFeedCursor(string cursor)
        {
            AppConfig current = Load();
            current.FeedCursor = cursor;
            Save(current);
        }

        private static string Protect(string plainText)
        {
            if (string.IsNullOrEmpty(plainText)) return string.Empty;
            byte[] bytes = Encoding.UTF8.GetBytes(plainText);
            byte[] protectedBytes = ProtectedData.Protect(bytes, null, DataProtectionScope.CurrentUser);
            return Convert.ToBase64String(protectedBytes);
        }

        private static string Unprotect(string base64)
        {
            if (string.IsNullOrEmpty(base64)) return string.Empty;
            try
            {
                byte[] protectedBytes = Convert.FromBase64String(base64);
                byte[] bytes = ProtectedData.Unprotect(protectedBytes, null, DataProtectionScope.CurrentUser);
                return Encoding.UTF8.GetString(bytes);
            }
            catch
            {
                return string.Empty;
            }
        }
    }

    public sealed class QueueStore
    {
        private readonly JavaScriptSerializer json = new JavaScriptSerializer();
        private readonly string queueFilePath;
        private readonly object syncRoot = new object();

        public QueueStore(string dataDirectory)
        {
            queueFilePath = Path.Combine(dataDirectory, "outbox_queue.json");
        }

        public int Count
        {
            get
            {
                lock (syncRoot)
                {
                    return ReadQueueNoLock().Count;
                }
            }
        }

        public bool Enqueue(BillEvent bill)
        {
            return Enqueue(bill, AssistantProtocolClient.UnixNow());
        }

        public bool Enqueue(BillEvent bill, long now)
        {
            lock (syncRoot)
            {
                List<QueueItem> list = ReadQueueNoLock();
                if (!list.Any(x => x.Bill.source_bill_id == bill.source_bill_id))
                {
                    list.Add(new QueueItem
                    {
                        Bill = bill,
                        Attempts = 0,
                        NextAttemptAt = now
                    });
                    WriteQueueNoLock(list);
                    return true;
                }
                return false;
            }
        }

        public QueueItem GetReady(long now)
        {
            lock (syncRoot)
            {
                return ReadQueueNoLock().FirstOrDefault(x => x.NextAttemptAt <= now);
            }
        }

        public void Complete(string sourceBillId)
        {
            lock (syncRoot)
            {
                List<QueueItem> list = ReadQueueNoLock()
                    .Where(x => x.Bill.source_bill_id != sourceBillId)
                    .ToList();
                WriteQueueNoLock(list);
            }
        }

        public void Fail(string sourceBillId, long now)
        {
            lock (syncRoot)
            {
                List<QueueItem> list = ReadQueueNoLock();
                QueueItem item = list.FirstOrDefault(x => x.Bill.source_bill_id == sourceBillId);
                if (item != null)
                {
                    item.Attempts++;
                    int delay = Math.Min(300, (int)Math.Pow(2, Math.Min(item.Attempts, 8)));
                    item.NextAttemptAt = now + delay;
                    WriteQueueNoLock(list);
                }
            }
        }

        private List<QueueItem> ReadQueueNoLock()
        {
            if (!File.Exists(queueFilePath)) return new List<QueueItem>();
            try
            {
                string text = File.ReadAllText(queueFilePath, Encoding.UTF8);
                var list = json.Deserialize<List<QueueItem>>(text);
                return list ?? new List<QueueItem>();
            }
            catch
            {
                return new List<QueueItem>();
            }
        }

        private void WriteQueueNoLock(List<QueueItem> list)
        {
            File.WriteAllText(queueFilePath, json.Serialize(list), Encoding.UTF8);
        }
    }
}
