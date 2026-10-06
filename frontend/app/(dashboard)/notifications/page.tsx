"use client";

import { useCallback, useEffect, useState } from "react";
import { getNotifications, markNotificationRead, markAllNotificationsRead, type Notification } from "@/lib/api";
import { dateSmart } from "@/lib/format";
import Pagination from "@/components/Pagination";

const EVENT_LABEL: Record<Notification["event_type"], string> = {
  lesson_reminder: "تذكير بحصة", subscription_expiring: "اشتراك قارب على الانتهاء",
  subscription_expired: "اشتراك منتهي", payment_received: "دفعة مستلمة",
  makeup_created: "حصة تعويضية", trial_reminder: "تذكير بتقييم تجريبي",
};

const CHANNEL_LABEL: Record<Notification["channel"], string> = {
  in_app: "داخل التطبيق", email: "بريد إلكتروني", whatsapp: "واتساب", sms: "رسالة نصية",
};

const PAGE_SIZE = 100;

export default function NotificationsPage() {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [eventFilter, setEventFilter] = useState("");

  const loadNotifications = useCallback(async (targetPage = 1, unread = false, event = "") => {
    setLoading(true);
    setError(null);
    try {
      const r = await getNotifications({ per_page: PAGE_SIZE, page: targetPage, unread: unread || undefined });
      setNotifications(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر التحميل");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadNotifications(1, unreadOnly, eventFilter); }, [loadNotifications, unreadOnly, eventFilter]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadNotifications(target, unreadOnly, eventFilter);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleMarkRead(id: number) {
    try { await markNotificationRead(id); loadNotifications(page, unreadOnly, eventFilter); }
    catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  async function handleMarkAllRead() {
    try { await markAllNotificationsRead(); loadNotifications(1, unreadOnly, eventFilter); }
    catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  const visible = eventFilter
    ? notifications.filter((n) => n.event_type === eventFilter)
    : notifications;

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

      {/* فلاتر */}
      <div className="mb-4 flex flex-wrap gap-2">
        <label className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 cursor-pointer">
          <input
            type="checkbox"
            checked={unreadOnly}
            onChange={(e) => { setUnreadOnly(e.target.checked); setPage(1); }}
            className="rounded"
          />
          غير المقروءة فقط
        </label>
        <select
          value={eventFilter}
          onChange={(e) => { setEventFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">كل الأنواع</option>
          {Object.entries(EVENT_LABEL).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
      </div>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {!loading && !error && (
        <div className="space-y-2">
          {visible.length === 0 && <p className="text-slate-400">لا توجد إشعارات</p>}
          {visible.map((n) => (
            <div key={n.id} className={`flex items-center justify-between rounded-xl border p-4 ${n.read_at ? "border-slate-200 bg-white" : "border-blue-200 bg-blue-50"}`}>
              <div>
                <p className={`text-sm ${n.read_at ? "text-slate-600" : "font-medium text-slate-800"}`}>{EVENT_LABEL[n.event_type]}</p>
                {/* ⭐ `dateSmart` — كان السطر الطويل بالثواني */}
                <p className="text-xs text-slate-400">
                  {CHANNEL_LABEL[n.channel]}
                  {n.sent_at ? ` — ${dateSmart(n.sent_at)}` : ""}
                </p>
                {n.payload && typeof n.payload === "object" && "message" in n.payload && <p className="mt-1 text-xs text-slate-500">{String((n.payload as { message?: string }).message)}</p>}
              </div>
              {!n.read_at && (
                <button onClick={() => handleMarkRead(n.id)} className="rounded bg-blue-100 px-3 py-1 text-xs text-blue-700 hover:bg-blue-200">تعليم كمقروء</button>
              )}
            </div>
          ))}
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="إشعار"
      />
    </div>
  );
}
