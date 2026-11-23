"use client";

import Link from "next/link";
import { useCallback, useState } from "react";
import {
  getNotificationPreferences,
  updateNotificationPreferences,
  type NotificationPreferenceChannel,
  type NotificationPreferencesResponse,
} from "@/lib/api";
import {
  changedPreferences,
  preferenceState,
} from "@/lib/notification-preferences";
import { usePage } from "@/lib/use-page";
import { useUI } from "@/components/ui";

/**
 * ⭐⭐⭐ **PR 4.3 — تفضيلات الإشعارات (خدمة ذاتية).**
 *
 * المستخدم يوقف/يشغّل البريد/واتساب/SMS بنفسه. الصندوق داخل
 * التطبيق ليس خياراً — إشعارات حسابك تُقرأ دائماً.
 *
 * ⭐ `available=false` لا يمنع الحفظ: التفضيل يُخزَّن ويُطبَّق
 * فور تفعيل القناة من الإدارة (واتساب مثلاً مقفول افتراضياً).
 *
 * ⭐ الجلب عبر `usePage`، والحفظ يرسل **الفروق فقط** —
 * `changedPreferences` مُختبرة في Vitest منفصلة عن الـUI.
 */

const EMPTY: NotificationPreferencesResponse = { channels: [] };

export default function NotificationPreferencesPage() {
  const { toast } = useUI();
  const [draft, setDraft] = useState<Record<string, boolean> | null>(null);
  const [saving, setSaving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const fetchPreferences = useCallback(() => getNotificationPreferences(), []);

  const { data, loading, error, reload } = usePage<NotificationPreferencesResponse>(
    "notification-preferences",
    fetchPreferences,
    { initial: EMPTY },
  );

  const serverState = preferenceState(data.channels);
  // ⭐ حالة العرض = تعديلات المستخدم غير المحفوظة، أو حالة السيرفر.
  const state = draft ?? serverState;
  const changes = changedPreferences(serverState, state);

  function toggle(channel: NotificationPreferenceChannel, enabled: boolean) {
    setDraft({ ...state, [channel]: enabled });
  }

  async function handleSave() {
    if (changes.length === 0) {
      toast.info("لا تغييرات", "كل التفضيلات محفوظة بالفعل.");
      return;
    }

    setSaving(true);
    setActionError(null);

    try {
      const response = await updateNotificationPreferences(changes);
      setDraft(preferenceState(response.channels));
      reload();
      toast.success("تم حفظ التفضيلات", "سيُطبَّق اختيارك على الإشعارات القادمة.");
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "تعذّر حفظ التفضيلات");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="max-w-2xl">
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">تفضيلات الإشعارات</h1>
          <p className="mt-1 text-sm text-slate-500">
            اختر القنوات التي تصلك عليها الإشعارات. صندوق الإشعارات داخل التطبيق يبقى دائماً.
          </p>
        </div>
        <Link
          href="/notifications"
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
        >
          العودة للإشعارات
        </Link>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>}
      {actionError && (
        <p className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{actionError}</p>
      )}

      {!loading && !error && (
        <div className="space-y-3">
          {data.channels.map((channel) => {
            const enabled = state[channel.channel] ?? channel.is_enabled;

            return (
              <label
                key={channel.channel}
                className="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4"
              >
                <span>
                  <span className="block text-sm font-medium text-slate-800">{channel.label}</span>
                  <span
                    className={`mt-1 block text-xs ${channel.available ? "text-emerald-600" : "text-amber-600"}`}
                  >
                    {channel.available
                      ? "مفعّلة وجاهزة للإرسال"
                      : channel.note ?? "غير مفعّلة من إدارة الأكاديمية بعد"}
                  </span>
                </span>
                <input
                  type="checkbox"
                  checked={enabled}
                  onChange={(event) => toggle(channel.channel, event.target.checked)}
                  className="mt-1 h-5 w-5 shrink-0 rounded border-slate-300 accent-emerald-600"
                  aria-label={`تفعيل ${channel.label}`}
                />
              </label>
            );
          })}

          <div className="flex flex-wrap items-center gap-2 pt-2">
            <button
              type="button"
              onClick={handleSave}
              disabled={saving || changes.length === 0}
              className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
            >
              {saving ? "جارٍ الحفظ..." : "حفظ التفضيلات"}
            </button>

            {changes.length > 0 && (
              <button
                type="button"
                onClick={() => setDraft(null)}
                disabled={saving}
                className="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
              >
                تراجع عن التغييرات
              </button>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
