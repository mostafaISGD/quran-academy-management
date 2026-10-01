"use client";

import { useEffect, useState } from "react";
import { getNotifications, markNotificationRead, markAllNotificationsRead, type Notification } from "@/lib/api";

const EVENT_LABEL: Record<Notification["event_type"], string> = {
  lesson_reminder: "تذكير بحصة", subscription_expiring: "اشتراك قارب على الانتهاء",
  subscription_expired: "اشتراك منتهي", payment_received: "دفعة مستلمة",
  makeup_created: "حصة تعويضية", trial_reminder: "تذكير بتقييم تجريبي",
};

const CHANNEL_LABEL: Record<Notification["channel"], string> = {
  in_app: "داخل التطبيق", email: "بريد إلكتروني", whatsapp: "واتساب", sms: "رسالة نصية",
};

export default function NotificationsPage() {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  function loadNotifications() {
    setLoading(true);
    getNotifications()
      .then((r) => setNotifications(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر التحميل"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadNotifications(); }, []);

  async function handleMarkRead(id: number) {
    try { await markNotificationRead(id); loadNotifications(); } catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  async function handleMarkAllRead() {
    try { await markAllNotificationsRead(); loadNotifications(); } catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  const unreadCount = notifications.filter((n) => !n.read_at).length;

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">الإشعارات</h1>
          {unreadCount > 0 && <p className="text-sm text-slate-500">{unreadCount} غير مقروء</p>}
        </div>
        {unreadCount > 0 && (
          <button onClick={handleMarkAllRead} className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">تعليم الكل كمقروء</button>
        )}
      </div>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {!loading && !error && (
        <div className="space-y-2">
          {notifications.length === 0 && <p className="text-slate-400">لا توجد إشعارات</p>}
          {notifications.map((n) => (
            <div key={n.id} className={`flex items-center justify-between rounded-xl border p-4 ${n.read_at ? "border-slate-200 bg-white" : "border-blue-200 bg-blue-50"}`}>
              <div>
                <p className={`text-sm ${n.read_at ? "text-slate-600" : "font-medium text-slate-800"}`}>{EVENT_LABEL[n.event_type]}</p>
                <p className="text-xs text-slate-400">{CHANNEL_LABEL[n.channel]} — {n.sent_at ? new Date(n.sent_at).toLocaleString("ar-EG") : ""}</p>
                {n.payload && typeof n.payload === "object" && "message" in n.payload && <p className="mt-1 text-xs text-slate-500">{String((n.payload as { message?: string }).message)}</p>}
              </div>
              {!n.read_at && (
                <button onClick={() => handleMarkRead(n.id)} className="rounded bg-blue-100 px-3 py-1 text-xs text-blue-700 hover:bg-blue-200">تعليم كمقروء</button>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
