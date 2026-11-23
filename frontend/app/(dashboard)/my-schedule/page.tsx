import { redirect } from "next/navigation";

/**
 * ⭐⭐ **`/my-schedule` بقى اسماً قديماً لبوابة المعلم.**
 *
 * ⭐ في PR 4.4 اتنقل بيت المعلم من `/my-schedule` إلى `/teacher`
 * (اليوم + الحضور + الحفظ + المستحقات + التوفر في شاشة واحدة).
 *
 * ⚠️ الصفحة القديمة كانت بتستدعي مسارات إدارية
 * (`/teachers/{id}/availability`) — وده بالظبط اللي البوابة
 * بتحلّه. فبدل ما نمسح الرابط ونكسر bookmarks، بنحوّله.
 */
export default function MySchedulePage() {
  redirect("/teacher");
}
