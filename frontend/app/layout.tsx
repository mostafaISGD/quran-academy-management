import type { Metadata } from "next";
import localFont from "next/font/local";
import "./globals.css";

const plexArabic = localFont({
  src: [
    { path: "./fonts/IBMPlexSansArabic-Regular.ttf", weight: "400" },
    { path: "./fonts/IBMPlexSansArabic-SemiBold.ttf", weight: "600" },
    { path: "./fonts/IBMPlexSansArabic-Bold.ttf", weight: "700" },
  ],
  variable: "--font-plex-arabic",
  display: "swap",
  adjustFontFallback: false,
});

const amiriQuran = localFont({
  src: "./fonts/AmiriQuran-Regular.ttf",
  variable: "--font-amiri-quran",
  display: "swap",
  preload: false,
  adjustFontFallback: false,
});

export const metadata: Metadata = {
  title: "Quran Academy Management System",
  description: "نظام إدارة أكاديمية تحفيظ القرآن",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="ar" dir="rtl" className={`h-full antialiased ${plexArabic.variable} ${amiriQuran.variable}`}>
      <body className="min-h-full flex flex-col font-sans">{children}</body>
    </html>
  );
}
