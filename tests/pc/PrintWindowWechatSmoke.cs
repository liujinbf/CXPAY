using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;
using System.Text;
using CXPayMonitor;

internal static class PrintWindowWechatSmoke
{
    private static IntPtr target;

    private static void Main()
    {
        try { Run(); }
        catch (Exception ex)
        {
            Console.Error.WriteLine("ERROR_TYPE=" + ex.GetType().FullName);
            try { Console.Error.WriteLine("ERROR_MESSAGE=" + ex.Message); } catch { }
            Environment.Exit(1);
        }
    }

    private static void Run()
    {
        EnumWindows(delegate(IntPtr handle, IntPtr state)
        {
            var title = new StringBuilder(256);
            GetWindowText(handle, title, title.Capacity);
            if (title.ToString().Contains("收款小账本")) { target = handle; return false; }
            return true;
        }, IntPtr.Zero);
        if (target == IntPtr.Zero) throw new InvalidOperationException("未找到收款小账本窗口");
        Rect rect;
        if (!GetWindowRect(target, out rect)) throw new InvalidOperationException("无法读取窗口区域");
        using (var bitmap = new Bitmap(rect.Right - rect.Left, rect.Bottom - rect.Top, PixelFormat.Format32bppArgb))
        {
            using (Graphics graphics = Graphics.FromImage(bitmap))
            {
                IntPtr dc = graphics.GetHdc();
                try
                {
                    bool rendered = PrintWindow(target, dc, 2);
                    Console.WriteLine("PrintWindow=" + rendered);
                }
                finally { graphics.ReleaseHdc(dc); }
            }
            WechatSnapshot snapshot = new WechatOcrCollector().Recognize(bitmap, "收款小账本", DateTime.Now);
            var parser = new WechatBillParser();
            string reason;
            Console.WriteLine("OCR文本行=" + snapshot.Items.Count);
            Console.WriteLine("页面校验=" + parser.IsSupportedPage(snapshot, out reason));
            Console.WriteLine("账单候选=" + parser.Parse(snapshot).Count);
        }
    }

    private delegate bool EnumWindowsProc(IntPtr handle, IntPtr state);

    [DllImport("user32.dll")]
    private static extern bool EnumWindows(EnumWindowsProc callback, IntPtr state);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowText(IntPtr handle, StringBuilder text, int maximumCount);

    [DllImport("user32.dll")]
    private static extern bool GetWindowRect(IntPtr handle, out Rect rect);

    [DllImport("user32.dll")]
    private static extern bool PrintWindow(IntPtr handle, IntPtr dc, uint flags);

    [StructLayout(LayoutKind.Sequential)]
    private struct Rect
    {
        public int Left;
        public int Top;
        public int Right;
        public int Bottom;
    }
}
