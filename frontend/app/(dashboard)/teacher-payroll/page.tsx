"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  approveTeacherPayroll,
  closeTeacherPayrollPeriod,
  generateTeacherPayroll,
  getTeacherPayrollLines,
  getTeacherPayrollPeriods,
  payTeacherPayrollLine,
  reopenTeacherPayrollPeriod,
  updateTeacherPayrollLine,
  type TeacherPayrollLine,
  type TeacherPayrollLineStatus,
  type TeacherPayrollLinesResponse,
  type TeacherPayrollPeriodsResponse,
} from "@/lib/api";
import { egp, num, payrollPeriodName } from "@/lib/format";
import { useUI } from "@/components/ui";

/**
 * مرتبات المعلمين.
 *
 * ⭐ الشاشة **بتعرض** مش بتحسب. `amount` بيجي جاهز من السيرفر من
 * `teacher_earnings` (المستحق المسجّل والمراجَع). مفيش ضرب في الملف
 * ده — وده محمي باختبار في الباك.
 *
 * الفرق عن شاشة الموظفين:
 *   |           | الأرقام المهمة                |
 *   |-----------|-------------------------------|
 *   | موظف      | الساعات × سعر الساعة         |
 *   | معلم حصة  | الحصص × سعر الحصة            |
 *   | معلم شهري | الراتب الثابت                |
 *
 * دورة السطر: مسودّة → معتمد → مدفوع. المسودّة بس قابلة للتعديل.
 */

const LINE_TONE: Record<TeacherPayrollLineStatus, string> = {
  draft: "bg-amber-50 text-amber-700 ring-amber-200",
  approved: "bg-blue-50 text-blue-700 ring-blue-200",
  paid: "bg-emerald-50 text-emerald-700 ring-emerald-200",
};

const PERIOD_TONE: Record<string, string> = {
  open: "bg-amber-50 text-amber-700 ring-amber-200",
  finalized: "bg-blue-50 text-blue-700 ring-blue-200",
  paid: "bg-emerald-50 text-emerald-700 ring-emerald-200",
};

const PERIOD_LABEL: Record<string, string> = {
  open: "مفتوحة",
  finalized: "مقفولة",
  paid: "مدفوعة",
};

/** `2026-10-01T00:00:00.000000Z` → `2026-10-01` */
const isoDate = (s: string) => s.slice(0, 10);

function prettyDate(iso: string): string {
  const [y, m, d] = isoDate(iso).split("-").map(Number);
  const months = [
    "يناير", "فبراير", "مارس", "أبريل", "مايو", "يونيو",
    "يوليو", "أغسطس", "سبتمبر", "أكتوبر", "نوفمبر", "ديسمبر",
  ];
  return `${d} ${months[m - 1]} ${y}`;
}

export default function TeacherPayrollPage() {
  const [data, setData] = useState<TeacherPayrollPeriodsResponse | null>(null);
  const [periodId, setPeriodId] = useState<number | null>(null);
  const [lines, setLines] = useState<TeacherPayrollLinesResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [working, setWorking] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { toast, confirm } = useUI();

  const loadPeriods = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const r = await getTeacherPayrollPeriods();
      setData(r);
      setPeriodId((cur) => {
        if (cur && r.periods.some((p) => p.id === cur)) return cur;
        const open = r.periods.find((p) => p.status === "open");
        return open?.id ?? r.periods[0]?.id ?? null;
      });
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل الفترات");
    } finally {
      setLoading(false);
    }
  }, []);

  const loadLines = useCallback(async (id: number) => {
    try {
      setLines(await getTeacherPayrollLines(id));
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل السطور");
    }
  }, []);

  useEffect(() => { loadPeriods(); }, [loadPeriods]);
  useEffect(() => { if (periodId) loadLines(periodId); }, [periodId, loadLines]);

  const period = data?.periods.find((p) => p.id === periodId) ?? null;
  const isOpen = period?.status === "open";

  const remainingPayable = useMemo(
    () =>
      (lines?.lines ?? []).filter((l) => l.status === "approved" && l.amount > 0).length,
    [lines],
  );

  // ============================================================
  // الاحتساب والاعتماد
  // ============================================================

  async function handleGenerate() {
    if (!period) return;

    const willReplace = (lines?.lines.length ?? 0) > 0;
    const harvested = lines?.harvested_total ?? 0;

    const ok = await confirm({
      title: "احتساب مرتبات المعلمين",
      message:
        `هيتحسب المرتب من المستحق المسجّل (${egp(harvested)}) ` +
        `من ${prettyDate(period.start_date)} لـ ${prettyDate(period.end_date)}.\n\n` +
        (willReplace
          ? "• المسودّات هتتحدّث.\n• المعتمد والمدفوع مش هيتلمسوا."
          : "• المعلمين التانيين مالهمش سعر أو مفيش لهم تسجيل."),
      confirmLabel: "احسب",
    });
    if (!ok) return;

    setWorking("generate");
    try {
      const r = await generateTeacherPayroll(period.id);
      toast.success(r.message);
      await Promise.all([loadLines(period.id), loadPeriods()]);
    } catch (e) {
      toast.apiError("فشل الاحتساب", e);
    } finally {
      setWorking(null);
    }
  }

  async function handleApprove() {
    if (!period || !lines) return;

    const drafts = lines.totals.draft;
    const zeroCount = lines.lines.filter((l) => l.status === "draft" && l.amount === 0).length;
    const sum = lines.lines
      .filter((l) => l.status === "draft" && l.amount > 0)
      .reduce((a, l) => a + l.amount, 0);

    const ok = await confirm({
      title: "اعتماد المسودّات",
      message:
        `هيتعمّد ${drafts - zeroCount} سطر بإجمالي ${egp(sum)}.\n\n` +
        (zeroCount ? `• ${zeroCount} سطر راتبه 0 هيتخطّى.\n` : "") +
        "• بعد الاعتماد مش هيقدر تتعدّل.",
      confirmLabel: "اعتمد",
    });
    if (!ok) return;

    setWorking("approve");
    try {
      const r = await approveTeacherPayroll(period.id);
      toast.success(r.message);
      await Promise.all([loadLines(period.id), loadPeriods()]);
    } catch (e) {
      toast.apiError("فشل الاعتماد", e);
    } finally {
      setWorking(null);
    }
  }

  // ============================================================
  // الإقفال وإعادة الفتح — «مرحلتين»
  // ============================================================

  async function handleClose() {
    if (!period || !lines) return;

    const unpaid = lines.lines.filter((l) => l.status === "approved");
    const unpaidSum = unpaid.reduce((a, l) => a + l.amount, 0);

    const ok = await confirm({
      title: "إقفال الفترة",
      message:
        "الأرقام هتتقفل ومش هينفع تتعدّل تاني.\n\n" +
        (unpaid.length > 0
          ? `• ${unpaid.length} سطر لسه مدفوعش (${egp(unpaidSum)}) — هتقدر تصرفهم بعد الإقفال.`
          : "• كل المستحق اتصرف.") +
        "\n\nلو اتقفلت بالغلط، تقدر تفتحها تاني.",
      confirmLabel: "اقفل",
    });
    if (!ok) return;

    setWorking("close");
    try {
      const r = await closeTeacherPayrollPeriod(period.id);
      toast.success(r.message);
      await Promise.all([loadLines(period.id), loadPeriods()]);
    } catch (e) {
      toast.apiError("فشل الإقفال", e);
    } finally {
      setWorking(null);
    }
  }

  async function handleReopen() {
    if (!period) return;

    const paidCount = lines?.totals.paid ?? 0;

    const ok = await confirm({
      title: "إعادة فتح الفترة",
      message:
        "هتقدر تحسب وتعدّل تاني.\n\n" +
        (paidCount > 0
          ? `⚠️ ${paidCount} سطر مدفوع — السطور المدفوعة هتفضل مدفوعة ومش هترجع.`
          : "مفيش سطور مدفوعة في الفترة."),
      confirmLabel: "افتح",
    });
    if (!ok) return;

    setWorking("reopen");
    try {
      const r = await reopenTeacherPayrollPeriod(period.id);
      toast.success(r.message);
      await Promise.all([loadLines(period.id), loadPeriods()]);
    } catch (e) {
      toast.apiError("فشل الفتح", e);
    } finally {
      setWorking(null);
    }
  }

  // ============================================================
  // تعديل سطر / دفع
  // ============================================================

  async function patchLine(
    id: number,
    payload: Parameters<typeof updateTeacherPayrollLine>[1],
  ) {
    try {
      const r = await updateTeacherPayrollLine(id, payload);
      setLines((prev) =>
        prev ? { ...prev, lines: prev.lines.map((l) => (l.id === id ? r : l)) } : prev,
      );
      return r;
    } catch (e) {
      toast.apiError("فشل التعديل", e);
      throw e;
    }
  }

  async function handlePay(line: TeacherPayrollLine) {
    const ok = await confirm({
      title: "تسجيل دفع",
      message:
        `هتدفع ${egp(line.amount)} لـ ${line.teacher.name}؟\n\n` +
        `• ${num(line.lessons_count)} حصة` +
        (line.rate_snapshot !== null ? ` × ${egp(line.rate_snapshot)}` : "") +
        "\n• السطر مش هيقدر يتعدّل بعدها.",
      confirmLabel: "سجّل الدفع",
    });
    if (!ok) return;

    setWorking(`pay-${line.id}`);
    try {
      await payTeacherPayrollLine(line.id);
      toast.success(`اتسجّل دفع ${line.teacher.name}`);
      await Promise.all(periodId ? [loadLines(periodId), loadPeriods()] : []);
    } catch (e) {
      toast.apiError("فشل تسجيل الدفع", e);
    } finally {
      setWorking(null);
    }
  }

  // ============================================================
  // العرض
  // ============================================================

  const summary = useMemo(() => {
    if (!lines) return null;
    return [
      { label: "الحصص", value: num(lines.totals.lessons), tone: "text-slate-800" },
      { label: "إجمالي المستحق", value: `${egp(lines.totals.amount)}`, tone: "text-emerald-700" },
      { label: "مسودّات", value: String(lines.totals.draft), tone: "text-amber-700" },
      { label: "معتمد", value: String(lines.totals.approved), tone: "text-blue-700" },
      { label: "مدفوع", value: String(lines.totals.paid), tone: "text-emerald-700" },
    ];
  }, [lines]);

  // الفرق بين المحسوب والمستحق الحقيقي — لو فيه، يبقى تنبيه
  const drift = lines ? Math.abs(lines.totals.amount - lines.harvested_total) : 0;

  return (
    <div>
      {/* ===== الترويسة ===== */}
      <div className="mb-6">
        <h1 className="text-lg font-semibold text-slate-800">مرتبات المعلمين</h1>
        <p className="text-xs text-slate-500">
          الأجر = المستحق المسجّل من الحصص — محسوب وقت الاحتساب ومش بيتغيّر بعدها
        </p>
      </div>

      {error && (
        <div className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700">{error}</div>
      )}

      {/* ===== اختيار الفترة ===== */}
      <div className="mb-4 flex flex-wrap gap-2">
        {(data?.periods ?? []).map((p) => (
          <button
            key={p.id}
            onClick={() => setPeriodId(p.id)}
            className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
              p.id === periodId
                ? "bg-slate-800 text-white"
                : "border border-slate-200 bg-white text-slate-600 hover:bg-slate-50"
            }`}
          >
            {/* ⭐ `payrollPeriodName` — الاسم المخزّن كان إنجليزي */}
            {payrollPeriodName(p.start_date)}
            {/* ⭐ مسافة **بعد** الاسم — من غيرها المتصفح يلصق
                المبلغ في السنة: «أكتوبر 20269,108.35» */}
            {" "}
            <span className="opacity-70">
              {egp(p.total_amount)}
              {p.lines_count > 0 ? ` · ${p.lines_count}` : ""}
            </span>
          </button>
        ))}
        {data && data.periods.length === 0 && (
          <p className="text-sm text-slate-400">مفيش فترات مرتبات لسه</p>
        )}
      </div>

      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : !period ? (
        <p className="text-sm text-slate-400">اختر فترة</p>
      ) : (
        <>
          {/* ===== شريط الفترة ===== */}
          <div className="mb-4 rounded-xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="flex items-center gap-2">
                  <h2 className="text-sm font-semibold text-slate-800">{payrollPeriodName(period.start_date)}</h2>
                  <span
                    className={`rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ${
                      PERIOD_TONE[period.status] ?? "bg-slate-50 text-slate-600 ring-slate-200"
                    }`}
                  >
                    {PERIOD_LABEL[period.status] ?? period.status}
                  </span>
                </div>
                <p className="mt-0.5 text-xs text-slate-500">
                  {prettyDate(period.start_date)} — {prettyDate(period.end_date)}
                </p>
              </div>

              <div className="flex flex-wrap gap-2">
                {isOpen ? (
                  <>
                    <button
                      onClick={handleGenerate}
                      disabled={working !== null}
                      className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      {working === "generate" ? "جاري الاحتساب…" : "احسب من المستحق"}
                    </button>
                    <button
                      onClick={handleApprove}
                      disabled={working !== null || (lines?.totals.draft ?? 0) === 0}
                      className="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      {working === "approve" ? "جاري الاعتماد…" : "اعتمد المسودّات"}
                    </button>
                    <button
                      onClick={handleClose}
                      disabled={working !== null || (lines?.totals.draft ?? 0) > 0}
                      title={
                        (lines?.totals.draft ?? 0) > 0
                          ? "اعتمد المسودّات الأول"
                          : "اقفل الأرقام — المدفوعات هتكمّل"
                      }
                      className="rounded-lg border border-slate-800 px-3 py-1.5 text-xs font-medium text-slate-800 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      {working === "close" ? "جاري الإقفال…" : "اقفل الفترة"}
                    </button>
                  </>
                ) : (
                  <button
                    onClick={handleReopen}
                    disabled={working !== null}
                    className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 transition hover:bg-amber-100 disabled:opacity-40"
                  >
                    {working === "reopen" ? "جاري الفتح…" : "افتح الفترة"}
                  </button>
                )}
              </div>
            </div>

            {!isOpen && (
              <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                {period.status === "paid" ? (
                  <>الفترة اتقفلت وكل المستحق اتصرف.</>
                ) : (
                  <>
                    الأرقام مقفولة — مش هيتقبل احتساب ولا تعديل.{" "}
                    {remainingPayable > 0 && (
                      <span className="font-medium text-amber-700">
                        باقي {remainingPayable} سطر مدفوعش — تقدر تصرفهم دلوقتي.
                      </span>
                    )}
                  </>
                )}
              </p>
            )}

            {/* ===== الملخص ===== */}
            {summary && (
              <div className="mt-4 grid grid-cols-2 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-5">
                {summary.map((s) => (
                  <div key={s.label} className="text-center">
                    <p className="text-[11px] text-slate-400">{s.label}</p>
                    <p className={`text-sm font-bold tabular-nums ${s.tone}`}>{s.value}</p>
                  </div>
                ))}
              </div>
            )}

            {/*
              * ⚠️ تنبيه الفرق.
              *
              * ⭐ الرسالة بتقول **السبب** مش بس الرقم. أول نسخة
              * بتقول «تعديل يدوي — طبيعي» بس الفرق كان معلم موقوف
              * مش محتسب، وده **مش** طبيعي. رسالتين غلط أبعد من
              * الصمت.
              */}
            {drift > 0.01 && lines && lines.totals.lines > 0 && (
              <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                الفرق بين المحسوب ({num(lines.totals.amount)}) والمستحق المسجّل (
                {num(lines.harvested_total)}) = {num(drift)}.
                {lines.missing.length > 0
                  ? ` محسوب على ${lines.missing.length} معلم مش فيهم سطر — شوف «مستحقين مش محتسبين» تحت.`
                  : lines.totals.draft > 0
                    ? " ده تعديل يدوي في المسودّات."
                    : " بعد الاعتماد الرقم ده متقفل."}
              </p>
            )}
          </div>

          {/* ===== السطور ===== */}
          {lines && lines.lines.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-200 py-10 text-center">
              <p className="text-sm text-slate-400">مفيش سطور في الفترة دي</p>
              <p className="mt-1 text-xs text-slate-400">
                اضغط «احسب من المستحق» — هياخد المستحق المسجّل من الحصص
              </p>
            </div>
          ) : (
            <div className="overflow-x-auto rounded-xl border border-slate-200">
              <table className="w-full text-sm">
                <thead className="border-b border-slate-200 bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-start font-medium">المعلم</th>
                    <th className="px-3 py-3 text-center font-medium">
                      حصص
                      <span className="block text-[10px] font-normal text-slate-400">مكتملة</span>
                    </th>
                    <th className="px-3 py-3 text-center font-medium">سعر الحصة/الشهر</th>
                    <th className="px-3 py-3 text-center font-medium">
                      المستحق
                      <span className="block text-[10px] font-normal text-slate-400">من السيرفر</span>
                    </th>
                    <th className="px-3 py-3 text-center font-medium">الحالة</th>
                    <th className="px-3 py-3 text-center font-medium">إجراء</th>
                  </tr>
                </thead>
                <tbody>
                  {lines?.lines.map((l) => (
                    <LineRow
                      key={l.id}
                      line={l}
                      busy={working === `pay-${l.id}`}
                      onPatch={patchLine}
                      onPay={handlePay}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* ===== ناقصين: ليهم مستحق بس مالهمش سطر ===== */}
          {lines && lines.missing.length > 0 && (
            <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50/50 p-4">
              <h3 className="mb-2 text-sm font-medium text-amber-900">
                مستحقين مش محتسبين ({lines.missing.length})
              </h3>
              <p className="mb-3 text-xs text-amber-700">
                دول ليهم مستحق مسجّل بس مالهمش سطر — غالباً مفيش لهم سعر،
                أو مفيش تشطيرة. من غير سطر مفيش رقم للتدفع.
              </p>
              <div className="flex flex-wrap gap-2">
                {lines.missing.map((m) => (
                  <span
                    key={m.teacher.id}
                    className="rounded-lg bg-white px-2.5 py-1 text-xs text-slate-700 ring-1 ring-amber-200"
                  >
                    {m.teacher.name} · {egp(m.harvested)}
                  </span>
                ))}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

/**
 * سطر واحد.
 *
 * الحصص والسعر قابلين للتعديل **بس في المسودّة** — بعد الاعتماد
 * الأرقام لقطة من وقت الاعتماد.
 */
function LineRow({
  line,
  busy,
  onPatch,
  onPay,
}: {
  line: TeacherPayrollLine;
  busy: boolean;
  onPatch: (
    id: number,
    payload: Parameters<typeof updateTeacherPayrollLine>[1],
  ) => Promise<TeacherPayrollLine>;
  onPay: (line: TeacherPayrollLine) => void;
}) {
  const editable = line.status === "draft";

  return (
    <tr className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
      <td className="px-4 py-2.5">
        <p className="font-medium text-slate-800">{line.teacher.name}</p>
        <p className="text-[11px] text-slate-400">{line.teacher.job_title ?? "—"}</p>
      </td>

      <td className="px-3 py-2.5 text-center">
        {editable ? (
          <NumberField
            value={line.lessons_count}
            step={1}
            onCommit={(v) => onPatch(line.id, { lessons_count: v })}
          />
        ) : (
          <span className="text-xs tabular-nums text-slate-600">{line.lessons_count}</span>
        )}
      </td>

      <td className="px-3 py-2.5 text-center">
        {editable ? (
          <NumberField
            value={line.rate_snapshot}
            step={1}
            onCommit={(v) => onPatch(line.id, { rate_snapshot: v })}
          />
        ) : (
          <span className="text-xs tabular-nums text-slate-600">
            {line.rate_snapshot === null ? "—" : num(line.rate_snapshot)}
          </span>
        )}
      </td>

      {/* ⭐ الرقم من السيرفر — مفيش حساب هنا */}
      <td className="px-3 py-2.5 text-center">
        <span className="text-sm font-bold tabular-nums text-slate-800">
          {egp(line.amount)}
        </span>
      </td>

      <td className="px-3 py-2.5 text-center">
        <span
          className={`rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ${
            LINE_TONE[line.status] ?? "bg-slate-50 text-slate-600 ring-slate-200"
          }`}
        >
          {line.status_label}
        </span>
        {line.status === "paid" && line.payment_method && (
          <p className="mt-0.5 text-[10px] text-slate-400">{line.payment_method}</p>
        )}
      </td>

      <td className="px-3 py-2.5 text-center">
        {line.status === "approved" && line.amount > 0 ? (
          <button
            onClick={() => onPay(line)}
            disabled={busy}
            className="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
          >
            {busy ? "…" : "سجّل الدفع"}
          </button>
        ) : line.status === "draft" && line.amount === 0 ? (
          <span className="text-[10px] text-slate-400">مستحقه 0</span>
        ) : (
          <span className="text-xs text-slate-300">—</span>
        )}
      </td>
    </tr>
  );
}

/**
 * خانة رقم — بتعدّل على blur أو Enter، مش على كل ضغطة.
 *
 * السبب: كل ضغطة كانت هتعمل request على كل رقم في «١٧٫٥»
 * (١، ١٧، ١٧٫٥). الحالة `saving` بتظهر في الخانة نفسها.
 */
function NumberField({
  value,
  onCommit,
  step = 0.25,
}: {
  value: number | null;
  onCommit: (v: number | null) => unknown;
  step?: number;
}) {
  const [text, setText] = useState(value === null ? "" : String(value));
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setText(value === null ? "" : String(value));
  }, [value]);

  async function commit() {
    const trimmed = text.trim();
    const n = trimmed === "" ? null : parseFloat(trimmed);

    if (trimmed !== "" && Number.isNaN(n)) {
      setText(value === null ? "" : String(value));
      return;
    }
    if (n === (value ?? null)) return;

    setSaving(true);
    try {
      await onCommit(n);
    } finally {
      setSaving(false);
    }
  }

  return (
    <span className="relative inline-block">
      <input
        type="number"
        inputMode="decimal"
        step={step}
        min="0"
        value={text}
        placeholder="0"
        aria-busy={saving}
        onChange={(e) => setText(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === "Enter") (e.target as HTMLInputElement).blur();
          if (e.key === "Escape") {
            setText(value === null ? "" : String(value));
            (e.target as HTMLInputElement).blur();
          }
        }}
        className={`w-20 rounded-lg border px-2 py-1 text-center text-sm font-semibold tabular-nums text-slate-800 focus:outline-none ${
          saving ? "border-slate-300 opacity-60" : "border-slate-200 focus:border-slate-400"
        }`}
      />
      {saving && (
        <span className="absolute -bottom-3 left-1/2 -translate-x-1/2 text-[9px] text-slate-400">
          …
        </span>
      )}
    </span>
  );
}