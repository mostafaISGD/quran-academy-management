"use client";

import { useEffect, useState } from "react";
import { useUI } from "@/components/ui";

/**
 * ⭐ إعدادات التذكيرات.
 *
 * ⭐ بيخلّي الإدارة تتحكم في:
 * - وقت تشغيل أمر الاشتراكات اليومي
 * - وقت تنبيه الفواتير المتأخرة
 * - فترات التذكير (3، 7، 14 يوم)
 * - فترة احتفاظ الإشعارات
 */

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
const BTN_PRIMARY =
  "rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50";

type Settings = {
  subscription_process_time: string; // HH:mm
  overdue_notify_time: string; // HH:mm
  overdue_periods: number[]; // [3, 7, 14]
  subscription_expiry_days: number[]; // [3, 7]
  notification_retention_months: number;
  channels_enabled: {
    in_app: boolean;
    whatsapp: boolean;
    sms: boolean;
    email: boolean;
  };
};

export default function ReminderSettingsPage() {
  const { toast } = useUI();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [settings, setSettings] = useState<Settings>({
    subscription_process_time: "02:00",
    overdue_notify_time: "09:00",
    overdue_periods: [3, 7, 14],
    subscription_expiry_days: [3, 7],
    notification_retention_months: 6,
    channels_enabled: {
      in_app: true,
      whatsapp: false,
      sms: false,
      email: false,
    },
  });

  const load = async () => {
    setLoading(true);
    try {
      // TODO: GET /api/reminder-settings
      // للدلوقتي نستخدم القيم الافتراضية
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر التحميل");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    load();
  }, []);

  const handleChange = <K extends keyof Settings>(
    key: K,
    value: Settings[K],
  ) => {
    setSettings((prev) => ({ ...prev, [key]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      // TODO: PUT /api/reminder-settings
      // await updateReminderSettings(settings);
      toast.success("تم حفظ إعدادات التذكيرات");
    } catch (e) {
      toast.apiError("فشل الحفظ", e);
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4">
        {[0, 1].map((i) => (
          <div key={i} className="h-24 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>;
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">إعدادات التذكيرات</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            توقيتات وفترات التنبيهات للأهالي والإدارة
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="space-y-6">
          {/* أوقات التشغيل */}
          <SettingsSection title="أوقات التشغيل التلقائي" icon="⏰">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs text-slate-600">معالجة الاشتراكات اليومية</label>
                <input
                  type="time"
                  value={settings.subscription_process_time}
                  onChange={(e) => handleChange("subscription_process_time", e.target.value)}
                  className={FIELD}
                />
                <p className="mt-1 text-[11px] text-slate-500">تشغيل أمر تجديد الاشتراكات وإنشاء الدروس</p>
              </div>
              <div>
                <label className="mb-1 block text-xs text-slate-600">تنبيه الفواتير المتأخرة</label>
                <input
                  type="time"
                  value={settings.overdue_notify_time}
                  onChange={(e) => handleChange("overdue_notify_time", e.target.value)}
                  className={FIELD}
                />
                <p className="mt-1 text-[11px] text-slate-500">إرسال تنبيهات للفواتير المتأخرة</p>
              </div>
            </div>
          </SettingsSection>

          {/* فترات التذكير */}
          <SettingsSection title="فترات التذكير (بالأيام)" icon="📅">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs text-slate-600">تنبيه انتهاء الاشتراك قبل</label>
                <div className="flex flex-wrap gap-2">
                  {[1, 3, 5, 7, 10, 14].map((d) => (
                    <label key={d} className="inline-flex items-center gap-1 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={settings.subscription_expiry_days.includes(d)}
                        onChange={(e) =>
                          handleChange(
                            "subscription_expiry_days",
                            e.target.checked
                              ? [...settings.subscription_expiry_days, d]
                              : settings.subscription_expiry_days.filter((x) => x !== d),
                          )
                        }
                        className="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                      />
                      <span className="text-sm text-slate-700">{d} يوم</span>
                    </label>
                  ))}
                </div>
              </div>
              <div>
                <label className="mb-1 block text-xs text-slate-600">تنبيه الفواتير المتأخرة بعد</label>
                <div className="flex flex-wrap gap-2">
                  {[1, 3, 5, 7, 14, 21, 30].map((d) => (
                    <label key={d} className="inline-flex items-center gap-1 cursor-pointer">
                      <input
                        type="checkbox"
                        checked={settings.overdue_periods.includes(d)}
                        onChange={(e) =>
                          handleChange(
                            "overdue_periods",
                            e.target.checked
                              ? [...settings.overdue_periods, d]
                              : settings.overdue_periods.filter((x) => x !== d),
                          )
                        }
                        className="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                      />
                      <span className="text-sm text-slate-700">{d} يوم</span>
                    </label>
                  ))}
                </div>
              </div>
            </div>
          </SettingsSection>

          {/* احتفاظ الإشعارات */}
          <SettingsSection title="احتفاظ الإشعارات" icon="🗄️">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <label className="mb-1 block text-xs text-slate-600">فترة الاحتفاظ (شهور)</label>
                <input
                  type="number"
                  min="1"
                  max="24"
                  value={settings.notification_retention_months}
                  onChange={(e) => handleChange("notification_retention_months", Number(e.target.value) || 6)}
                  className={FIELD}
                />
                <p className="mt-1 text-[11px] text-slate-500">الإشعارات الأقدم من ده هتتعمل delete أتوماتيك</p>
              </div>
            </div>
          </SettingsSection>

          {/* قنوات الإرسال */}
          <SettingsSection title="قنوات الإرسال" icon="📢">
            <p className="mb-3 text-xs text-slate-500">
              ⚠️ واتساب/إس إم إس/إيميل محتاجة تكامل خارجي (حالياً in_app بس)
            </p>
            <div className="grid gap-3 sm:grid-cols-4">
              {[
                { key: "in_app", label: "في التطبيق", icon: "🔔" },
                { key: "whatsapp", label: "واتساب", icon: "💬" },
                { key: "sms", label: "إس إم إس", icon: "📱" },
                { key: "email", label: "إيميل", icon: "📧" },
              ].map((ch) => (
                <label key={ch.key} className="flex items-center gap-2 cursor-pointer p-3 rounded-lg border border-slate-200 hover:bg-slate-50">
                  <input
                    type="checkbox"
                    checked={settings.channels_enabled[ch.key as keyof typeof settings.channels_enabled]}
                    onChange={(e) =>
                      handleChange("channels_enabled", {
                        ...settings.channels_enabled,
                        [ch.key]: e.target.checked,
                      })
                    }
                    className="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                    disabled={ch.key !== "in_app"}
                  />
                  <span className="text-sm font-medium text-slate-700">{ch.icon} {ch.label}</span>
                  {ch.key !== "in_app" && (
                    <span className="ml-auto text-[11px] text-slate-400">قريباً</span>
                  )}
                </label>
              ))}
            </div>
          </SettingsSection>
        </div>

        <div className="mt-8 pt-6 border-t border-slate-200 flex justify-end">
          <button type="submit" className={BTN_PRIMARY} disabled={saving}>
            {saving ? "جاري الحفظ…" : "حفظ الإعدادات"}
          </button>
        </div>
      </form>
    </div>
  );
}

function SettingsSection({
  title,
  icon,
  children,
}: { title: string; icon: string; children: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-800">
        <span className="text-lg">{icon}</span>
        {title}
      </h2>
      {children}
    </div>
  );
}