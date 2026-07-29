using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using System.Text;
using System.Threading;

namespace CXPayMonitor
{
    /// <summary>
    /// 只截取用户明确选择的微信窗口，再交给 Windows 本地 OCR。
    /// 不注入微信、不读取进程内存、不抓取网络流量。
    /// </summary>
    public sealed class WechatUiCollector
    {
        private static readonly HashSet<string> AllowedProcesses = new HashSet<string>(
            new[] { "Weixin", "WeChat", "WeChatAppEx", "WeChatBrowser", "WeChatAppLauncher" },
            StringComparer.OrdinalIgnoreCase);

        public IList<WindowDescriptor> ListWindows()
        {
            var windows = new List<WindowDescriptor>();
            NativeMethods.EnumWindows(delegate(IntPtr handle, IntPtr parameter)
            {
                if (!NativeMethods.IsWindowVisible(handle)) return true;
                uint processId;
                NativeMethods.GetWindowThreadProcessId(handle, out processId);
                try
                {
                    Process process = Process.GetProcessById((int)processId);
                    if (!AllowedProcesses.Contains(process.ProcessName)) return true;
                    NativeMethods.Rect bounds;
                    if (!NativeMethods.GetWindowRect(handle, out bounds)) return true;
                    int width = Math.Max(0, bounds.Right - bounds.Left);
                    int height = Math.Max(0, bounds.Bottom - bounds.Top);
                    if (width < 240 || height < 240) return true;

                    string windowTitle = ReadWindowTitle(handle);
                    if (windowTitle.Length == 0)
                    {
                        if (!string.Equals(process.ProcessName, "WeChatAppEx", StringComparison.OrdinalIgnoreCase))
                            return true;
                        var className = new StringBuilder(128);
                        NativeMethods.GetClassName(handle, className, className.Capacity);
                        windowTitle = "微信小程序窗口（" + width + "×" + height + "，" + className + "）";
                    }
                    windows.Add(new WindowDescriptor
                    {
                        Handle = handle.ToInt64(),
                        ProcessId = (int)processId,
                        ProcessName = process.ProcessName,
                        Title = windowTitle
                    });
                }
                catch { }
                return true;
            }, IntPtr.Zero);
            windows.Sort(delegate(WindowDescriptor left, WindowDescriptor right)
            {
                int leftRank = IsTargetTitle(left.Title) ? 0 : 1;
                int rightRank = IsTargetTitle(right.Title) ? 0 : 1;
                int rank = leftRank.CompareTo(rightRank);
                return rank != 0 ? rank : string.Compare(left.Title, right.Title,
                    StringComparison.CurrentCultureIgnoreCase);
            });
            return windows;
        }

        public WechatSnapshot Capture(WindowDescriptor selected)
        {
            IntPtr handle = ValidateWindow(selected);
            if (NativeMethods.IsIconic(handle))
                throw new InvalidOperationException("所选微信收款记录窗口已最小化，请恢复窗口；窗口可以被其他软件覆盖");

            NativeMethods.Rect bounds;
            if (!NativeMethods.GetWindowRect(handle, out bounds))
                throw new InvalidOperationException("无法读取所选微信窗口的位置");
            int width = bounds.Right - bounds.Left;
            int height = bounds.Bottom - bounds.Top;
            if (width < 240 || height < 240 || width > 10000 || height > 10000)
                throw new InvalidOperationException("所选微信窗口尺寸异常，请恢复窗口后重试");

            using (var bitmap = new Bitmap(width, height, PixelFormat.Format32bppArgb))
            {
                bool rendered;
                using (Graphics graphics = Graphics.FromImage(bitmap))
                {
                    graphics.Clear(Color.Black);
                    IntPtr dc = graphics.GetHdc();
                    try { rendered = NativeMethods.PrintWindow(handle, dc, 2); }
                    finally { graphics.ReleaseHdc(dc); }
                    if (!rendered)
                    {
                        IntPtr foreground = NativeMethods.GetForegroundWindow();
                        if (foreground != handle && NativeMethods.GetAncestor(foreground, 2) != handle)
                            throw new InvalidOperationException("微信后台窗口捕获失败，请恢复窗口后重试");
                        graphics.CopyFromScreen(bounds.Left, bounds.Top, 0, 0,
                            new Size(width, height), CopyPixelOperation.SourceCopy);
                    }
                }
                string title = ReadWindowTitle(handle);
                if (title.Length == 0) title = selected.Title;
                return new WechatOcrCollector().Recognize(bitmap, title, DateTime.Now);
            }
        }

        public void ActivateForInspection(WindowDescriptor selected)
        {
            IntPtr handle = ValidateWindow(selected);
            if (NativeMethods.IsIconic(handle)) NativeMethods.ShowWindow(handle, 9);
            if (!NativeMethods.SetForegroundWindow(handle))
                throw new InvalidOperationException("无法将微信记录窗口切换到前台，请手动打开该窗口后再检测");
            Thread.Sleep(350);
        }

        private static IntPtr ValidateWindow(WindowDescriptor selected)
        {
            if (selected == null || selected.Handle == 0)
                throw new ArgumentException("请先选择微信收款单或小账本窗口");
            IntPtr handle = new IntPtr(selected.Handle);
            if (!NativeMethods.IsWindow(handle))
                throw new InvalidOperationException("所选微信窗口已关闭，请刷新窗口列表后重新选择");
            uint currentProcessId;
            NativeMethods.GetWindowThreadProcessId(handle, out currentProcessId);
            Process process;
            try { process = Process.GetProcessById((int)currentProcessId); }
            catch { throw new InvalidOperationException("无法读取所选窗口的进程信息"); }
            if (!AllowedProcesses.Contains(process.ProcessName))
                throw new InvalidOperationException("为防止误采集，只允许选择受支持的微信进程名窗口");
            return handle;
        }

        private static string ReadWindowTitle(IntPtr handle)
        {
            int length = NativeMethods.GetWindowTextLength(handle);
            var title = new StringBuilder(Math.Max(length + 1, 2));
            NativeMethods.GetWindowText(handle, title, title.Capacity);
            return title.ToString().Trim();
        }

        private static bool IsTargetTitle(string title)
        {
            return !string.IsNullOrEmpty(title)
                && (title.Contains("收款小账本") || title.Contains("微信收款单") || title.Contains("经营账户"));
        }

        private static class NativeMethods
        {
            internal delegate bool EnumWindowsProc(IntPtr handle, IntPtr parameter);

            [DllImport("user32.dll")]
            internal static extern bool EnumWindows(EnumWindowsProc callback, IntPtr parameter);

            [DllImport("user32.dll")]
            internal static extern bool IsWindowVisible(IntPtr handle);

            [DllImport("user32.dll")]
            internal static extern bool IsWindow(IntPtr handle);

            [DllImport("user32.dll")]
            internal static extern bool IsIconic(IntPtr handle);

            [DllImport("user32.dll")]
            internal static extern IntPtr GetForegroundWindow();

            [DllImport("user32.dll")]
            internal static extern IntPtr GetAncestor(IntPtr handle, uint flags);

            [DllImport("user32.dll")]
            internal static extern bool SetForegroundWindow(IntPtr handle);

            [DllImport("user32.dll")]
            internal static extern bool ShowWindow(IntPtr handle, int command);

            [DllImport("user32.dll")]
            internal static extern bool PrintWindow(IntPtr handle, IntPtr deviceContext, uint flags);

            [DllImport("user32.dll", CharSet = CharSet.Unicode)]
            internal static extern int GetWindowText(IntPtr handle, StringBuilder text, int maximumCount);

            [DllImport("user32.dll", CharSet = CharSet.Unicode)]
            internal static extern int GetWindowTextLength(IntPtr handle);

            [DllImport("user32.dll")]
            internal static extern uint GetWindowThreadProcessId(IntPtr handle, out uint processId);

            [DllImport("user32.dll")]
            internal static extern bool GetWindowRect(IntPtr handle, out Rect bounds);

            [DllImport("user32.dll", CharSet = CharSet.Unicode)]
            internal static extern int GetClassName(IntPtr handle, StringBuilder className, int maximumCount);

            [StructLayout(LayoutKind.Sequential)]
            internal struct Rect
            {
                public int Left;
                public int Top;
                public int Right;
                public int Bottom;
            }
        }
    }
}
