"use client";

import { useEffect, useState } from "react";
import {
  createProgramLevel,
  deleteProgramLevel,
  getProgram,
  getProgramStudents,
  getProgramTeachers,
  linkAllMissingTeachers,
  linkProgramTeacher,
  unlinkIdleTeachers,
  unlinkProgramTeacher,
  updateProgram,
  updateProgramLevel,
  type Level,
  type Program,
  type ProgramDetail,
  type ProgramStudentsResponse,
  type ProgramTeacherRow,
} from "@/lib/api";
import { useUI } from "@/components/ui";
import {
  Empty, InfoGrid, Pill, Section, Skeleton, StatCard, alpha, fmtDate, fmtMoney,
} from "./ProgramPrimitives";

/**
 * ملف البرنامج — Drawer واحد فيه كل حاجة.
 *
 * مفيش tabs: الأدمن بيفتح البرنامج ويشوف كلenzi في scroll واحد.
 *
 * حدود القسم (مهم): البرنامج بيعرّف الخدمة وبيعرض اللي مرتبط بيها.
 * جدول المعلم والحضور والدفع — دي أقسامها، مش هنا.
 */

const BILLING_LABEL: Record<string, string> = {
  monthly: "شهري",
  per_lesson: "حصة مفردة",
  custom: "مخصص",
};

const SUB_STATUS: Record<string, { label: string; tone: "green" | "amber" | "slate" }> = {
  active: { label: "نشط", tone: "green" },
  paused: { label: "موقوف", tone: "amber" },
  expired: { label: "منتهي", tone: "slate" },
  cancelled: { label: "ملغي", tone: "slate" },
};

export default function ProgramProfile({
  programId, onClose, onEdit, onChanged,
}: {
  programId: number;
  onClose: () => void;
  onEdit: (p: Program) => void;
  onChanged: () => void;
}) {
  const { toast, confirm } = useUI();

  const [detail, setDetail] = useState<ProgramDetail | null>(null);
  const [linked, setLinked] = useState<ProgramTeacherRow[]>([]);
  const [suggested, setSuggested] = useState<ProgramTeacherRow[]>([]);
  /** مسجّل بس مفيش له حصص — محتاج قرار من الأدمن */
  const [idle, setIdle] = useState<ProgramTeacherRow[]>([]);
  const [fixing, setFixing] = useState<"link" | "unlink" | null>(null);
  const [students, setStudents] = useState<ProgramStudentsResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // نماذج داخلية
  const [levelName, setLevelName] = useState("");
  const [editingLevel, setEditingLevel] = useState<Level | null>(null);
  const [savingLevel, setSavingLevel] = useState(false);

  // إعادة الجلب بعد أي تعديل — مع إبقاء التحميل ظاهر أثناء الطلب
  async function refresh() {
    try {
      const [d, t, s] = await Promise.all([
        getProgram(programId),
        getProgramTeachers(programId).catch(() => ({ linked: [], idle: [], suggested: [], health: { unlinked: 0, idle: 0, total: 0 } })),
      getProgramStudents(programId).catch(() => ({ students: [], total: 0 })),
    ]);
    setDetail(d);
    setLinked(t.linked);
    setIdle(t.idle ?? []);
    setSuggested(t.suggested);
    setStudents(s);
    setError(null);
  } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل البرنامج");
    }
  }

  // الأب بيعمل remount بـ key لما البرنامج يتغيّر، فمفيش داعي نعمل
  // setLoading(true) هنا — الـ initial state بيكفي
  useEffect(() => {
    let active = true;

    Promise.all([
      getProgram(programId),
      getProgramTeachers(programId).catch(() => ({ linked: [], idle: [], suggested: [], health: { unlinked: 0, idle: 0, total: 0 } })),
      getProgramStudents(programId).catch(() => ({ students: [], total: 0 })),
    ])
      .then(([d, t, s]) => {
        if (!active) return;
        setDetail(d);
        setLinked(t.linked);
        setIdle(t.idle ?? []);
        setSuggested(t.suggested);
        setStudents(s);
      })
      .catch((e) => {
        if (!active) return;
        setError(e instanceof Error ? e.message : "تعذر تحميل البرنامج");
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [programId]);

  const program = detail?.program;
  const stats = detail?.stats;
  const color = program?.color ?? "#64748b";

  // ============================================================
  // المستويات
  // ============================================================

  async function addLevel(e: React.FormEvent) {
    e.preventDefault();
    const name = levelName.trim();
    if (!name) return;

    setSavingLevel(true);
    try {
      await createProgramLevel(programId, { name });
      setLevelName("");
      toast.success("تمت إضافة المستوى", name);
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل الإضافة", err instanceof Error ? err.message : undefined);
    } finally {
      setSavingLevel(false);
    }
  }

  function startEditLevel(l: Level) {
    setEditingLevel(l);
    setLevelName(l.name);
  }

  async function saveLevelEdit(e: React.FormEvent) {
    e.preventDefault();
    if (!editingLevel) return;
    const name = levelName.trim();
    if (!name) return;

    setSavingLevel(true);
    try {
      await updateProgramLevel(programId, editingLevel.id, { name });
      toast.success("تم تحديث المستوى", name);
      setEditingLevel(null);
      setLevelName("");
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل التحديث", err instanceof Error ? err.message : undefined);
    } finally {
      setSavingLevel(false);
    }
  }

  async function removeLevel(l: Level) {
    const ok = await confirm({
      title: "حذف المستوى",
      message: `متأكد إنك عايز تحذف «${l.name}»؟\nالحصص المرتبطة بالمستوى دي مش هتتحذف، بس هتفقد ربطها بالمستوى.`,
      confirmLabel: "احذف المستوى",
      tone: "danger",
    });
    if (!ok) return;

    try {
      const res = await deleteProgramLevel(programId, l.id);
      toast.success("تم حذف المستوى", l.name);
      // لو في حصص فقدت ربطها — نقول الرقم بصراحة
      if (res.orphaned_lessons > 0) {
        toast.info(
          `${res.orphaned_lessons} حصة فقدت ربطها بالمستوى`,
          `${res.affected_students} طالب`,
        );
      }
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل الحذف", err instanceof Error ? err.message : undefined);
    }
  }

  function cancelLevelEdit() {
    setEditingLevel(null);
    setLevelName("");
  }

  // ============================================================
  // المعلمون
  // ============================================================

  async function attachTeacher(row: ProgramTeacherRow) {
    try {
      await linkProgramTeacher(programId, { teacher_id: row.id });
      toast.success("تمت إضافة المعلم", row.display_name);
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل الإضافة", err instanceof Error ? err.message : undefined);
    }
  }

  async function detachTeacher(row: ProgramTeacherRow) {
    try {
      await unlinkProgramTeacher(programId, row.id);
      toast.success("تم فك الربط", row.display_name);
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل فك الربط", err instanceof Error ? err.message : undefined);
    }
  }

  async function togglePrimary(row: ProgramTeacherRow) {
    try {
      await linkProgramTeacher(programId, { teacher_id: row.id, is_primary: !row.is_primary });
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل التغيير", err instanceof Error ? err.message : undefined);
    }
  }

  /**
   * إصلاح جماعي — بعد تأكيد صريح.
   *
   * إحنا بنطبّع الـ pivot على الواقع (مش العكس): لو المعلم عنده حصص
   * فعلاً في البرنامج، يبقى منطقي إنه يكون مسجّل عليه. والعكس:
   * مسجّل ومفيش حصص — ممكن يكون خلّص، فالفكّ قرار الأدمن.
   */
  async function fixAllUnlinked() {
    const ok = await confirm({
      title: "ربط المعلمين الناقصين",
      message: `فيه ${suggested.length} معلم عندهم حصص في البرنامج ومش مسجّلين عليه.\nهنضيفهم كلهم على البرنامج.`,
      confirmLabel: "اربطهم كلهم",
      tone: "primary",
    });
    if (!ok) return;

    setFixing("link");
    try {
      const res = await linkAllMissingTeachers(programId);
      toast.success("تم الربط", res.message);
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل الربط", err instanceof Error ? err.message : undefined);
    } finally {
      setFixing(null);
    }
  }

  async function fixAllIdle() {
    const ok = await confirm({
      title: "فكّ المعلمين بلا حصص",
      message: `فيه ${idle.length} معلم مسجّل على البرنامج ومفيش لهم أي حصة فيه.\nهنشيلهم من البرنامج.`,
      confirmLabel: "فكّهم",
      tone: "danger",
    });
    if (!ok) return;

    setFixing("unlink");
    try {
      const res = await unlinkIdleTeachers(programId);
      toast.success("تم الفك", res.message);
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل الفك", err instanceof Error ? err.message : undefined);
    } finally {
      setFixing(null);
    }
  }

  // ============================================================
  // الحالة
  // ============================================================

  async function toggleStatus() {
    if (!program) return;
    const next = program.status === "active" ? "inactive" : "active";

    if (next === "inactive") {
      const ok = await confirm({
        title: "إيقاف البرنامج",
        message: `متأكد إنك عايز توقف «${program.name}»؟\nالطلاب المشتركين هيفضلوا مشتركين — الإيقاف بيمنع التسجيل الجديد بس.`,
        confirmLabel: "أوقف البرنامج",
        tone: "danger",
      });
      if (!ok) return;
    }

    try {
      await updateProgram(program.id, { status: next });
      toast.success(next === "active" ? "تم تفعيل البرنامج" : "تم إيقاف البرنامج", program.name);
      await refresh();
      onChanged();
    } catch (err) {
      toast.error("فشل تغيير الحالة", err instanceof Error ? err.message : undefined);
    }
  }

  // ============================================================

  return (
    <div className="fixed inset-0 z-50 flex">
      <div className="flex-1 bg-black/50" onClick={onClose} />

      <div className="flex h-full w-full max-w-3xl flex-col bg-white shadow-2xl">
        {/* ============ الترويسة ============ */}
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
          <div className="flex min-w-0 items-start gap-3">
            {program?.image_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={program.image_url} alt={program.name} className="h-12 w-12 rounded-lg object-cover" />
            ) : (
              <div
                className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-bold"
                style={{ backgroundColor: alpha(color, 0.12), color }}
              >
                {program?.name.trim().charAt(0) ?? "؟"}
              </div>
            )}

            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-bold text-slate-800">{program?.name ?? "…"}</h2>
                {program && (
                  <Pill tone={program.status === "active" ? "green" : "red"}>
                    {program.status === "active" ? "● نشط" : "○ غير نشط"}
                  </Pill>
                )}
              </div>
              {program?.description && (
                <p className="mt-1 max-w-xl text-sm leading-relaxed text-slate-500">
                  {program.description}
                </p>
              )}
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-1.5">
            <button
              onClick={() => program && onEdit(program)}
              className="rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-700 hover:bg-blue-100"
            >
              تعديل
            </button>
            <button
              onClick={toggleStatus}
              className={`rounded-lg px-2.5 py-1.5 text-xs font-medium ${
                program?.status === "active"
                  ? "bg-amber-50 text-amber-700 hover:bg-amber-100"
                  : "bg-emerald-50 text-emerald-700 hover:bg-emerald-100"
              }`}
            >
              {program?.status === "active" ? "إيقاف" : "تفعيل"}
            </button>
            <button
              onClick={onClose}
              className="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200"
            >
              إغلاق
            </button>
          </div>
        </div>

        {/* ============ المحتوى ============ */}
        <div className="flex-1 space-y-4 overflow-y-auto bg-slate-50 p-4">
          {loading ? (
            <Skeleton rows={4} />
          ) : error ? (
            <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
              {error}
            </div>
          ) : !program || !stats ? null : (
            <>
              {/* ===== الأرقام: ملخّص ===== */}
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatCard
                  label="الطلاب" icon="👥" value={stats.students_count} tone="blue"
                  hint={stats.students.paused > 0 ? `${stats.students.active} نشط · ${stats.students.paused} موقوف` : "كلهم نشطين"}
                />
                <StatCard label="المعلمون" icon="👨‍🏫" value={stats.teachers_count} tone="green" />
                <StatCard label="الباقات" icon="🏷️" value={stats.plans_count} tone="purple" />
                <StatCard label="المستويات" icon="🪜" value={stats.levels_count} tone="amber" />
              </div>

              {/* ===== ملخص الحفظ —Program بيتكلم عن الخدمة، مش عن الجدول */}
              {stats.memorization.records > 0 && (
                <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                  <p className="text-xs text-slate-500">ملخص الحفظ في البرنامج</p>
                  <div className="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <span><b className="text-slate-800">{stats.memorization.pages}</b> <span className="text-slate-500">صفحة</span></span>
                    <span><b className="text-slate-800">{stats.memorization.ayahs}</b> <span className="text-slate-500">آية</span></span>
                    <span><b className="text-slate-800">{stats.memorization.surahs}</b> <span className="text-slate-500">سورة</span></span>
                    <span><b className="text-slate-800">{stats.memorization.students}</b> <span className="text-slate-500">طالب يحفظ</span></span>
                    <span><b className="text-slate-800">{stats.lessons_count}</b> <span className="text-slate-500">حصة إجمالاً</span></span>
                    <span><b className="text-emerald-700">{stats.lessons_upcoming}</b> <span className="text-slate-500">قادمة</span></span>
                  </div>
                </div>
              )}

              {/* ===== معلومات البرنامج ===== */}
              <Section title="معلومات البرنامج" icon="ℹ️">
                <InfoGrid
                  rows={[
                    { label: "اسم البرنامج", value: program.name },
                    { label: "المعرّف", value: program.slug, tone: "muted" },
                    { label: "الحالة", value: program.status === "active" ? "نشط" : "غير نشط", tone: program.status === "active" ? "good" : "warn" },
                    { label: "تاريخ الإنشاء", value: fmtDate(program.created_at) },
                  ]}
                />
                {program.categories && program.categories.length > 0 && (
                  <div className="mt-3 border-t border-dashed border-slate-100 pt-3">
                    <p className="mb-1.5 text-xs text-slate-500">التصنيفات</p>
                    <div className="flex flex-wrap gap-1.5">
                      {program.categories.map((c) => (
                        <span
                          key={c.id}
                          className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium"
                          style={{ backgroundColor: alpha(color, 0.1), color }}
                        >
                          {c.icon && <span>{c.icon}</span>}
                          {c.name}
                        </span>
                      ))}
                    </div>
                  </div>
                )}
              </Section>

              {/* ===== المستويات ===== */}
              <Section
                title="المستويات / المراحل" icon="🪜" count={program.levels?.length ?? 0}
                action={
                  <span className="text-[11px] text-slate-400">ترتيبهم هو ترتيب التقدّم</span>
                }
              >
                <form onSubmit={editingLevel ? saveLevelEdit : addLevel} className="mb-3 flex gap-2">
                  <input
                    value={levelName}
                    onChange={(e) => setLevelName(e.target.value)}
                    placeholder={editingLevel ? "عدّل اسم المستوى" : "اسم المستوى الجديد…"}
                    className="flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                    maxLength={150}
                  />
                  <button
                    type="submit" disabled={savingLevel || !levelName.trim()}
                    className="rounded-lg bg-slate-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-40"
                  >
                    {editingLevel ? "حفظ" : "إضافة"}
                  </button>
                  {editingLevel && (
                    <button
                      type="button" onClick={cancelLevelEdit}
                      className="rounded-lg bg-slate-100 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-200"
                    >
                      إلغاء
                    </button>
                  )}
                </form>

                {!program.levels?.length ? (
                  <Empty text="مفيش مستويات لسه" hint="ابدأ بإضافة أول مستوى عشان البرنامج يبني عليه" />
                ) : (
                  <ol className="space-y-1.5">
                    {program.levels.map((l) => (
                      <li
                        key={l.id}
                        className="flex items-center gap-2.5 rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2"
                      >
                        <span
                          className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                          style={{ backgroundColor: alpha(color, 0.14), color }}
                        >
                          {l.sort_order}
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium text-slate-800">
                            {l.name}
                            {l.status !== "active" && <span className="mr-2 text-xs text-slate-400">(معطّل)</span>}
                          </p>
                          {l.description && (
                            <p className="truncate text-xs text-slate-500">{l.description}</p>
                          )}
                        </div>
                        <code className="hidden shrink-0 text-[11px] text-slate-400 sm:block">{l.code}</code>
                        <div className="flex shrink-0 gap-1">
                          <button
                            onClick={() => startEditLevel(l)}
                            className="rounded bg-blue-50 px-2 py-1 text-xs text-blue-600 hover:bg-blue-100"
                          >
                            تعديل
                          </button>
                          <button
                            onClick={() => removeLevel(l)}
                            className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100"
                          >
                            حذف
                          </button>
                        </div>
                      </li>
                    ))}
                  </ol>
                )}
              </Section>

              {/* ===== المعلمون ===== */}
              <Section
                title="المعلمون" icon="👨‍🏫" count={linked.length + idle.length}
                action={<span className="text-[11px] text-slate-400">اللي بيلقوا البرنامج فعلياً</span>}
              >
                {!linked.length && !idle.length ? (
                  <Empty text="مفيش معلمين مسجّلين على البرنامج" hint="ممكن تضيف من الاقتراحات تحت" />
                ) : (
                  <>
                    {/* -- مسجّلين وشغالين -- */}
                    {linked.length > 0 && (
                      <ul className="space-y-1.5">
                        {linked.map((t) => (
                          <li
                            key={t.id}
                            className="flex items-center gap-2.5 rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2"
                          >
                            <div className="min-w-0 flex-1">
                              <p className="flex items-center gap-1.5 truncate text-sm font-medium text-slate-800">
                                {t.is_primary && <span className="text-amber-500" title="المعلم الأساسي">★</span>}
                                {t.display_name}
                                {t.status !== "active" && <span className="text-xs text-slate-400">(معطّل)</span>}
                              </p>
                              <p className="truncate text-xs text-slate-500">
                                {t.specialization ?? "بدون تخصص محدد"}
                                <span className="mx-1.5 text-slate-300">·</span>
                                {t.lessons_count} حصة في البرنامج
                              </p>
                            </div>
                            <button
                              onClick={() => togglePrimary(t)}
                              title={t.is_primary ? "إلغاء «أساسي»" : "تعيين كمعلم أساسي"}
                              className={`shrink-0 rounded px-2 py-1 text-xs ${
                                t.is_primary
                                  ? "bg-amber-100 text-amber-700"
                                  : "bg-slate-100 text-slate-500 hover:bg-amber-100 hover:text-amber-700"
                              }`}
                            >
                              أساسي
                            </button>
                            <button
                              onClick={() => detachTeacher(t)}
                              className="shrink-0 rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100"
                            >
                              فكّ
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}

                    {/* -- مسجّلين بس مفيش حصص: محتاج قرار -- */}
                    {idle.length > 0 && (
                      <div className="mt-3 rounded-lg border border-slate-200 bg-white p-3">
                        <div className="mb-2 flex items-center justify-between gap-2">
                          <p className="text-xs font-medium text-slate-600">
                            ⚪ مسجّلين على البرنامج ومفيش لهم حصص ({idle.length})
                          </p>
                          <button
                            onClick={fixAllIdle}
                            disabled={fixing !== null}
                            className="rounded bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-600 hover:bg-slate-200 disabled:opacity-40"
                          >
                            {fixing === "unlink" ? "…" : "فكّهم كلهم"}
                          </button>
                        </div>
                        <ul className="space-y-1.5">
                          {idle.map((t) => (
                            <li key={t.id} className="flex items-center gap-2.5">
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-sm text-slate-700">{t.display_name}</p>
                                <p className="truncate text-xs text-slate-400">
                                  {t.specialization ?? "بدون تخصص"} · 0 حصة
                                </p>
                              </div>
                              <button
                                onClick={() => detachTeacher(t)}
                                className="shrink-0 rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100"
                              >
                                فكّ
                              </button>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </>
                )}

                {/* اقتراحات: معلمين عندهم حصص في البرنامج بس مش مسجّلين.
                    ده بيخلّي البرنامج متسق مع الواقع بدل ما يفترق. */}
                {suggested.length > 0 && (
                  <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <div className="mb-2 flex items-center justify-between gap-2">
                      <p className="text-xs font-medium text-amber-800">
                        ⚠️ معلمين عندهم حصص في البرنامج ومش مسجّلين ({suggested.length})
                      </p>
                      <button
                        onClick={fixAllUnlinked}
                        disabled={fixing !== null}
                        className="shrink-0 rounded bg-amber-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-amber-700 disabled:opacity-40"
                      >
                        {fixing === "link" ? "…" : "اربطهم كلهم"}
                      </button>
                    </div>
                    <ul className="space-y-1.5">
                      {suggested.map((t) => (
                        <li key={t.id} className="flex items-center gap-2">
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm text-slate-700">{t.display_name}</p>
                            <p className="truncate text-xs text-amber-700">
                              {t.specialization ?? "بدون تخصص"} · {t.lessons_count} حصة
                            </p>
                          </div>
                          <button
                            onClick={() => attachTeacher(t)}
                            className="shrink-0 rounded bg-amber-600 px-2 py-1 text-xs font-medium text-white hover:bg-amber-700"
                          >
                            أضف للبرنامج
                          </button>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </Section>

              {/* ===== الباقات: عرض بس ===== */}
              <Section
                title="الباقات" icon="🏷️" count={program.subscription_plans?.length ?? 0}
                action={<span className="text-[11px] text-slate-400">إدارة الباقات ليها قسمها</span>}
              >
                {!program.subscription_plans?.length ? (
                  <Empty text="مفيش باقات على البرنامج" />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-slate-200 text-right text-xs text-slate-500">
                          <th className="py-2 pl-3 font-medium">الباقة</th>
                          <th className="py-2 pl-3 font-medium">النظام</th>
                          <th className="py-2 pl-3 font-medium">الحصص</th>
                          <th className="py-2 pl-3 font-medium">السعر</th>
                          <th className="py-2 font-medium">الحالة</th>
                        </tr>
                      </thead>
                      <tbody>
                        {program.subscription_plans.map((p) => (
                          <tr key={p.id} className="border-b border-slate-100 last:border-0">
                            <td className="py-2 pl-3">
                              <span className="font-medium text-slate-800">{p.name}</span>
                              {p.description && (
                                <span className="block text-xs text-slate-400">{p.description}</span>
                              )}
                            </td>
                            <td className="py-2 pl-3 text-slate-600">{BILLING_LABEL[p.billing_type] ?? p.billing_type}</td>
                            <td className="py-2 pl-3 text-slate-600">
                              {p.lessons_count ?? "—"}
                              <span className="text-xs text-slate-400"> × {p.lesson_duration_minutes}د</span>
                            </td>
                            <td className="py-2 pl-3 font-medium text-slate-800">{fmtMoney(p.price, p.currency)}</td>
                            <td className="py-2">
                              <Pill tone={p.status === "active" ? "green" : "slate"}>
                                {p.status === "active" ? "نشطة" : "متوقفة"}
                              </Pill>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Section>

              {/* ===== الطلاب: ملخّص، مش إدارة اشتراك ===== */}
              <Section
                title="الطلاب" icon="👥" count={students?.total ?? 0}
                action={
                  <span className="text-[11px] text-slate-400">
                    {students && students.total > 5
                      ? `أول ${Math.min(5, students.total)} من ${students.total}`
                      : "ملخّص من الاشتراكات"}
                  </span>
                }
              >
                {!students?.students.length ? (
                  <Empty text="مفيش طلاب مشتركين في البرنامج" hint="الطلاب بيوصلوا للبرنامج عن طريق الاشتراك" />
                ) : (
                  <>
                    <ul className="space-y-1.5">
                      {students.students.slice(0, 5).map((s) => {
                        const st = SUB_STATUS[s.subscription_status] ?? { label: s.subscription_status, tone: "slate" as const };
                        return (
                          <li
                            key={s.id}
                            className="flex items-center gap-2.5 rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2"
                          >
                            <div className="min-w-0 flex-1">
                              <p className="truncate text-sm font-medium text-slate-800">{s.full_name}</p>
                              <p className="truncate text-xs text-slate-500">
                                {s.student_code}
                                {s.teacher_name && <><span className="mx-1.5 text-slate-300">·</span>{s.teacher_name}</>}
                              </p>
                            </div>
                            <Pill tone={st.tone}>{st.label}</Pill>
                          </li>
                        );
                      })}
                    </ul>
                    {students.total > 5 && (
                      <p className="mt-3 border-t border-dashed border-slate-100 pt-2 text-center text-xs text-slate-400">
                       عرض ٥ من {students.total} — إدارة الاشتراكات من قسم «الاشتراكات»
                      </p>
                    )}
                  </>
                )}
              </Section>
            </>
          )}
        </div>
      </div>
    </div>
  );
}