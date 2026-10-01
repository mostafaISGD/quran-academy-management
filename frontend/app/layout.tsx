import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "Quran Academy Management System",
  description: "نظام إدارة أكاديمية تحفيظ القرآن",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="ar" dir="rtl" className="h-full antialiased">
      <body className="min-h-full flex flex-col font-sans">{children}</body>
    </html>
  );
}
