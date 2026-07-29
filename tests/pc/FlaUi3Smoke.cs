using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.RegularExpressions;
using FlaUI.Core.AutomationElements;
using FlaUI.UIA3;

internal static class FlaUi3Smoke
{
    private static void Main()
    {
        IntPtr handle = FindWindow(new[] { "微信收款单", "收款小账本" });
        if (handle == IntPtr.Zero) throw new InvalidOperationException("未找到微信收款单或收款小账本窗口");
        using (var automation = new UIA3Automation())
        {
            var handles = new List<IntPtr> { handle };
            EnumChildWindows(handle, delegate(IntPtr childHandle, IntPtr parameter)
            {
                handles.Add(childHandle);
                return true;
            }, IntPtr.Zero);
            int totalElements = 0;
            int named = 0;
            bool page = false;
            bool money = false;
            bool paid = false;
            foreach (IntPtr candidateHandle in handles)
            {
                var className = new StringBuilder(256);
                GetClassName(candidateHandle, className, className.Capacity);
                uint processId;
                GetWindowThreadProcessId(candidateHandle, out processId);
                int handleElements = 0;
                bool handlePage = false;
                bool handleMoney = false;
                bool handlePaid = false;
                try
                {
                    AutomationElement root = automation.FromHandle(candidateHandle);
                    AutomationElement[] descendants = root.FindAllDescendants();
                    var elements = new List<AutomationElement> { root };
                    elements.AddRange(descendants);
                    handleElements = elements.Count;
                    totalElements += elements.Count;
                    foreach (AutomationElement element in elements)
                    {
                        string name;
                        try { name = element.Name ?? string.Empty; }
                        catch { continue; }
                        if (name.Length == 0) continue;
                        named++;
                        handlePage = handlePage || name.Contains("微信收款单") || name.Contains("收款小账本");
                        handleMoney = handleMoney || Regex.IsMatch(name, "[¥￥]\\s*\\d");
                        handlePaid = handlePaid || name.Contains("已支付") || name.Contains("共收款");
                    }
                }
                catch { }
                page = page || handlePage;
                money = money || handleMoney;
                paid = paid || handlePaid;
                Console.WriteLine("  HWND=" + candidateHandle.ToInt64() + "/PID=" + processId
                    + "/类=" + className + "/元素=" + handleElements + "/页面词=" + handlePage
                    + "/金额=" + handleMoney + "/已支付=" + handlePaid);
            }
            Console.WriteLine("FlaUI-UIA3-ChildHWND/句柄=" + handles.Count + "/元素=" + totalElements + "/有名称=" + named
                + "/页面词=" + page + "/金额=" + money + "/已支付=" + paid);
        }
    }

    private static IntPtr FindWindow(string[] expectedTitles)
    {
        IntPtr result = IntPtr.Zero;
        EnumWindows(delegate(IntPtr handle, IntPtr parameter)
        {
            int length = GetWindowTextLength(handle);
            if (length <= 0) return true;
            var title = new StringBuilder(length + 1);
            GetWindowText(handle, title, title.Capacity);
            if (Array.IndexOf(expectedTitles, title.ToString()) < 0) return true;
            result = handle;
            return false;
        }, IntPtr.Zero);
        return result;
    }

    private delegate bool EnumWindowsProc(IntPtr handle, IntPtr parameter);

    [DllImport("user32.dll")]
    private static extern bool EnumWindows(EnumWindowsProc callback, IntPtr parameter);

    [DllImport("user32.dll")]
    private static extern bool EnumChildWindows(IntPtr parent, EnumWindowsProc callback, IntPtr parameter);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowText(IntPtr handle, StringBuilder text, int maximumCount);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetWindowTextLength(IntPtr handle);

    [DllImport("user32.dll", CharSet = CharSet.Unicode)]
    private static extern int GetClassName(IntPtr handle, StringBuilder className, int maximumCount);

    [DllImport("user32.dll")]
    private static extern uint GetWindowThreadProcessId(IntPtr handle, out uint processId);
}
