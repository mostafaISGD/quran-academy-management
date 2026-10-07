"use client";

import { useEffect, useState, useMemo, useCallback, memo } from "react";
import {
  getTeachers, createTeacher, updateTeacher, deleteTeacher,
  getTeacherOverview, getTeacherStudents, getTeacherLessonsSummary, getTeacherFinancialSummary,
  getTeacherAvailability, createScheduleBlock, updateScheduleBlock, deleteScheduleBlock,
  WEEKDAY_LABELS, SCHEDULE_KIND_LABEL,
  type Teacher, type TeacherOverview, type TeacherStudent, type TeacherLessonsSummary,
  type TeacherFinancialSummary, type Lesson,
  type TeacherScheduleBlock, type ScheduleKind,
} from "@/lib/api";
import { date, dateSmart, money, num } from "@/lib/format";
import Pagination from "@/components/Pagination";
import { useUI, Modal, IconTrash, IconPencil, IconCheck, IconAlert } from "@/components/ui";

// ---------- ثوابت العرض ----------

const STATUS_LABEL: Record<Teacher["status"], string> = {
  active: "نشط",
  inactive: "غير نشط",
};

const STATUS_COLORS: Record<Teacher["status"], string> = {
  active: "bg-green-100 text-green-700",
  inactive: "bg-slate-100 text-slate-500",
};

const CONTRACT_LABEL: Record<string, string> = {
  per_lesson: "لكل حصة",
  monthly: "شهري",
};

const RATE_LABEL: Record<string, string> = {
  per_lesson: "سعر الحصة",
  monthly: "الراتب الشهري",
};

const LESSON_STATUS_LABEL: Record<string, string> = {
  scheduled: "مجدولة",
  confirmed: "مؤكدة",
  in_progress: "جارية",
  completed: "مكتملة",
  cancelled: "ملغاة",
  student_absent: " الطالب غائب",
  teacher_absent: "المعلم غائب",
  technical_issue: "مشكلة تقنية",
  rescheduled: "مؤجلة",
};

const LESSON_STATUS_COLORS: Record<string, string> = {
  completed: "bg-green-100 text-green-700",
  scheduled: "bg-blue-100 text-blue-700",
  confirmed: "bg-blue-100 text-blue-700",
  in_progress: "bg-amber-100 text-amber-700",
  cancelled: "bg-red-100 text-red-600",
  student_absent: "bg-orange-100 text-orange-700",
  teacher_absent: "bg-orange-100 text-orange-700",
  technical_issue: "bg-purple-100 text-purple-700",
  rescheduled: "bg-slate-100 text-slate-600",
};

const LESSON_TYPE_LABEL: Record<string, string> = {
  regular: "عادية",
  trial: "تجريبية",
  makeup: "تعويضية",
  extra: "إضافية",
  free: "مجاناً",
  assessment: "اختبار",
};

const COUNTRY_FLAGS: Record<string, string> = {
  "+20": "🇪🇬", "+966": "🇸🇦", "+971": "🇦🇪", "+965": "🇰🇼", "+974": "🇶🇦",
  "+962": "🇯🇴", "+218": "🇱🇾", "+212": "🇲🇦",
};

const SORT_OPTIONS = [
  { value: "created_at", label: "الأحدث إضافة" },
  { value: "display_name", label: "الاسم (أ-ي)" },
  { value: "lessons_count", label: "الأكثر حصصاً" },
  { value: "students_count", label: "الأكثر طلاباً" },
  { value: "ratings_avg_rating", label: "الأعلى تقييماً" },
  { value: "joined_at", label: "تاريخ الانضمام" },
];

// ---------- أدوات مساعدة ----------

/**
 * ⭐ `fmtDate` و `fmtDateTime` و `fmtMoney` اتشالوا.
 *
 * كان فيهم **٣ نسخ** من التنسيق مكتوبة يدوي هنا. وكل واحدة
 * بتعمل حاجة مختلفة عن التانية (`: "ar-EG"` مش `-u-nu-arab`،
 * و`fmtDateTime` من غير `hour12` صريح).
 *
 * فبقى في مكان واحد — `lib/format`.
 */
const fmtDate = date;
const fmtDateTime = dateSmart;

/** ⭐ alias — `money` من `lib/format` (عملة + «ج.م» + أرقام لاتينية) */
const fmtMoney = money;

function fullPhone(teacher: Pick<Teacher, "phone" | "country_code">): string {
  return `${teacher.country_code ?? ""} ${teacher.phone}`.trim();
}

// ---------- صورة المعلم ----------

const TeacherAvatar = memo(function TeacherAvatar({
  teacher, size = "md",
}: {
  teacher: Pick<Teacher, "full_name" | "avatar_url">;
  size?: "sm" | "md" | "lg";
}) {
  const dims = size === "lg" ? "h-20 w-20 text-2xl" : size === "sm" ? "h-8 w-8 text-xs" : "h-11 w-11 text-base";

  if (teacher.avatar_url) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img src={teacher.avatar_url} alt={teacher.full_name}
        className={`${dims} shrink-0 rounded-full object-cover ring-2 ring-slate-100`} />
    );
  }

  const colors = [
    "bg-blue-100 text-blue-700", "bg-emerald-100 text-emerald-700",
    "bg-purple-100 text-purple-700", "bg-amber-100 text-amber-700",
    "bg-rose-100 text-rose-700", "bg-teal-100 text-teal-700",
  ];
  const hash = teacher.full_name.split("").reduce((a, c) => a + c.charCodeAt(0), 0);

  return (
    <div className={`${dims} flex shrink-0 items-center justify-center rounded-full font-bold ${colors[hash % colors.length]}`}>
      {teacher.full_name.trim().charAt(0) || "؟"}
    </div>
  );
});

// ---------- بطاقة إحصائية ----------

function StatCard({ label, value, tone = "slate", hint }: {
  label: string; value: string | number; tone?: "slate" | "blue" | "green" | "amber" | "purple"; hint?: string;
}) {
  const tones = {
    slate: "bg-slate-800 text-white",
    blue: "bg-blue-50 text-blue-800",
    green: "bg-green-50 text-green-800",
    amber: "bg-amber-50 text-amber-800",
    purple: "bg-purple-50 text-purple-800",
  } as const;

  return (
    <div className={`rounded-xl p-4 ${tones[tone]}`}>
      <p className={`text-xs ${tone === "slate" ? "text-slate-300" : "opacity-70"}`}>{label}</p>
      <p className="mt-1 text-xl font-bold">{value}</p>
      {hint && <p className={`mt-0.5 text-[11px] ${tone === "slate" ? "text-slate-400" : "opacity-60"}`}>{hint}</p>}
    </div>
  );
}

// ---------- نجوم التقييم ----------

function Stars({ value, size = "sm" }: { value: number; size?: "sm" | "lg" }) {
  const cls = size === "lg" ? "text-2xl" : "text-sm";
  return (
    <span className={`${cls} tracking-tight`} aria-label={`${value} من 5`}>
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} className={i <= Math.round(value) ? "text-amber-400" : "text-slate-200"}>★</span>
      ))}
    </span>
  );
}

// ============================================================
// تبويب: نظرة عامة
// ============================================================

function OverviewTab({ teacherId }: { teacherId: number }) {
  const [data, setData] = useState<TeacherOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    getTeacherOverview(teacherId)
      .then((r) => { if (active) setData(r); })
      .catch((e) => { if (active) setError(e instanceof Error ? e.message : "تعذر التحميل"); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [teacherId]);

  if (loading) return <TabSkeleton />;
  if (error) return <p className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>;
  if (!data) return null;

  const t = data.basic;
  const s = data.stats;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="الطلاب" value={s.students_count} tone="blue" />
        <StatCard label="الحصص الكلي" value={s.lessons_count} tone="slate" hint={`${s.lessons_this_month} هذا الشهر`} />
        <StatCard label="حصص قادمة" value={s.upcoming_lessons} tone="green" />
        <StatCard
          label="التقييم"
          value={s.average_rating ? `${s.average_rating} / 5` : "—"}
          tone="amber"
          hint={s.ratings_count ? `${s.ratings_count} تقييم` : "لا يوجد تقييمات"}
        />
      </div>

      <Section title="البيانات الأساسية" icon="👤">
        <InfoGrid rows={[
          { label: "الكود", value: t.teacher_code },
          { label: "الهاتف", value: fullPhone(t) },
          { label: "البريد", value: t.email ?? "—" },
          { label: "الفرع", value: t.branch?.name ?? "—" },
          { label: "الدولة", value: t.country_code ? `${COUNTRY_FLAGS[t.country_code] ?? ""} ${t.country_code}` : "—" },
          { label: "تاريخ الانضمام", value: fmtDate(t.joined_at) },
        ]} />
      </Section>

      <Section title="البيانات المهنية" icon="💼">
        <InfoGrid rows={[
          { label: "التخصص", value: t.specialization ?? "—" },
          { label: "المؤهلات", value: t.qualifications ?? "—" },
          { label: "سنوات الخبرة", value: t.years_of_experience !== null ? `${t.years_of_experience} سنة` : "—" },
          { label: "اللغات", value: t.languages ?? "—" },
        ]} />
        {t.bio && (
          <p className="mt-3 whitespace-pre-line rounded-lg bg-slate-50 p-3 text-sm leading-relaxed text-slate-600">{t.bio}</p>
        )}
      </Section>

      <Section title="البرامج التي يدرّسها" icon="📚">
        {data.programs.length === 0 ? (
          <Empty text="لا توجد برامج مرتبطة" />
        ) : (
          <div className="space-y-2">
            {data.programs.map((p) => (
              <ProgramRow key={p.id} name={p.name} lessonsCount={p.lessons_count} />
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

// ---------- عناصر مساعدة للتبويبات ----------

function Section({ title, icon, children, action }: {
  title: string; icon: string; children: React.ReactNode; action?: React.ReactNode;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white">
      <header className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
        <h3 className="text-sm font-semibold text-slate-800"><span className="ml-1.5">{icon}</span>{title}</h3>
        {action}
      </header>
      <div className="p-4">{children}</div>
    </section>
  );
}

function InfoGrid({ rows }: { rows: { label: string; value: string }[] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2.5 sm:grid-cols-2">
      {rows.map((r) => (
        <div key={r.label} className="flex items-baseline justify-between gap-3 border-b border-dashed border-slate-100 pb-2 last:border-0 sm:last:border-b sm:[&:nth-last-child(-n+2)]:border-0">
          <dt className="shrink-0 text-xs text-slate-500">{r.label}</dt>
          <dd className="truncate text-sm font-medium text-slate-800">{r.value}</dd>
        </div>
      ))}
    </dl>
  );
}

function ProgramRow({ name, lessonsCount }: { name: string; lessonsCount: number }) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5">
      <span className="text-sm font-medium text-slate-700">{name}</span>
      <span className="rounded-full bg-white px-2.5 py-0.5 text-xs font-medium text-slate-600">{lessonsCount} حصة</span>
    </div>
  );
}

function TabSkeleton() {
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {[0, 1, 2, 3].map((i) => <div key={i} className="h-20 animate-pulse rounded-xl bg-slate-100" />)}
      </div>
      <div className="h-40 animate-pulse rounded-xl bg-slate-100" />
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-200 py-6 text-center">
      <p className="text-sm text-slate-400">{text}</p>
    </div>
  );
}

// ============================================================
// تبويب: الطلاب
// ============================================================

function StudentsTab({ teacherId }: { teacherId: number }) {
  const [students, setStudents] = useState<TeacherStudent[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  useEffect(() => {
    let active = true;
    setLoading(true);
    getTeacherStudents(teacherId)
      .then((r) => { if (active) setStudents(r.students); })
      .catch((e) => { if (active) setError(e instanceof Error ? e.message : "تعذر التحميل"); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [teacherId]);

  const filtered = useMemo(() => {
    if (!students) return [];
    const q = search.trim().toLowerCase();
    if (!q) return students;
    return students.filter(
      (s) => s.full_name.toLowerCase().includes(q) || s.student_code.toLowerCase().includes(q),
    );
  }, [students, search]);

  if (loading) return <TabSkeleton />;
  if (error) return <p className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="🔎 ابحث بالاسم أو الكود..."
          className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
          {students?.length ?? 0} طالب
        </span>
      </div>

      {filtered.length === 0 ? (
        <Empty text={search ? "مفيش نتائج للبحث" : "لا يوجد طلاب مرتبطون بهذا المعلم"} />
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-3 py-2.5 text-start font-medium">الطالب</th>
                <th className="px-2 py-2.5 text-center font-medium">الحصص</th>
                <th className="px-2 py-2.5 text-center font-medium">مكتملة</th>
                <th className="px-2 py-2.5 text-center font-medium">قادمة</th>
                <th className="hidden px-3 py-2.5 text-start font-medium sm:table-cell">آخر حصة</th>
              </tr>
            </thead>
            <tbody>
              {filtered.map((s) => (
                <tr key={s.id} className="border-t border-slate-100 hover:bg-slate-50">
                  <td className="px-3 py-2.5">
                    <p className="font-medium text-slate-800">{s.full_name}</p>
                    <p className="text-xs text-slate-400">{s.student_code}</p>
                  </td>
                  <td className="px-2 py-2.5 text-center text-slate-700">{s.teacher_stats.total}</td>
                  <td className="px-2 py-2.5 text-center text-green-700">{s.teacher_stats.completed}</td>
                  <td className="px-2 py-2.5 text-center text-blue-700">{s.teacher_stats.upcoming}</td>
                  <td className="hidden px-3 py-2.5 text-xs text-slate-500 sm:table-cell">
                    {s.teacher_stats.last_lesson_at ? fmtDate(s.teacher_stats.last_lesson_at) : "—"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// ============================================================
// تبويب: الحصص
// ============================================================

function LessonRow({ lesson, isPast }: { lesson: Lesson; isPast?: boolean }) {
  return (
    <div className={`flex items-center justify-between gap-3 border-b border-slate-100 py-2.5 last:border-0 ${isPast ? "opacity-70" : ""}`}>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-slate-800">{lesson.student?.full_name ?? "طالب"}</p>
        <p className="truncate text-xs text-slate-500">
          {lesson.program?.name ?? "—"}
          {lesson.level?.name ? ` — ${lesson.level.name}` : ""}
          {` • ${LESSON_TYPE_LABEL[lesson.lesson_type] ?? lesson.lesson_type}`}
        </p>
      </div>
      {/* ⭐ `text-start` — التاريخ والوقت فيه أرقام، والمفروض
          الأرقام تفضل على يمين الكلام العربي */}
      <div className="shrink-0 text-start">
        <p className="text-xs font-medium text-slate-700">{fmtDateTime(lesson.scheduled_start_at)}</p>
        <span className={`mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] ${LESSON_STATUS_COLORS[lesson.status] ?? "bg-slate-100 text-slate-600"}`}>
          {LESSON_STATUS_LABEL[lesson.status] ?? lesson.status}
        </span>
      </div>
    </div>
  );
}

function LessonsTab({ teacherId }: { teacherId: number }) {
  const [data, setData] = useState<TeacherLessonsSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    getTeacherLessonsSummary(teacherId)
      .then((r) => { if (active) setData(r); })
      .catch((e) => { if (active) setError(e instanceof Error ? e.message : "تعذر التحميل"); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [teacherId]);

  if (loading) return <TabSkeleton />;
  if (error) return <p className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>;
  if (!data) return null;

  const { summary: s } = data;
  const completionRate = s.total ? Math.round((s.completed / s.total) * 100) : 0;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="إجمالي الحصص" value={s.total} tone="slate" />
        <StatCard label="مكتملة" value={s.completed} tone="green" hint={`${completionRate}% من الإجمالي`} />
        <StatCard label="قادمة" value={s.upcoming} tone="blue" />
        <StatCard label="هذا الشهر" value={s.this_month} tone="purple" hint={`${s.this_month_completed} مكتملة`} />
      </div>

      <Section
        title="الحصص القادمة"
        icon="🗓️"
        action={<span className="text-xs text-slate-400">أقرب {data.upcoming_lessons.length}</span>}
      >
        {data.upcoming_lessons.length === 0
          ? <Empty text="لا توجد حصص قادمة مجدولة" />
          : data.upcoming_lessons.map((l) => <LessonRow key={l.id} lesson={l} />)}
      </Section>

      <Section
        title="آخر الحصص المكتملة"
        icon="✅"
        action={<span className="text-xs text-slate-400">آخر {data.recent_lessons.length}</span>}
      >
        {data.recent_lessons.length === 0
          ? <Empty text="لا توجد حصص مكتملة بعد" />
          : data.recent_lessons.map((l) => <LessonRow key={l.id} lesson={l} isPast />)}
      </Section>

      <Section title="التوزيع حسب البرنامج" icon="📊">
        {data.by_program.length === 0 ? (
          <Empty text="لا توجد بيانات" />
        ) : (
          <div className="space-y-3">
            {data.by_program.map((p) => {
              const pct = s.total ? Math.round((p.lessons_count / s.total) * 100) : 0;
              return (
                <div key={p.name}>
                  <div className="mb-1 flex items-center justify-between text-xs">
                    <span className="font-medium text-slate-700">{p.name}</span>
                    <span className="text-slate-500">{p.lessons_count} حصة — {pct}%</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full rounded-full bg-slate-700 transition-all" style={{ width: `${pct}%` }} />
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================
// تبويب: جدول commitments المعلم
// ============================================================

const KIND_TONE: Record<string, string> = {
  academy: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  external: "bg-violet-50 text-violet-800 ring-violet-200",
  leave: "bg-amber-50 text-amber-800 ring-amber-200",
  personal: "bg-slate-100 text-slate-700 ring-slate-200",
};

const KIND_DOT: Record<string, string> = {
  academy: "bg-emerald-500",
  external: "bg-violet-500",
  leave: "bg-amber-500",
  personal: "bg-slate-400",
};

const DAY_ORDER = [0, 1, 2, 3, 4, 5, 6];

function ScheduleTab({ teacherId }: { teacherId: number }) {
  const { toast, confirm } = useUI();
  const [blocks, setBlocks] = useState<TeacherScheduleBlock[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<TeacherScheduleBlock | null>(null);
  const [openForm, setOpenForm] = useState(false);
  const [saving, setSaving] = useState(false);
  const [draft, setDraft] = useState({
    weekday: 0,
    starts_at: "16:00",
    ends_at: "18:00",
    kind: "academy" as ScheduleKind,
    title: "",
  });

  const load = useCallback(() => {
    setLoading(true);
    setError(null);
    getTeacherAvailability(teacherId)
      .then((r) => setBlocks(r.blocks))
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر التحميل"))
      .finally(() => setLoading(false));
  }, [teacherId]);

  useEffect(() => { load(); }, [load]);

  const byDay = useMemo(() => {
    const map = new Map<number, TeacherScheduleBlock[]>(DAY_ORDER.map((d) => [d, []]));
    for (const b of blocks) map.get(b.weekday)?.push(b);
    for (const [, list] of map) list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    return map;
  }, [blocks]);

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        weekday: draft.weekday,
        starts_at: draft.starts_at,
        ends_at: draft.ends_at,
        kind: draft.kind,
        title: draft.title.trim() || undefined,
      };
      if (editing) {
        await updateScheduleBlock(teacherId, editing.id, payload);
        toast.success("تم تعديل الفترة", `${WEEKDAY_LABELS[draft.weekday]} ${draft.starts_at} - ${draft.ends_at}`);
      } else {
        await createScheduleBlock(teacherId, payload);
        toast.success("تمت إضافة الفترة", `${WEEKDAY_LABELS[draft.weekday]} ${draft.starts_at} - ${draft.ends_at}`);
      }
      setOpenForm(false);
      setEditing(null);
      load();
    } catch (err) {
      toast.error("فشل الحفظ", err instanceof Error ? err.message : undefined);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(b: TeacherScheduleBlock) {
    const ok = await confirm({
      title: "حذف الفترة",
      message: `متأكد إنك عايز تحذف «${WEEKDAY_LABELS[b.weekday]} من ${b.starts_at} إلى ${b.ends_at}»؟\n\n${b.kind === "leave" ? "الإجازة هتتشال من الجدول." : "الوقت ده هيبقى متاح تاني في الحجز."}`,
      confirmLabel: "احذف",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;
    try {
      await deleteScheduleBlock(teacherId, b.id);
      toast.success("تم حذف الفترة");
      load();
    } catch (err) {
      toast.error("فشل الحذف", err instanceof Error ? err.message : undefined);
    }
  }

  if (loading) return <TabSkeleton />;
  if (error) return <p className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>;

  return (
    <div className="space-y-4">
      {/* تنبيه الجدول الفاضي */}
      {blocks.length === 0 ? (
        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
            <IconAlert size={16} />
          </span>
          <div>
            <p className="text-sm font-semibold text-amber-900">جدول المعلم فاضي</p>
            <p className="mt-0.5 text-sm leading-relaxed text-amber-800">
              لازم يسجّل مواعيده (الأكاديمية والشغل الخارجي) قبل ما يتبعت له طلاب
              جدد. أي وقت مش مسجّل بيتحسب فاضي.
            </p>
          </div>
        </div>
      ) : (
        <div className="flex flex-wrap items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600">
          <span className="font-medium">{blocks.length} موعد مسجّل</span>
          <span className="text-slate-300">·</span>
          {(["academy", "external", "leave", "personal"] as const).map((k) => {
            const n = blocks.filter((b) => b.kind === k).length;
            if (n === 0) return null;
            return (
              <span key={k} className="inline-flex items-center gap-1">
                <span className={`size-2 rounded-full ${KIND_DOT[k]}`} />
                {SCHEDULE_KIND_LABEL[k]} {num(n, 0)}
              </span>
            );
          })}
        </div>
      )}

      {/* الجدول الأسبوعي */}
      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <div className="grid min-w-[760px] grid-cols-7 divide-x divide-x-reverse divide-slate-100">
          {DAY_ORDER.map((day) => {
            const list = byDay.get(day) ?? [];
            return (
              <div key={day} className="flex min-h-[200px] flex-col">
                <div className="border-b border-slate-100 bg-slate-50 px-2 py-2 text-center">
                  <p className={`text-xs font-semibold ${list.length ? "text-slate-800" : "text-slate-400"}`}>
                    {WEEKDAY_LABELS[day]}
                  </p>
                  <button
                    onClick={() => {
                      setEditing(null);
                      setDraft({ weekday: day, starts_at: "16:00", ends_at: "18:00", kind: "academy", title: "" });
                      setOpenForm(true);
                    }}
                    className="mt-0.5 rounded px-1 py-0.5 text-[11px] text-slate-400 transition hover:bg-slate-200 hover:text-slate-700"
                  >
                    + إضافة
                  </button>
                </div>

                <div className="flex-1 space-y-1.5 p-1.5">
                  {list.length === 0 ? (
                    <p className="py-6 text-center text-[11px] text-slate-300">فاضي</p>
                  ) : (
                    list.map((b) => (
                      <div key={b.id} className={`group rounded-lg px-2 py-1.5 ring-1 ${KIND_TONE[b.kind]}`}>
                        <div className="flex items-start justify-between gap-1">
                          <span dir="ltr" className="text-[11px] font-semibold tabular-nums">
                            {b.starts_at.slice(0, 5)}–{b.ends_at.slice(0, 5)}
                          </span>
                          <div className="flex shrink-0 gap-0.5 opacity-0 transition group-hover:opacity-100">
                            <button
                              onClick={() => {
                                setEditing(b);
                                setDraft({
                                  weekday: b.weekday,
                                  starts_at: b.starts_at.slice(0, 5),
                                  ends_at: b.ends_at.slice(0, 5),
                                  kind: b.kind,
                                  title: b.title ?? "",
                                });
                                setOpenForm(true);
                              }}
                              aria-label="تعديل"
                              className="rounded p-0.5 hover:bg-white/60"
                            >
                              <IconPencil size={11} />
                            </button>
                            <button
                              onClick={() => handleDelete(b)}
                              aria-label="حذف"
                              className="rounded p-0.5 text-red-600 hover:bg-white/60"
                            >
                              <IconTrash size={11} />
                            </button>
                          </div>
                        </div>
                        <p className="mt-0.5 truncate text-[11px] font-medium">
                          {SCHEDULE_KIND_LABEL[b.kind]}
                          {b.title ? ` · ${b.title}` : ""}
                        </p>
                      </div>
                    ))
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* نموذج */}
      {openForm && (
        <Modal
          open
          onClose={() => { setOpenForm(false); setEditing(null); }}
          title={editing ? "تعديل الفترة" : "إضافة وقت مشغول"}
          icon={<IconPencil size={16} />}
          footer={
            <>
              <button
                type="button"
                onClick={() => { setOpenForm(false); setEditing(null); }}
                className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
              >
                إلغاء
              </button>
              <button
                type="submit"
                form="teacher-block-form"
                disabled={saving}
                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-60"
              >
                <IconCheck size={14} />
                {saving ? "جارٍ الحفظ..." : "حفظ"}
              </button>
            </>
          }
        >
          <form id="teacher-block-form" onSubmit={handleSave} className="space-y-3">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">اليوم</label>
              <select
                value={draft.weekday}
                onChange={(e) => setDraft({ ...draft, weekday: Number(e.target.value) })}
                className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
              >
                {DAY_ORDER.map((d) => (
                  <option key={d} value={d}>{WEEKDAY_LABELS[d]}</option>
                ))}
              </select>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">من</label>
                <input
                  type="time" required value={draft.starts_at}
                  onChange={(e) => setDraft({ ...draft, starts_at: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">إلى</label>
                <input
                  type="time" required value={draft.ends_at}
                  onChange={(e) => setDraft({ ...draft, ends_at: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">النوع</label>
              <div className="grid grid-cols-2 gap-2">
                {(["academy", "external", "leave", "personal"] as const).map((k) => (
                  <button
                    key={k} type="button"
                    onClick={() => setDraft({ ...draft, kind: k })}
                    className={`rounded-lg px-3 py-2 text-sm transition ${
                      draft.kind === k
                        ? `${KIND_TONE[k]} ring-2`
                        : "bg-slate-50 text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100"
                    }`}
                  >
                    {SCHEDULE_KIND_LABEL[k]}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">العنوان (اختياري)</label>
              <input
                value={draft.title}
                onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                placeholder={draft.kind === "external" ? "مثال: أكاديمية النور" : "مثال: حصة تحفيظ"}
                className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
              />
            </div>
          </form>
        </Modal>
      )}
    </div>
  );
}

// ============================================================
// تبويب: مالي (قراءة فقط)
// ============================================================

function FinanceTab({ teacherId }: { teacherId: number }) {
  const [data, setData] = useState<TeacherFinancialSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    getTeacherFinancialSummary(teacherId)
      .then((r) => { if (active) setData(r); })
      .catch((e) => { if (active) setError(e instanceof Error ? e.message : "تعذر التحميل"); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [teacherId]);

  if (loading) return <TabSkeleton />;
  if (error) return <p className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>;
  if (!data) return null;

  const s = data.summary;
  const cur = data.currency;

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
        <span>🔒</span>
        <p className="text-xs text-amber-800">
          هذا الملخص للعرض فقط — أي تعديل مالي يتم من قسم المدفوعات والرواتب.
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="مستحقات الشهر" value={fmtMoney(s.earned_this_month, cur)} tone="slate" />
        <StatCard label="مدفوع الشهر" value={fmtMoney(s.paid_this_month, cur)} tone="green" />
        <StatCard label="معلّق" value={fmtMoney(s.pending_total, cur)} tone="amber" />
        <StatCard
          label="المستحق للمعلم"
          value={fmtMoney(s.outstanding, cur)}
          tone={s.outstanding > 0 ? "purple" : "green"}
          hint={s.outstanding > 0 ? "لم يُدفع بعد" : "مسدّد بالكامل"}
        />
      </div>

      <Section title="إجماليات عامة" icon="💰">
        <InfoGrid rows={[
          { label: "إجمالي المستحقات", value: fmtMoney(s.earned_total, cur) },
          { label: "إجمالي المدفوع", value: fmtMoney(s.paid_total, cur) },
        ]} />
      </Section>

      <Section title="العقد الحالي" icon="📄">
        {data.contract ? (
          <InfoGrid rows={[
            { label: "نوع التعاقد", value: CONTRACT_LABEL[data.contract.contract_type] ?? data.contract.contract_type },
            { label: "يبدأ من", value: fmtDate(data.contract.start_date) },
            { label: "ينتهي في", value: data.contract.end_date ? fmtDate(data.contract.end_date) : "مفتوح" },
            { label: "الراتب الشهري", value: data.contract.monthly_salary ? fmtMoney(data.contract.monthly_salary, cur) : "—" },
          ]} />
        ) : <Empty text="لا يوجد عقد نشط" />}
      </Section>

      <Section title="سعر الحصة الحالي" icon="🏷️">
        {data.rate ? (
          <InfoGrid rows={[
            { label: RATE_LABEL[data.rate.rate_type] ?? "القيمة", value: fmtMoney(data.rate.amount, cur) },
            { label: "مدة الحصة", value: data.rate.duration_minutes ? `${data.rate.duration_minutes} دقيقة` : "—" },
            { label: "ساري من", value: fmtDate(data.rate.effective_from) },
            { label: "ساري حتى", value: data.rate.effective_to ? fmtDate(data.rate.effective_to) : "مفتوح" },
          ]} />
        ) : <Empty text="لم يتم تحديد سعر" />}
      </Section>
    </div>
  );
}

// ============================================================
// نموذج إضافة / تعديل معلم
// ============================================================

type TeacherFormState = {
  display_name: string;
  phone: string;
  email: string;
  country_code: string;
  specialization: string;
  qualifications: string;
  years_of_experience: string;
  languages: string;
  bio: string;
  status: Teacher["status"];
};

const EMPTY_FORM: TeacherFormState = {
  display_name: "", phone: "", email: "", country_code: "",
  specialization: "", qualifications: "", years_of_experience: "",
  languages: "", bio: "", status: "active",
};

const COUNTRY_OPTIONS = [
  { code: "+20", name: "مصر" }, { code: "+966", name: "السعودية" },
  { code: "+971", name: "الإمارات" }, { code: "+965", name: "الكويت" },
  { code: "+974", name: "قطر" }, { code: "+962", name: "الأردن" },
  { code: "+218", name: "ليبيا" }, { code: "+212", name: "المغرب" },
];

const inputCls = "rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500";

function TeacherFormModal({
  teacher, onClose, onSaved,
}: {
  teacher: Teacher | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState<TeacherFormState>(() =>
    teacher
      ? {
        display_name: teacher.display_name,
        phone: teacher.phone,
        email: teacher.email ?? "",
        country_code: teacher.country_code ?? "",
        specialization: teacher.specialization ?? "",
        qualifications: teacher.qualifications ?? "",
        years_of_experience: teacher.years_of_experience?.toString() ?? "",
        languages: teacher.languages ?? "",
        bio: teacher.bio ?? "",
        status: teacher.status,
      }
      : EMPTY_FORM,
  );
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);

    const payload = {
      display_name: form.display_name.trim(),
      phone: form.phone.trim(),
      email: form.email.trim() || undefined,
      country_code: form.country_code || undefined,
      specialization: form.specialization.trim() || undefined,
      qualifications: form.qualifications.trim() || undefined,
      years_of_experience: form.years_of_experience ? Number(form.years_of_experience) : undefined,
      languages: form.languages.trim() || undefined,
      bio: form.bio.trim() || undefined,
      status: form.status,
    };

    try {
      if (teacher) await updateTeacher(teacher.id, payload);
      else await createTeacher(payload);
      onSaved();
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل الحفظ");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center overflow-y-auto bg-black/50 p-4">
      <div className="my-8 w-full max-w-2xl rounded-xl bg-white shadow-2xl">
        <header className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <h3 className="text-base font-semibold text-slate-800">
            {teacher ? "✏️ تعديل بيانات المعلم" : "➕ إضافة معلم جديد"}
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">✕</button>
        </header>

        <form onSubmit={handleSubmit}>
          <div className="max-h-[65vh] space-y-5 overflow-y-auto px-5 py-4">
            <fieldset className="space-y-3">
              <legend className="mb-2 text-sm font-semibold text-slate-700">👤 البيانات الأساسية</legend>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <input required placeholder="الاسم *" value={form.display_name}
                  onChange={(e) => setForm((f) => ({ ...f, display_name: e.target.value }))} className={inputCls} />
                <input required placeholder="رقم الهاتف *" value={form.phone}
                  onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} className={inputCls} />
                <select value={form.country_code}
                  onChange={(e) => setForm((f) => ({ ...f, country_code: e.target.value }))} className={inputCls}>
                  <option value="">— الدولة —</option>
                  {COUNTRY_OPTIONS.map((c) => (
                    <option key={c.code} value={c.code}>{COUNTRY_FLAGS[c.code]} {c.name} ({c.code})</option>
                  ))}
                </select>
                <input type="email" placeholder="البريد الإلكتروني" value={form.email}
                  onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))} className={inputCls} />
              </div>
            </fieldset>

            <fieldset className="space-y-3 rounded-lg bg-blue-50/60 p-3">
              <legend className="px-1 text-sm font-semibold text-blue-700">💼 البيانات المهنية</legend>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <input placeholder="التخصص (مثال: تحفيظ)" value={form.specialization}
                  onChange={(e) => setForm((f) => ({ ...f, specialization: e.target.value }))} className={inputCls} />
                <input placeholder="المؤهلات" value={form.qualifications}
                  onChange={(e) => setForm((f) => ({ ...f, qualifications: e.target.value }))} className={inputCls} />
                <input type="number" min={0} max={70} placeholder="سنوات الخبرة" value={form.years_of_experience}
                  onChange={(e) => setForm((f) => ({ ...f, years_of_experience: e.target.value }))} className={inputCls} />
                <input placeholder="اللغات (مثال: العربية، الإنجليزية)" value={form.languages}
                  onChange={(e) => setForm((f) => ({ ...f, languages: e.target.value }))} className={inputCls} />
              </div>
              <textarea rows={3} placeholder="نبذة تعريفية عن المعلم" value={form.bio}
                onChange={(e) => setForm((f) => ({ ...f, bio: e.target.value }))} className={`${inputCls} w-full`} />
            </fieldset>

            <fieldset className="space-y-3">
              <legend className="mb-2 text-sm font-semibold text-slate-700">⚙️ الحالة</legend>
              <div className="flex gap-2">
                {(["active", "inactive"] as const).map((s) => (
                  <button key={s} type="button" onClick={() => setForm((f) => ({ ...f, status: s }))}
                    className={`flex-1 rounded-lg border px-3 py-2 text-sm transition ${
                      form.status === s
                        ? "border-slate-800 bg-slate-800 text-white"
                        : "border-slate-300 bg-white text-slate-600 hover:border-slate-400"
                    }`}>
                    {STATUS_LABEL[s]}
                  </button>
                ))}
              </div>
            </fieldset>

            {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600">{error}</p>}
          </div>

          <footer className="flex gap-2 border-t border-slate-200 px-5 py-3">
            <button type="submit" disabled={saving}
              className="flex-1 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-60">
              {saving ? "جارٍ الحفظ..." : teacher ? "حفظ التعديلات" : "إضافة المعلم"}
            </button>
            <button type="button" onClick={onClose}
              className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
          </footer>
        </form>
      </div>
    </div>
  );
}

// ============================================================
// ملف المعلم (Drawer)
// ============================================================

type TabKey = "overview" | "students" | "lessons" | "schedule" | "finance";

const TABS: { key: TabKey; label: string; icon: string }[] = [
  { key: "overview", label: "نظرة عامة", icon: "👤" },
  { key: "students", label: "الطلاب", icon: "🎓" },
  { key: "lessons", label: "الحصص", icon: "📅" },
  { key: "schedule", label: "جدوله", icon: "🕐" },
  { key: "finance", label: "مالي", icon: "💰" },
];

function TeacherDrawer({ teacher, onClose, onEdit, onRefresh }: {
  teacher: Teacher;
  onClose: () => void;
  onEdit: (teacher: Teacher) => void;
  onRefresh: (teacherId: number) => void;
}) {
  const [tab, setTab] = useState<TabKey>("overview");

  return (
    <div className="fixed inset-0 z-50 flex">
      <div className="flex-1 bg-black/50" onClick={onClose} />

      <div className="flex h-full w-full max-w-3xl flex-col bg-white shadow-2xl">
        <header className="flex items-start justify-between gap-4 border-b border-slate-200 p-4">
          <div className="flex min-w-0 items-center gap-3">
            <TeacherAvatar teacher={teacher} size="lg" />
            <div className="min-w-0">
              <h2 className="truncate text-lg font-bold text-slate-800">{teacher.full_name}</h2>
              <p className="truncate text-sm text-slate-500">
                {teacher.teacher_code}
                {teacher.specialization ? ` — ${teacher.specialization}` : ""}
              </p>
              <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs">
                <span className={`rounded-full px-2.5 py-0.5 font-medium ${STATUS_COLORS[teacher.status]}`}>
                  {STATUS_LABEL[teacher.status]}
                </span>
                {(teacher.students_count ?? 0) > 0 && (
                  <span className="rounded-full bg-blue-50 px-2.5 py-0.5 text-blue-700">
                    {teacher.students_count} طالب
                  </span>
                )}
                {(teacher.lessons_count ?? 0) > 0 && (
                  <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-slate-600">
                    {teacher.lessons_count} حصة
                  </span>
                )}
                {teacher.average_rating ? (
                  <span className="flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-amber-700">
                    <Stars value={teacher.average_rating} />
                    {teacher.average_rating}
                  </span>
                ) : null}
              </div>
            </div>
          </div>

          <button onClick={onClose} className="shrink-0 text-slate-400 hover:text-slate-600">✕</button>
        </header>

        <nav className="flex gap-1 overflow-x-auto border-b border-slate-200 px-3">
          {TABS.map((t) => (
            <button key={t.key} onClick={() => setTab(t.key)}
              className={`shrink-0 rounded-t-lg px-4 py-2.5 text-sm transition ${
                tab === t.key
                  ? "border-b-2 border-slate-800 font-medium text-slate-800"
                  : "border-b-2 border-transparent text-slate-500 hover:text-slate-700"
              }`}>
              <span className="ml-1">{t.icon}</span>{t.label}
            </button>
          ))}

          {/* التقييمات — معطّل مؤقتاً */}
          <span
            title="قريباً — قسم التقييمات لسه شغال عليه"
            aria-disabled="true"
            className="relative flex shrink-0 cursor-not-allowed items-center rounded-t-lg border-b-2 border-transparent px-4 py-2.5 text-sm text-slate-300">
            ⭐ التقييمات
            <span className="mr-1.5 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-400">قريباً</span>
          </span>
        </nav>

        <div className="flex-1 overflow-y-auto bg-slate-50/50 p-4">
          {tab === "overview" && <OverviewTab teacherId={teacher.id} />}
          {tab === "students" && <StudentsTab teacherId={teacher.id} />}
          {tab === "lessons" && <LessonsTab teacherId={teacher.id} />}
          {tab === "schedule" && <ScheduleTab teacherId={teacher.id} />}
          {tab === "finance" && <FinanceTab teacherId={teacher.id} />}
        </div>

        <footer className="flex gap-2 border-t border-slate-200 p-3">
          <button onClick={() => onEdit(teacher)}
            className="flex-1 rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-700">
            ✏️ تعديل البيانات
          </button>
          <button
            onClick={async () => {
              const next = teacher.status === "active" ? "inactive" : "active";
              await updateTeacher(teacher.id, { status: next });
              onRefresh(teacher.id);
              onClose();
            }}
            className={`rounded-lg px-4 py-2.5 text-sm font-medium transition ${
              teacher.status === "active"
                ? "bg-amber-50 text-amber-700 hover:bg-amber-100"
                : "bg-green-50 text-green-700 hover:bg-green-100"
            }`}>
            {teacher.status === "active" ? "⏸ إيقاف" : "▶ تفعيل"}
          </button>
        </footer>
      </div>
    </div>
  );
}

// ============================================================
// الصفحة الرئيسية: قائمة المعلمين
// ============================================================

export default function TeachersPage() {
  const { toast, confirm } = useUI();

  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [specializationFilter, setSpecializationFilter] = useState("");
  const [sort, setSort] = useState("created_at");

  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Teacher | null>(null);

  const PAGE_SIZE = 100;
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [countsAll, setCountsAll] = useState<{ total: number }>({ total: 0 });

  const load = useCallback(async (targetPage = 1) => {
    setLoading(true);
    setError(null);
    try {
      const res = await getTeachers({
        search: search.trim() || undefined,
        status: statusFilter || undefined,
        specialization: specializationFilter || undefined,
        sort,
        dir: sort === "display_name" || sort === "joined_at" ? "asc" : "desc",
        page: targetPage,
        per_page: PAGE_SIZE,
      });
      setTeachers(res.data);
      setMeta({ total: res.total, last_page: res.last_page });
      setCounts((res.counts ?? {}) as Record<string, number>);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, [search, statusFilter, specializationFilter, sort]);

  // أي تغيير في الفلاتر يرجّعنا للصفحة الأولى
  useEffect(() => {
    const t = setTimeout(() => {
      setPage(1);
      load(1);
    }, 300);
    return () => clearTimeout(t);
  }, [load]);

  const goToPage = useCallback((target: number) => {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }, [page, meta.last_page, load]);

  // الإجمالي الكلي غير المتفلتر — مرة واحدة عند التحميل
  const loadUnfilteredTotal = useCallback(async () => {
    try {
      const res = await getTeachers({ per_page: 1 });
      setCountsAll({ total: res.total });
    } catch {
      // fallback للمتصفح
    }
  }, []);

  useEffect(() => {
    loadUnfilteredTotal();
  }, [loadUnfilteredTotal]);

  // تحديث معلم واحد بدون إعادة تحميل القائمة كاملة
  const refreshOne = useCallback((teacherId: number) => {
    setTeachers((prev) => prev.map((t) => (t.id === teacherId ? { ...t, ...prev.find((p) => p.id === teacherId) } : t)));
  }, []);

  const stats = useMemo(() => ({
    total: countsAll.total || meta.total || teachers.length,
    active: counts.active ?? 0,
    inactive: counts.inactive ?? 0,
    students: teachers.reduce((sum, t) => sum + (t.students_count ?? 0), 0),
  }), [teachers, meta.total, counts, countsAll]);

  const specializations = useMemo(
    () => [...new Set(teachers.map((t) => t.specialization).filter(Boolean))] as string[],
    [teachers],
  );

  const selected = teachers.find((t) => t.id === selectedId) ?? null;

  function exportCsv() {
    const header = ["الكود", "الاسم", "الهاتف", "التخصص", "الحالة", "الطلاب", "الحصص", "التقييم"];
    const rows = teachers.map((t) => [
      t.teacher_code, t.display_name, fullPhone(t), t.specialization ?? "",
      STATUS_LABEL[t.status], String(t.students_count ?? 0),
      String(t.lessons_count ?? 0), t.average_rating?.toString() ?? "",
    ]);
    // BOM عشان الإكسل يقرأ العربي صح
    const csv = "\uFEFF" + [header, ...rows].map((r) => r.map((c) => `"${c.replace(/"/g, '""')}"`).join(",")).join("\r\n");
    const url = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `teachers-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  async function handleDelete(t: Teacher) {
    const ok = await confirm({
      title: "حذف المعلم",
      message: `متأكد إنك عايز تحذف المعلم «${t.display_name}»؟\n\n• الحصص السابقة ليه مش هتتحذف.\n• الاشتراكات المرتبطة بيه هيفضلوا بدون مدرس لحد ما تغيّرهم.`,
      confirmLabel: "احذف المعلم",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;
    try {
      await deleteTeacher(t.id);
      toast.success("تم حذف المعلم", t.display_name);
      setSelectedId(null);
      load();
    } catch (err) {
      toast.error("فشل حذف المعلم", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  return (
    <div>
      {/* الهيدر */}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">المعلمون</h1>
          <p className="text-sm text-slate-500">إدارة بيانات المعلمين وبرامجهم وحصصهم</p>
        </div>
        <div className="flex gap-2">
          <button onClick={exportCsv} disabled={teachers.length === 0}
            className="rounded-lg border border-slate-300 px-3.5 py-2 text-sm text-slate-600 transition hover:bg-slate-50 disabled:opacity-40">
            ⬇ تصدير
          </button>
          <button onClick={() => { setEditing(null); setFormOpen(true); }}
            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">
            ➕ معلم جديد
          </button>
        </div>
      </div>

      {/* الإحصائيات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="إجمالي المعلمين" value={stats.total} tone="slate" />
        <StatCard label="نشط" value={stats.active} tone="green" />
        <StatCard label="غير نشط" value={stats.inactive} tone="slate" />
        <StatCard label="إجمالي الطلاب" value={stats.students} tone="blue" hint="حسب بيانات الحصص" />
      </div>

      {/* البحث والفلاتر */}
      <div className="mb-4 flex flex-wrap gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="🔎 ابحث بالاسم أو الكود أو الهاتف أو البريد..."
          className="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3.5 py-2 text-sm outline-none focus:border-slate-500"
        />
        <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none focus:border-slate-500">
          <option value="">كل الحالات</option>
          <option value="active">نشط</option>
          <option value="inactive">غير نشط</option>
        </select>
        <select value={specializationFilter} onChange={(e) => setSpecializationFilter(e.target.value)}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none focus:border-slate-500">
          <option value="">كل التخصصات</option>
          {specializations.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
        <select value={sort} onChange={(e) => setSort(e.target.value)}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none focus:border-slate-500">
          {SORT_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </div>

      {error && (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-red-50 px-4 py-2.5">
          <p className="text-sm text-red-700">{error}</p>
          <button onClick={() => load(page)} className="text-sm font-medium text-red-700 underline">إعادة المحاولة</button>
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3, 4].map((i) => <div key={i} className="h-16 animate-pulse rounded-xl bg-slate-100" />)}
        </div>
      ) : teachers.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-4xl">👨‍🏫</p>
          <p className="mt-3 text-sm font-medium text-slate-600">
            {search || statusFilter || specializationFilter ? "مفيش نتائج مطابقة للفلاتر" : "لسه مفيش معلمين مسجّلين"}
          </p>
          <button onClick={() => { setEditing(null); setFormOpen(true); }}
            className="mt-4 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            إضافة أول معلم
          </button>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-start font-medium">المعلم</th>
                <th className="px-3 py-3 text-start font-medium">التخصص</th>
                <th className="hidden px-3 py-3 text-start font-medium lg:table-cell">البرامج</th>
                <th className="px-2 py-3 text-center font-medium">الطلاب</th>
                <th className="px-2 py-3 text-center font-medium">الحصص</th>
                <th className="hidden px-3 py-3 text-center font-medium sm:table-cell">التقييم</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-end font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {teachers.map((t) => (
                <tr key={t.id} onClick={() => setSelectedId(t.id)}
                  className="cursor-pointer border-b border-slate-100 transition last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <TeacherAvatar teacher={t} size="sm" />
                      <div className="min-w-0">
                        <p className="truncate font-medium text-slate-800">{t.full_name}</p>
                        <p className="truncate text-xs text-slate-400">{fullPhone(t)}</p>
                      </div>
                    </div>
                  </td>

                  <td className="px-3 py-3 text-slate-600">{t.specialization ?? "—"}</td>

                  <td className="hidden px-3 py-3 lg:table-cell">
                    {t.programs && t.programs.length > 0 ? (
                      <div className="flex flex-wrap gap-1">
                        {t.programs.slice(0, 2).map((p) => (
                          <span key={p.id} className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">
                            {p.name}
                          </span>
                        ))}
                        {t.programs.length > 2 && (
                          <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-400">
                            +{t.programs.length - 2}
                          </span>
                        )}
                      </div>
                    ) : <span className="text-slate-400">—</span>}
                  </td>

                  <td className="px-2 py-3 text-center text-slate-700">{t.students_count ?? 0}</td>
                  <td className="px-2 py-3 text-center text-slate-700">{t.lessons_count ?? 0}</td>

                  <td className="hidden px-3 py-3 sm:table-cell">
                    {t.average_rating ? (
                      <span className="inline-flex items-center gap-1">
                        <Stars value={t.average_rating} />
                        <span className="text-xs font-medium text-slate-700">{t.average_rating}</span>
                      </span>
                    ) : <span className="text-xs text-slate-300">—</span>}
                  </td>

                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${STATUS_COLORS[t.status]}`}>
                      {STATUS_LABEL[t.status]}
                    </span>
                  </td>

                  <td className="px-3 py-3" onClick={(e) => e.stopPropagation()}>
                    <div className="flex justify-end gap-1">
                      <button onClick={() => setSelectedId(t.id)} title="عرض الملف"
                        className="rounded px-2 py-1 text-xs text-slate-500 transition hover:bg-slate-100 hover:text-slate-800">
                        👁
                      </button>
                      <button onClick={() => { setEditing(t); setFormOpen(true); }} title="تعديل"
                        className="rounded px-2 py-1 text-xs text-blue-600 transition hover:bg-blue-50">
                        ✏️
                      </button>
                      <button title={t.status === "active" ? "إيقاف" : "تفعيل"}
                        onClick={async () => {
                          await updateTeacher(t.id, { status: t.status === "active" ? "inactive" : "active" });
                          load();
                        }}
                        className="rounded px-2 py-1 text-xs text-amber-600 transition hover:bg-amber-50">
                        {t.status === "active" ? "⏸" : "▶"}
                      </button>
                      <button onClick={() => handleDelete(t)} title="حذف"
                        className="rounded px-2 py-1 text-xs text-red-600 transition hover:bg-red-50">
                        🗑
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* ===== Pagination ===== */}
      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="معلم"
      />

      {formOpen && (
        <TeacherFormModal
          teacher={editing}
          onClose={() => { setFormOpen(false); setEditing(null); }}
          onSaved={() => load(page)}
        />
      )}

      {selected && !formOpen && (
        <TeacherDrawer
          teacher={selected}
          onClose={() => setSelectedId(null)}
          onEdit={(t) => { setEditing(t); setFormOpen(true); }}
          onRefresh={refreshOne}
        />
      )}
    </div>
  );
}