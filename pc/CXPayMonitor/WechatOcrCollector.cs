using System;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Runtime.InteropServices.WindowsRuntime;
using System.Text;
using System.Threading.Tasks;
using Windows.Globalization;
using Windows.Graphics.Imaging;
using Windows.Media.Ocr;
using Windows.Storage.Streams;

namespace CXPayMonitor
{
    /// <summary>
    /// 使用 Windows 本地中文 OCR 读取微信记录页截图。图片仅在内存中处理，不写入磁盘。
    /// </summary>
    public sealed class WechatOcrCollector
    {
        private const uint PreferredScale = 3;

        public WechatSnapshot Recognize(Bitmap source, string windowTitle, DateTime capturedAt)
        {
            if (source == null) throw new ArgumentNullException("source");
            return RecognizeAsync(source, windowTitle, capturedAt).GetAwaiter().GetResult();
        }

        internal WechatSnapshot RecognizeFileForTest(string path, string windowTitle, DateTime capturedAt)
        {
            using (var bitmap = new Bitmap(path))
                return Recognize(bitmap, windowTitle, capturedAt);
        }

        private static async Task<WechatSnapshot> RecognizeAsync(Bitmap source, string windowTitle, DateTime capturedAt)
        {
            OcrEngine engine = OcrEngine.TryCreateFromLanguage(new Language("zh-CN"));
            if (engine == null)
                throw new InvalidOperationException("Windows 中文 OCR 不可用，请在系统语言设置中安装‘中文（简体）’基本键入/OCR组件");

            var snapshot = new WechatSnapshot
            {
                CapturedAt = capturedAt,
                WindowTitle = windowTitle ?? string.Empty,
                CaptureWidth = source.Width,
                CaptureHeight = source.Height,
                Items = new List<AutomationTextItem>()
            };

            using (var randomAccess = new InMemoryRandomAccessStream())
            {
                Stream output = randomAccess.AsStreamForWrite();
                try
                {
                    source.Save(output, ImageFormat.Png);
                    await output.FlushAsync();
                    randomAccess.Seek(0);
                    BitmapDecoder decoder = await BitmapDecoder.CreateAsync(randomAccess);
                    await AddPassAsync(engine, decoder, 1, snapshot);

                    uint maximum = OcrEngine.MaxImageDimension;
                    uint scale = Math.Min(PreferredScale,
                        Math.Min(maximum / Math.Max(1U, decoder.PixelWidth), maximum / Math.Max(1U, decoder.PixelHeight)));
                    if (scale > 1) await AddPassAsync(engine, decoder, scale, snapshot);
                }
                finally
                {
                    output.Dispose();
                }
            }
            return snapshot;
        }

        private static async Task AddPassAsync(OcrEngine engine, BitmapDecoder decoder, uint scale,
            WechatSnapshot snapshot)
        {
            var transform = new BitmapTransform
            {
                ScaledWidth = decoder.PixelWidth * scale,
                ScaledHeight = decoder.PixelHeight * scale,
                InterpolationMode = scale == 1 ? BitmapInterpolationMode.NearestNeighbor : BitmapInterpolationMode.Cubic
            };
            SoftwareBitmap bitmap = await decoder.GetSoftwareBitmapAsync(
                BitmapPixelFormat.Bgra8, BitmapAlphaMode.Premultiplied, transform,
                ExifOrientationMode.IgnoreExifOrientation, ColorManagementMode.DoNotColorManage);
            try
            {
                OcrResult result = await engine.RecognizeAsync(bitmap);
                for (int lineIndex = 0; lineIndex < result.Lines.Count; lineIndex++)
                {
                    OcrLine line = result.Lines[lineIndex];
                    if (line.Words.Count == 0) continue;
                    var text = new StringBuilder();
                    double left = double.MaxValue;
                    double top = double.MaxValue;
                    double right = 0;
                    double bottom = 0;
                    foreach (OcrWord word in line.Words)
                    {
                        text.Append(word.Text);
                        left = Math.Min(left, word.BoundingRect.X / scale);
                        top = Math.Min(top, word.BoundingRect.Y / scale);
                        right = Math.Max(right, (word.BoundingRect.X + word.BoundingRect.Width) / scale);
                        bottom = Math.Max(bottom, (word.BoundingRect.Y + word.BoundingRect.Height) / scale);
                    }
                    string value = NormalizeOcrText(text.ToString());
                    if (value.Length == 0) continue;
                    snapshot.Items.Add(new AutomationTextItem
                    {
                        Text = value,
                        Path = "ocr:" + scale + ":" + lineIndex,
                        ControlType = "OcrLine",
                        Left = left,
                        Top = top,
                        Width = Math.Max(0, right - left),
                        Height = Math.Max(0, bottom - top)
                    });
                }
            }
            finally
            {
                bitmap.Dispose();
            }
        }

        private static string NormalizeOcrText(string value)
        {
            return (value ?? string.Empty)
                .Replace('．', '.')
                .Replace('。', '.')
                .Replace('：', ':')
                .Replace(" ", string.Empty)
                .Trim();
        }
    }
}
