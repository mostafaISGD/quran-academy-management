import { describe, expect, it } from "vitest";
import { date, dateTime, time } from "../lib/format";

/**
 * ⭐⭐⭐ **PR 3.7 — العرض في منطقة المستخدم.**
 *
 * ⭐ المواعيد مخزّنة **UTC** ⇒ لازم العرض يتحوّل لمنطقة المستخدم
 * (منطقة تانية) يبقى الوقت صحيح.
 *
 * ⭐ `13:00 UTC`:
 * - في القاهرة (UTC+3) ⇒ 16:00
 * - في لندن شتاءً (UTC+0) ⇒ 13:00
 */
describe("timezone-aware formatting", () => {
  const iso = "2026-10-20T13:00:00Z";

  it("shows the time in the given zone", () => {
    expect(time(iso, "Africa/Cairo")).toContain("4:00");
    expect(time(iso, "UTC")).toContain("1:00");
  });

  it("keeps the same instant in every zone", () => {
    expect(date(iso, "Africa/Cairo")).toContain("20");
    expect(date(iso, "UTC")).toContain("20");
  });

  it("formats the date and the time together in the given zone", () => {
    const cairo = dateTime(iso, "Africa/Cairo");
    const utc = dateTime(iso, "UTC");

    expect(cairo).toContain("4:00");
    expect(utc).toContain("1:00");
  });

  it("falls back to the browser zone when no zone is given", () => {
    expect(time(iso)).toBeTruthy();
    expect(time(iso)).not.toBe("—");
  });
});
