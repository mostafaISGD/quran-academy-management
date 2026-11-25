import { describe, expect, it } from "vitest";
import {
  changedPreferences,
  preferenceState,
} from "@/lib/notification-preferences";

/**
 * ⭐ PR 4.3 — فروق التفضيلات.
 *
 * الهدف: لا نرسل إلا ما تغيّر فعلاً، ولا نرسل قناة غير معروفة
 * للسيرفر. هذا ما يجعل زر «حفظ» غير مزعج ولا يكتب صفوفاً بلا داعٍ.
 */
describe("notification preferences", () => {
  it("يبني الحالة من قنوات السيرفر", () => {
    expect(
      preferenceState([
        { channel: "email", is_enabled: true },
        { channel: "whatsapp", is_enabled: false },
      ]),
    ).toEqual({ email: true, whatsapp: false });
  });

  it("يرجّع الفروق فقط", () => {
    expect(
      changedPreferences(
        { email: true, whatsapp: true, sms: false },
        { email: false, whatsapp: true, sms: false },
      ),
    ).toEqual([{ channel: "email", is_enabled: false }]);
  });

  it("يتجاهل قناة غير موجودة في حالة السيرفر", () => {
    expect(
      changedPreferences({ email: true }, { email: true, telepathy: false }),
    ).toEqual([]);
  });

  it("لا يرجّع شيئاً حين لا تغيير", () => {
    expect(
      changedPreferences(
        { email: true, whatsapp: false },
        { email: true, whatsapp: false },
      ),
    ).toEqual([]);
  });
});
