using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Web.Script.Serialization;

namespace CXPayMonitor
{
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
                        ? AppConfig.AuthorizedFeedMode : persisted.CaptureMode,
                    WindowTitle = persisted.WindowTitle,
                    WindowProcessName = persisted.WindowProcessName
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
                WindowProcessName = config.WindowProcessName
            };
            AtomicWrite(configPath, json.Serialize(persisted));
        }

        private static string Protect(string value)
        {
            if (string.IsNullOrEmpty(value)) return string.Empty;
            byte[] encrypted = ProtectedData.Protect(
                Encoding.UTF8.GetBytes(value), null, DataProtectionScope.CurrentUser);
            return Convert.ToBase64String(encrypted);
        }

        private static string Unprotect(string value)
        {
            if (string.IsNullOrEmpty(value)) return string.Empty;
            byte[] decrypted = ProtectedData.Unprotect(
                Convert.FromBase64String(value), null, DataProtectionScope.CurrentUser);
            return Encoding.UTF8.GetString(decrypted);
        }

        internal static void AtomicWrite(string path, string content)
        {
            string temp = path + ".tmp";
            File.WriteAllText(temp, content, new UTF8Encoding(false));
            if (File.Exists(path)) File.Replace(temp, path, null);
            else File.Move(temp, path);
        }

        private sealed class PersistedConfig
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
        }
    }

    public sealed class QueueStore
    {
        private readonly object gate = new object();
        private readonly string path;
        private readonly string completedPath;
        private readonly JavaScriptSerializer json = new JavaScriptSerializer();
        private List<QueueItem> items;
        private HashSet<string> completed;

        public QueueStore(string dataDirectory)
        {
            path = Path.Combine(dataDirectory, "pending.json");
            completedPath = Path.Combine(dataDirectory, "completed.txt");
            items = LoadItems();
            completed = LoadCompleted();
        }

        public int Count { get { lock (gate) return items.Count; } }

        public bool Enqueue(BillEvent bill)
        {
            lock (gate)
            {
                if (completed.Contains(bill.source_bill_id)
                    || items.Any(x => x.Bill.source_bill_id == bill.source_bill_id)) return false;
                items.Add(new QueueItem { Bill = bill, Attempts = 0, NextAttemptAt = 0 });
                Save();
                return true;
            }
        }

        public QueueItem GetReady(long now)
        {
            lock (gate)
            {
                return items.FirstOrDefault(x => x.NextAttemptAt <= now);
            }
        }

        public void Complete(string sourceBillId)
        {
            lock (gate)
            {
                items.RemoveAll(x => x.Bill.source_bill_id == sourceBillId);
                if (completed.Add(sourceBillId))
                {
                    File.AppendAllText(completedPath, sourceBillId + Environment.NewLine, new UTF8Encoding(false));
                    if (completed.Count > 10000)
                    {
                        completed = new HashSet<string>(completed.Skip(completed.Count - 8000), StringComparer.Ordinal);
                        ConfigStore.AtomicWrite(completedPath, string.Join(Environment.NewLine, completed) + Environment.NewLine);
                    }
                }
                Save();
            }
        }

        public void Fail(string sourceBillId, long now)
        {
            lock (gate)
            {
                QueueItem item = items.FirstOrDefault(x => x.Bill.source_bill_id == sourceBillId);
                if (item == null) return;
                item.Attempts++;
                int delay = Math.Min(300, (int)Math.Pow(2, Math.Min(item.Attempts, 8)));
                item.NextAttemptAt = now + delay;
                Save();
            }
        }

        private List<QueueItem> LoadItems()
        {
            try
            {
                if (File.Exists(path))
                    return json.Deserialize<List<QueueItem>>(File.ReadAllText(path, Encoding.UTF8))
                           ?? new List<QueueItem>();
            }
            catch { }
            return new List<QueueItem>();
        }

        private HashSet<string> LoadCompleted()
        {
            try
            {
                if (File.Exists(completedPath))
                    return new HashSet<string>(File.ReadAllLines(completedPath, Encoding.UTF8), StringComparer.Ordinal);
            }
            catch { }
            return new HashSet<string>(StringComparer.Ordinal);
        }

        private void Save()
        {
            ConfigStore.AtomicWrite(path, json.Serialize(items));
        }
    }
}
