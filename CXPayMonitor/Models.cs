using System;
using System.Collections.Generic;

namespace CXPayMonitor
{
    public sealed class AppConfig
    {
        public const string WechatUiMode = "wechat_ui";
        public const string WechatHookMode = "wechat_hook";
        public const string AuthorizedFeedMode = "authorized_feed";

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

        public static AppConfig CreateDefault()
        {
            return new AppConfig
            {
                DeviceId = "PC_" + Environment.MachineName,
                PayType = "wxpay",
                CaptureMode = WechatUiMode,
                PollSeconds = 5,
                FeedCursor = string.Empty
            };
        }
    }

    public sealed class WindowDescriptor
    {
        public long Handle { get; set; }
        public int ProcessId { get; set; }
        public string ProcessName { get; set; }
        public string Title { get; set; }

        public override string ToString()
        {
            return (Title ?? "未命名窗口") + "  [" + (ProcessName ?? "未知进程") + ", PID " + ProcessId + "]";
        }
    }

    public sealed class AutomationTextItem
    {
        public string Text { get; set; }
        public string Path { get; set; }
        public string ControlType { get; set; }
        public double Left { get; set; }
        public double Top { get; set; }
        public double Width { get; set; }
        public double Height { get; set; }
    }

    public sealed class WechatSnapshot
    {
        public DateTime CapturedAt { get; set; }
        public string WindowTitle { get; set; }
        public int CaptureWidth { get; set; }
        public int CaptureHeight { get; set; }
        public List<AutomationTextItem> Items { get; set; }
    }

    public sealed class BillEvent
    {
        public string source_bill_id { get; set; }
        public string money { get; set; }
        public long occurred_at { get; set; }
        public string remark { get; set; }
        public string pay_type { get; set; }
    }

    public sealed class FeedResponse
    {
        public int code { get; set; }
        public string message { get; set; }
        public string cursor { get; set; }
        public List<BillEvent> data { get; set; }
    }

    public sealed class QueueItem
    {
        public BillEvent Bill { get; set; }
        public int Attempts { get; set; }
        public long NextAttemptAt { get; set; }
    }
}
