using System;
using System.Runtime.InteropServices;
using System.Threading;

namespace CXPayMonitor
{
    public static class ClipboardHelper
    {
        [DllImport("user32.dll")]
        private static extern bool OpenClipboard(IntPtr hWndNewOwner);

        [DllImport("user32.dll")]
        private static extern bool CloseClipboard();

        [DllImport("user32.dll")]
        private static extern bool EmptyClipboard();

        [DllImport("user32.dll")]
        private static extern IntPtr SetClipboardData(uint uFormat, IntPtr hMem);

        [DllImport("kernel32.dll")]
        private static extern IntPtr GlobalAlloc(uint uFlags, UIntPtr dwBytes);

        [DllImport("kernel32.dll")]
        private static extern IntPtr GlobalLock(IntPtr hMem);

        [DllImport("kernel32.dll")]
        private static extern bool GlobalUnlock(IntPtr hMem);

        private const uint CF_UNICODETEXT = 13;
        private const uint GMEM_MOVEABLE = 0x0002;

        public static bool CopyText(string text)
        {
            if (string.IsNullOrEmpty(text)) return false;

            // 1. 尝试 WinForms 原生 Clipboard (最适合 Windows 桌面)
            for (int i = 0; i < 5; i++)
            {
                try
                {
                    System.Windows.Forms.Clipboard.SetDataObject(text, true, 5, 100);
                    return true;
                }
                catch { Thread.Sleep(50); }
            }

            // 2. 尝试 Win32 原生内存级写入 (绕过一切 COM 阻塞)
            for (int i = 0; i < 5; i++)
            {
                if (OpenClipboard(IntPtr.Zero))
                {
                    try
                    {
                        EmptyClipboard();
                        byte[] bytes = System.Text.Encoding.Unicode.GetBytes(text + "\0");
                        IntPtr hGlobal = GlobalAlloc(GMEM_MOVEABLE, (UIntPtr)bytes.Length);
                        if (hGlobal != IntPtr.Zero)
                        {
                            IntPtr target = GlobalLock(hGlobal);
                            if (target != IntPtr.Zero)
                            {
                                Marshal.Copy(bytes, 0, target, bytes.Length);
                                GlobalUnlock(hGlobal);
                                SetClipboardData(CF_UNICODETEXT, hGlobal);
                                return true;
                            }
                        }
                    }
                    finally
                    {
                        CloseClipboard();
                    }
                }
                Thread.Sleep(50);
            }

            return false;
        }
    }
}
