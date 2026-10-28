import type {
  NotificationPreference,
  NotificationPreferenceChannel,
} from "@/lib/api";

/**
 * ⭐⭐ **PR 4.3 — منطق تفضيلات الإشعارات، خارج الـcomponent.**
 *
 * الـstate في الصفحة خريطة `channel → enabled`. السيرفر لا يحتاج
 * إلا **الفروق**، لا القائمة كاملة — فلا نرسل تغييرات وهمية.
 *
 * ⭐ الدوال هنا خالية من React وNext، فتُختبر في Vitest (بيئة
 * node) بلا render — نفس نمط `route-access` و`nav-filter`.
 */
export type PreferenceState = Record<string, boolean>;

export function preferenceState(
  channels: Pick<NotificationPreference, "channel" | "is_enabled">[],
): PreferenceState {
  return Object.fromEntries(
    channels.map((channel) => [channel.channel, channel.is_enabled]),
  );
}

/**
 * الفروق فقط بين حالة السيرفر وحالة المستخدم.
 * القناة غير الموجودة في الأصل تُتجاهل — لا نرسل مفاتيح غريبة.
 */
export function changedPreferences(
  original: Readonly<PreferenceState>,
  current: Readonly<PreferenceState>,
): Array<{ channel: NotificationPreferenceChannel; is_enabled: boolean }> {
  return Object.entries(current)
    .filter(([channel, enabled]) => channel in original && original[channel] !== enabled)
    .map(([channel, enabled]) => ({
      channel: channel as NotificationPreferenceChannel,
      is_enabled: enabled,
    }));
}
