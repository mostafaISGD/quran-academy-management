"use client";

import { useEffect, useState, useCallback, useMemo } from "react";
import {
  DndContext, DragOverlay, closestCorners, PointerSensor,
  useSensor, useSensors, type DragStartEvent, type DragEndEvent,
} from "@dnd-kit/core";
import { CSS } from "@dnd-kit/utilities";
import { useSortable } from "@dnd-kit/sortable";
import {
  apiFetch, getSchedule, createLesson, cancelLesson, completeLesson,
  markAttendance, recordMemorization, getStudents, getTeachers,
  getPrograms, getSubscriptions,
  type Lesson, type Student, type Teacher, type Program, type Subscription,
} from "@/lib/api";

type ViewMode = "day" | "week" | "teachers";
type LessonType = "single" | "weekly" | "monthly";

const STATUS_CONFIG: Record<string, { label: string; color: string; bg: string }> = {
  scheduled: { label: "مجدولة", color: "text-yellow-700", bg: "bg-yellow-100 border-yellow-300" },
  confirmed: { label: "مؤكدة", color: "text-blue-700", bg: "bg-blue-100 border-blue-300" },
  in_progress: { label: "جارية الآن", color: "text-purple-700", bg: "bg-purple-100 border-purple-300" },
  completed: { label: "مكتملة", color: "text-green-700", bg: "bg-green-100 border-green-300" },
  cancelled: { label: "ملغاة", color: "text-red-700", bg: "bg-red-100 border-red-300" },
  student_absent: { label: "غياب الطالب", color: "text-orange-700", bg: "bg-orange-100 border-orange-300" },
  teacher_absent: { label: "غياب المعلم", color: "text-red-700", bg: "bg-red-100 border-red-300" },
  technical_issue: { label: "مشكلة تقنية", color: "text-red-700", bg: "bg-red-100 border-red-300" },
  rescheduled: { label: "أُعيدت جدولتها", color: "text-purple-700", bg: "bg-purple-100 border-purple-300" },
};

const LESSON_TYPE_LABEL: Record<string, string> = {
  regular: "عادية", trial: "تجريبية", makeup: "تعويضية", extra: "إضافية", free: "مجانية", assessment: "تقييم",
};

const DAYS_AR = ["الأحد", "الإثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];
const DAYS_SHORT = ["أحد", "إثنين", "ثلاثاء", "أربعاء", "خميس", "جمعة", "سبت"];

function formatTime(iso: string) {
  return new Date(iso).toLocaleTimeString("ar-EG", { hour: "2-digit", minute: "2-digit" });
}

function formatDate(date: Date) {
  return date.toLocaleDateString("ar-EG", { day: "numeric", month: "long", year: "numeric" });
}

export default function SchedulePage() {
  const [lessons, setLessons] = useState<Lesson[]>([]);
  const [students, setStudents] = useState<Student[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<ViewMode>("day");
  const [currentDate, setCurrentDate] = useState(new Date());
  const [showQuickAdd, setShowQuickAdd] = useState(false);
  const [quickAddSlot, setQuickAddSlot] = useState<{ hour: number; dayIndex?: number } | null>(null);
  const [selectedLesson, setSelectedLesson] = useState<Lesson | null>(null);
  const [activeLesson, setActiveLesson] = useState<Lesson | null>(null);
  const [filter, setFilter] = useState("all");
  const [searchQuery, setSearchQuery] = useState("");
  const [showProblems, setShowProblems] = useState(false);

  // Quick add form
  const [qaForm, setQaForm] = useState({
    student_id: "", teacher_id: "", program_id: "", subscription_id: "",
    lesson_type: "single" as LessonType, date: "", start_time: "", end_time: "",
    duration_minutes: "30", weekdays: [] as number[], recurring_until: "",
  });

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

  const loadSchedule = useCallback(() => {
    setLoading(true);
    const view = viewMode === "day" ? "day" : viewMode === "week" ? "week" : "month";
    getSchedule({ view, date: currentDate.toISOString().split("T")[0] })
      .then(setLessons)
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل الجدول"))
      .finally(() => setLoading(false));
  }, [viewMode, currentDate]);

  useEffect(() => {
    loadSchedule();
    getStudents().then((r) => setStudents(r.data)).catch(() => {});
    getTeachers().then((r) => setTeachers(r.data)).catch(() => {});
    getPrograms().then((r) => setPrograms(r.data)).catch(() => {});
    getSubscriptions().then((r) => setSubscriptions(r.data)).catch(() => {});
  }, [loadSchedule]);

  // Stats
  const stats = useMemo(() => {
    const today = new Date().toDateString();
    const todayLessons = lessons.filter((l) => new Date(l.scheduled_start_at).toDateString() === today);
    return {
      total: todayLessons.length,
      scheduled: todayLessons.filter((l) => l.status === "scheduled").length,
      completed: todayLessons.filter((l) => l.status === "completed").length,
      absent: todayLessons.filter((l) => l.status === "student_absent" || l.status === "teacher_absent").length,
      cancelled: todayLessons.filter((l) => l.status === "cancelled").length,
      issues: todayLessons.filter((l) => l.status === "technical_issue").length,
      makeups: todayLessons.filter((l) => l.lesson_type === "makeup").length,
      unconfirmed: todayLessons.filter((l) => l.status === "scheduled").length,
      liveNow: todayLessons.filter((l) => l.status === "in_progress").length,
    };
  }, [lessons]);

  // Filtered lessons
  const filteredLessons = useMemo(() => {
    let result = lessons;
    if (filter === "today") result = result.filter((l) => new Date(l.scheduled_start_at).toDateString() === new Date().toDateString());
    if (filter === "in_progress") result = result.filter((l) => l.status === "in_progress");
    if (filter === "problems") result = result.filter((l) => ["technical_issue", "teacher_absent", "student_absent"].includes(l.status));
    if (filter === "makeups") result = result.filter((l) => l.lesson_type === "makeup");
    if (filter === "trials") result = result.filter((l) => l.lesson_type === "trial");
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      result = result.filter((l) =>
        l.student?.full_name.toLowerCase().includes(q) ||
        l.teacher?.full_name.toLowerCase().includes(q) ||
        l.program?.name.toLowerCase().includes(q)
      );
    }
    return result;
  }, [lessons, filter, searchQuery]);

  // Day view: group by hour
  const lessonsByHour = useMemo(() => {
    const map: Record<number, Lesson[]> = {};
    filteredLessons.forEach((l) => {
      const hour = new Date(l.scheduled_start_at).getHours();
      map[hour] = map[hour] ?? [];
      map[hour].push(l);
    });
    return map;
  }, [filteredLessons]);

  // Week view: group by day and hour
  const lessonsByDayAndHour = useMemo(() => {
    const map: Record<string, Lesson[]> = {};
    filteredLessons.forEach((l) => {
      const day = new Date(l.scheduled_start_at).getDay();
      const hour = new Date(l.scheduled_start_at).getHours();
      const key = `${day}-${hour}`;
      map[key] = map[key] ?? [];
      map[key].push(l);
    });
    return map;
  }, [filteredLessons]);

  // Teachers view
  const teacherSchedules = useMemo(() => {
    const map: Record<number, { teacher: Teacher; lessons: Lesson[] }> = {};
    teachers.forEach((t) => { map[t.id] = { teacher: t, lessons: [] }; });
    filteredLessons.forEach((l) => {
      if (map[l.teacher_id]) map[l.teacher_id].lessons.push(l);
    });
    return Object.values(map);
  }, [teachers, filteredLessons]);

  function navigateDate(direction: number) {
    const newDate = new Date(currentDate);
    if (viewMode === "day") newDate.setDate(newDate.getDate() + direction);
    else if (viewMode === "week") newDate.setDate(newDate.getDate() + direction * 7);
    else newDate.setMonth(newDate.getMonth() + direction);
    setCurrentDate(newDate);
  }

  function openQuickAdd(hour: number, dayIndex?: number) {
    setQuickAddSlot({ hour, dayIndex });
    setQaForm({ ...qaForm, date: currentDate.toISOString().split("T")[0], start_time: `${hour.toString().padStart(2, "0")}:00` });
    setShowQuickAdd(true);
  }

  async function handleQuickAdd(e: React.FormEvent) {
    e.preventDefault();
    try {
      const startTime = qaForm.date + "T" + qaForm.start_time;
      if (qaForm.lesson_type === "single") {
        await createLesson({
          student_id: Number(qaForm.student_id), teacher_id: Number(qaForm.teacher_id),
          program_id: Number(qaForm.program_id), subscription_id: qaForm.subscription_id || undefined,
          lesson_type: "regular", scheduled_start: startTime, duration_minutes: Number(qaForm.duration_minutes),
        });
      } else {
        const weekdays = qaForm.weekdays.length > 0 ? qaForm.weekdays : [new Date(qaForm.date).getDay()];
        const endDate = qaForm.recurring_until || new Date(new Date(qaForm.date).setMonth(new Date(qaForm.date).getMonth() + 1)).toISOString().split("T")[0];
        await apiFetch("/lessons/recurring", {
          method: "POST",
          body: JSON.stringify({
            student_id: Number(qaForm.student_id), teacher_id: Number(qaForm.teacher_id),
            program_id: Number(qaForm.program_id), subscription_id: qaForm.subscription_id || undefined,
            weekdays, start_time: qaForm.start_time, duration_minutes: Number(qaForm.duration_minutes),
            series_start_date: qaForm.date, end_date: endDate,
          }),
        });
      }
      setShowQuickAdd(false);
      loadSchedule();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل إنشاء الحصة"); }
  }

  async function handleCancel(lesson: Lesson) {
    const reason = prompt("سبب الإلغاء:");
    if (!reason) return;
    try { await cancelLesson(lesson.id, reason); loadSchedule(); } catch (err) { setError(err instanceof Error ? err.message : "فشل الإلغاء"); }
  }

  async function handleComplete(lesson: Lesson) {
    try { await completeLesson(lesson.id); loadSchedule(); } catch (err) { setError(err instanceof Error ? err.message : "فشل الإكمال"); }
  }

  async function handleAttendance(lesson: Lesson, status: string) {
    try { await markAttendance(lesson.id, status); loadSchedule(); } catch (err) { setError(err instanceof Error ? err.message : "فشل تسجيل الحضور"); }
  }

  function handleDragStart(event: DragStartEvent) {
    const { active } = event;
    const lesson = lessons.find((l) => l.id === active.id);
    if (lesson) setActiveLesson(lesson);
  }

  async function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    setActiveLesson(null);
    if (!over) return;
    const activeId = active.id as number;
    const overData = over.data.current;
    if (overData?.type === "slot") {
      const { dayIndex, hour } = overData as { dayIndex: number; hour: number };
      const lesson = lessons.find((l) => l.id === activeId);
      if (!lesson) return;
      const oldDate = new Date(lesson.scheduled_start_at);
      const newDate = new Date(oldDate);
      newDate.setDate(newDate.getDate() - oldDate.getDay() + dayIndex);
      newDate.setHours(hour, 0, 0, 0);
      if (oldDate.getTime() === newDate.getTime()) return;
      try {
        await apiFetch(`/lessons/${lesson.id}`, { method: "PUT", body: JSON.stringify({ scheduled_start: newDate.toISOString() }) });
        loadSchedule();
      } catch (err) { setError(err instanceof Error ? err.message : "فشل إعادة الجدولة"); }
    }
  }

  const HOURS = Array.from({ length: 17 }, (_, i) => i + 6); // 6 AM to 10 PM

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-slate-800">جدول الحصص</h1>
        <div className="flex gap-2">
          <button onClick={() => { setViewMode("day"); setCurrentDate(new Date()); }} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">+ حجز حصة</button>
        </div>
      </div>

      {/* View Switcher + Navigation */}
      <div className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-3">
        <div className="flex items-center gap-2">
          <button onClick={() => navigateDate(-1)} className="rounded-lg bg-slate-100 px-3 py-2 text-sm hover:bg-slate-200">◀</button>
          <span className="min-w-[200px] text-center text-sm font-medium text-slate-700">
            {viewMode === "day" ? formatDate(currentDate) : viewMode === "week" ? `أسبوع ${formatDate(currentDate)}` : currentDate.toLocaleDateString("ar-EG", { month: "long", year: "numeric" })}
          </span>
          <button onClick={() => navigateDate(1)} className="rounded-lg bg-slate-100 px-3 py-2 text-sm hover:bg-slate-200">▶</button>
          <button onClick={() => setCurrentDate(new Date())} className="rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600 hover:bg-slate-200">اليوم</button>
        </div>
        <div className="flex gap-1">
          {(["day", "week", "teachers"] as ViewMode[]).map((mode) => (
            <button key={mode} onClick={() => setViewMode(mode)} className={`rounded-lg px-3 py-2 text-sm ${viewMode === mode ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}>
              {mode === "day" ? "اليوم" : mode === "week" ? "الأسبوع" : "المدرسين"}
            </button>
          ))}
        </div>
        <div className="flex items-center gap-2">
          <input placeholder="بحث..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
      </div>

      {/* Stats Bar */}
      <div className="grid grid-cols-7 gap-2">
        {[
          { label: "إجمالي اليوم", value: stats.total, color: "bg-slate-800 text-white" },
          { label: "مجدولة", value: stats.scheduled, color: "bg-yellow-100 text-yellow-800" },
          { label: "مكتملة", value: stats.completed, color: "bg-green-100 text-green-800" },
          { label: "غياب", value: stats.absent, color: "bg-orange-100 text-orange-800" },
          { label: "ملغاة", value: stats.cancelled, color: "bg-red-100 text-red-800" },
          { label: "تعويضات", value: stats.makeups, color: "bg-teal-100 text-teal-800" },
          { label: "مشاكل", value: stats.issues, color: "bg-red-100 text-red-800" },
        ].map((stat) => (
          <div key={stat.label} className={`rounded-xl p-3 text-center ${stat.color}`}>
            <p className="text-2xl font-bold">{stat.value}</p>
            <p className="text-xs">{stat.label}</p>
          </div>
        ))}
      </div>

      {/* Quick Filters */}
      <div className="flex gap-2">
        {[
          { key: "all", label: "الكل" },
          { key: "today", label: "اليوم" },
          { key: "in_progress", label: "قيد التنفيذ" },
          { key: "problems", label: "مشاكل" },
          { key: "makeups", label: "تعويضات" },
          { key: "trials", label: "تجارب" },
        ].map((f) => (
          <button key={f.key} onClick={() => setFilter(f.key)} className={`rounded-lg px-3 py-2 text-sm ${filter === f.key ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}>
            {f.label}
          </button>
        ))}
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {/* DAY VIEW - Timeline */}
      {!loading && !error && viewMode === "day" && (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="mb-4 text-lg font-semibold text-slate-800">جدول اليوم — {formatDate(currentDate)}</h2>
          <div className="space-y-1">
            {HOURS.map((hour) => {
              const hourLessons = lessonsByHour[hour] ?? [];
              return (
                <div key={hour} className="flex gap-2">
                  <div className="w-16 shrink-0 pt-2 text-right text-xs text-slate-400">
                    {hour.toString().padStart(2, "0")}:00
                  </div>
                  <div className="flex-1">
                    {hourLessons.length === 0 ? (
                      <button onClick={() => openQuickAdd(hour)} className="h-12 w-full rounded-lg border-2 border-dashed border-slate-200 text-slate-300 hover:border-emerald-300 hover:text-emerald-500">
                        +
                      </button>
                    ) : (
                      <div className="space-y-1">
                        {hourLessons.map((lesson) => (
                          <LessonCard key={lesson.id} lesson={lesson} onClick={() => setSelectedLesson(lesson)} />
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* WEEK VIEW - Calendar Grid */}
      {!loading && !error && viewMode === "week" && (
        <DndContext sensors={sensors} collisionDetection={closestCorners} onDragStart={handleDragStart} onDragEnd={handleDragEnd} onDragCancel={() => setActiveLesson(null)}>
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <h2 className="mb-4 text-lg font-semibold text-slate-800">جدول الأسبوع</h2>
            <div className="overflow-x-auto">
              <div className="min-w-[800px]">
                <div className="grid grid-cols-8 gap-1">
                  <div className="p-2 text-center text-xs text-slate-400">الوقت</div>
                  {DAYS_AR.map((day, i) => (
                    <div key={day} className="p-2 text-center text-xs font-medium text-slate-600">{day}</div>
                  ))}
                  {HOURS.map((hour) => (
                    <>
                      <div key={`time-${hour}`} className="p-2 text-right text-xs text-slate-400">
                        {hour.toString().padStart(2, "0")}:00
                      </div>
                      {DAYS_AR.map((_, dayIndex) => {
                        const dayLessons = lessonsByDayAndHour[`${dayIndex}-${hour}`] ?? [];
                        return (
                          <div key={`slot-${dayIndex}-${hour}`} className="min-h-[60px] rounded-lg border border-slate-100 bg-slate-50 p-1">
                            {dayLessons.map((lesson) => (
                              <DraggableLessonCard key={lesson.id} lesson={lesson} onClick={() => setSelectedLesson(lesson)} />
                            ))}
                            {dayLessons.length === 0 && (
                              <button onClick={() => openQuickAdd(hour, dayIndex)} className="h-full w-full text-slate-300 hover:text-emerald-500">+</button>
                            )}
                          </div>
                        );
                      })}
                    </>
                  ))}
                </div>
              </div>
            </div>
          </div>
          <DragOverlay>
            {activeLesson ? (
              <div className="rounded-lg border border-slate-300 bg-white p-2 text-xs shadow-lg">
                <span className="font-medium text-slate-800">{activeLesson.student?.full_name}</span>
              </div>
            ) : null}
          </DragOverlay>
        </DndContext>
      )}

      {/* TEACHERS VIEW */}
      {!loading && !error && viewMode === "teachers" && (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="mb-4 text-lg font-semibold text-slate-800">جدول المدرسين</h2>
          <div className="space-y-4">
            {teacherSchedules.map(({ teacher, lessons: tLessons }) => (
              <div key={teacher.id} className="rounded-xl border border-slate-200 p-4">
                <div className="mb-3 flex items-center justify-between">
                  <h3 className="font-medium text-slate-800">{teacher.full_name}</h3>
                  <span className="text-xs text-slate-500">{tLessons.length} حصة</span>
                </div>
                <div className="space-y-1">
                  {tLessons.length === 0 && <p className="text-sm text-slate-400">لا توجد حصص</p>}
                  {tLessons.map((lesson) => (
                    <div key={lesson.id} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                      <span className="text-slate-600">{formatTime(lesson.scheduled_start_at)} — {lesson.student?.full_name}</span>
                      <span className={`rounded-full px-2 py-0.5 text-xs ${STATUS_CONFIG[lesson.status]?.bg ?? "bg-slate-100"}`}>
                        {STATUS_CONFIG[lesson.status]?.label ?? lesson.status}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Quick Add Modal */}
      {showQuickAdd && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="w-full max-w-lg rounded-xl bg-white p-6">
            <h3 className="mb-4 text-lg font-semibold text-slate-800">حجز حصة</h3>
            <form onSubmit={handleQuickAdd} className="space-y-4">
              <div>
                <label className="mb-1 block text-sm text-slate-600">نوع الحصة</label>
                <div className="flex gap-2">
                  {(["single", "weekly", "monthly"] as LessonType[]).map((type) => (
                    <button key={type} type="button" onClick={() => setQaForm({ ...qaForm, lesson_type: type })}
                      className={`rounded-lg px-3 py-2 text-sm ${qaForm.lesson_type === type ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600"}`}>
                      {type === "single" ? "فردية" : type === "weekly" ? "أسبوعية" : "شهرية"}
                    </button>
                  ))}
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <select value={qaForm.student_id} onChange={(e) => setQaForm({ ...qaForm, student_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                  <option value="">اختر الطالب</option>
                  {students.map((s) => <option key={s.id} value={s.id}>{s.full_name}</option>)}
                </select>
                <select value={qaForm.teacher_id} onChange={(e) => setQaForm({ ...qaForm, teacher_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                  <option value="">اختر المعلم</option>
                  {teachers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
                </select>
                <select value={qaForm.program_id} onChange={(e) => setQaForm({ ...qaForm, program_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                  <option value="">اختر البرنامج</option>
                  {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
                <select value={qaForm.subscription_id} onChange={(e) => setQaForm({ ...qaForm, subscription_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                  <option value="">اختر الاشتراك</option>
                  {subscriptions.map((s) => <option key={s.id} value={s.id}>{s.student?.full_name ?? `اشتراك ${s.id}`}</option>)}
                </select>
                <input type="date" value={qaForm.date} onChange={(e) => setQaForm({ ...qaForm, date: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                <input type="time" value={qaForm.start_time} onChange={(e) => setQaForm({ ...qaForm, start_time: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                <input type="number" placeholder="المدة (دقيقة)" value={qaForm.duration_minutes} onChange={(e) => setQaForm({ ...qaForm, duration_minutes: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              </div>
              {qaForm.lesson_type !== "single" && (
                <div>
                  <label className="mb-1 block text-sm text-slate-600">أيام الأسبوع</label>
                  <div className="flex gap-2">
                    {DAYS_SHORT.map((day, i) => (
                      <button key={day} type="button" onClick={() => {
                        const weekdays = qaForm.weekdays.includes(i) ? qaForm.weekdays.filter((d) => d !== i) : [...qaForm.weekdays, i];
                        setQaForm({ ...qaForm, weekdays });
                      }} className={`rounded-lg px-3 py-2 text-sm ${qaForm.weekdays.includes(i) ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600"}`}>
                        {day}
                      </button>
                    ))}
                  </div>
                </div>
              )}
              {qaForm.lesson_type === "monthly" && (
                <div>
                  <label className="mb-1 block text-sm text-slate-600">حتى تاريخ</label>
                  <input type="date" value={qaForm.recurring_until} onChange={(e) => setQaForm({ ...qaForm, recurring_until: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                </div>
              )}
              <div className="flex gap-2">
                <button type="submit" className="flex-1 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حجز الحصة</button>
                <button type="button" onClick={() => setShowQuickAdd(false)} className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Lesson Detail Modal */}
      {selectedLesson && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div className="max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-lg font-semibold text-slate-800">تفاصيل الحصة</h3>
              <button onClick={() => setSelectedLesson(null)} className="text-slate-400 hover:text-slate-600">✕</button>
            </div>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="font-medium text-slate-800">{selectedLesson.student?.full_name}</span>
                <span className={`rounded-full px-2 py-1 text-xs ${STATUS_CONFIG[selectedLesson.status]?.bg ?? "bg-slate-100"}`}>
                  {STATUS_CONFIG[selectedLesson.status]?.label ?? selectedLesson.status}
                </span>
              </div>
              <p className="text-sm text-slate-600">المعلم: {selectedLesson.teacher?.full_name}</p>
              <p className="text-sm text-slate-600">البرنامج: {selectedLesson.program?.name}</p>
              <p className="text-sm text-slate-600">الوقت: {formatTime(selectedLesson.scheduled_start_at)} — {formatTime(selectedLesson.scheduled_end_at)}</p>
              <p className="text-sm text-slate-600">النوع: {LESSON_TYPE_LABEL[selectedLesson.lesson_type]}</p>
              {selectedLesson.lesson_type === "makeup" && (
                <p className="text-sm text-teal-600">تعويضية عن حصة #{selectedLesson.parent_lesson_id}</p>
              )}
              {selectedLesson.lesson_type === "trial" && (
                <p className="text-sm text-blue-600">حصة تجريبية</p>
              )}
              {selectedLesson.meeting_url && (
                <a href={selectedLesson.meeting_url} target="_blank" rel="noopener noreferrer" className="inline-block rounded-lg bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">
                  🎥 فتح الاجتماع
                </a>
              )}
              <div className="flex gap-2 border-t border-slate-200 pt-3">
                {selectedLesson.status !== "completed" && selectedLesson.status !== "cancelled" && (
                  <>
                    <button onClick={() => { handleComplete(selectedLesson); setSelectedLesson(null); }} className="flex-1 rounded-lg bg-green-100 px-3 py-2 text-sm text-green-700 hover:bg-green-200">إنهاء</button>
                    <button onClick={() => { handleAttendance(selectedLesson, "present"); setSelectedLesson(null); }} className="flex-1 rounded-lg bg-blue-100 px-3 py-2 text-sm text-blue-700 hover:bg-blue-200">حاضر</button>
                    <button onClick={() => { handleAttendance(selectedLesson, "absent"); setSelectedLesson(null); }} className="flex-1 rounded-lg bg-amber-100 px-3 py-2 text-sm text-amber-700 hover:bg-amber-200">غائب</button>
                    <button onClick={() => { handleCancel(selectedLesson); setSelectedLesson(null); }} className="flex-1 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-700 hover:bg-red-200">إلغاء</button>
                  </>
                )}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function LessonCard({ lesson, onClick }: { lesson: Lesson; onClick: () => void }) {
  const config = STATUS_CONFIG[lesson.status] ?? { label: lesson.status, color: "text-slate-700", bg: "bg-slate-100 border-slate-300" };
  return (
    <div onClick={onClick} className={`cursor-pointer rounded-lg border p-3 transition-shadow hover:shadow-md ${config.bg}`}>
      <div className="flex items-center justify-between">
        <span className="font-medium text-slate-800">{lesson.student?.full_name}</span>
        <span className={`rounded-full px-2 py-0.5 text-xs ${config.bg} ${config.color}`}>{config.label}</span>
      </div>
      <p className="mt-1 text-sm text-slate-600">المعلم: {lesson.teacher?.full_name}</p>
      <p className="text-xs text-slate-500">{formatTime(lesson.scheduled_start_at)} — {formatTime(lesson.scheduled_end_at)}</p>
      <p className="text-xs text-slate-500">{lesson.program?.name}</p>
      {lesson.lesson_type === "trial" && <span className="mt-1 inline-block rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-700">🆕 تجريبية</span>}
      {lesson.lesson_type === "makeup" && <span className="mt-1 inline-block rounded bg-teal-100 px-2 py-0.5 text-xs text-teal-700">↪ تعويضية</span>}
    </div>
  );
}

function DraggableLessonCard({ lesson, onClick }: { lesson: Lesson; onClick: () => void }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: lesson.id,
    data: { type: "lesson", lesson },
  });
  const style = { transform: CSS.Transform.toString(transform), transition, opacity: isDragging ? 0.5 : 1 };
  const config = STATUS_CONFIG[lesson.status] ?? { label: lesson.status, color: "text-slate-700", bg: "bg-slate-100 border-slate-300" };
  return (
    <div ref={setNodeRef} style={style} {...attributes} {...listeners} onClick={onClick}
      className={`cursor-grab rounded-lg border p-2 text-xs shadow-sm hover:shadow-md active:cursor-grabbing ${config.bg}`}>
      <div className="flex items-center justify-between">
        <span className="font-medium text-slate-800">{lesson.student?.full_name}</span>
      </div>
      <p className="mt-1 text-slate-500">{formatTime(lesson.scheduled_start_at)} — {lesson.teacher?.full_name}</p>
      {lesson.lesson_type === "trial" && <span className="mt-0.5 inline-block rounded bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-700">🆕</span>}
      {lesson.lesson_type === "makeup" && <span className="mt-0.5 inline-block rounded bg-teal-100 px-1.5 py-0.5 text-[10px] text-teal-700">↪</span>}
    </div>
  );
}
