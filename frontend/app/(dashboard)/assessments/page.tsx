"use client";

import { useCallback, useEffect, useState } from "react";
import { getAssessments, createAssessment, updateAssessmentResult, type Assessment } from "@/lib/api";
import Pagination from "@/components/Pagination";

const RESULT_LABEL: Record<string, string> = {
  ready_to_subscribe: "جاهز للاشتراك", needs_follow_up: "يحتاج متابعة", not_suitable: "غير مناسب",
};

const PAGE_SIZE = 100;

export default function AssessmentsPage() {
  const [assessments, setAssessments] = useState<Assessment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [selectedAssessment, setSelectedAssessment] = useState<Assessment | null>(null);

  const [form, setForm] = useState({ lead_id: "", teacher_id: "", scheduled_at: "" });
  const [resultForm, setResultForm] = useState({ reading_score: "", tajweed_score: "", memorization_score: "", recommended_level: "", notes: "", result: "" as Assessment["result"] | "" });

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [resultCounts, setResultCounts] = useState<Record<string, number>>({});

  const loadAssessments = useCallback(async (targetPage = 1) => {
    setLoading(true);
    setError(null);
    try {
      const r = await getAssessments({ per_page: PAGE_SIZE, page: targetPage });
      setAssessments(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
      const c = r.counts as Record<string, unknown> | undefined;
      const rs = c?.result;
      setResultCounts((rs && typeof rs === "object" ? rs : {}) as Record<string, number>);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadAssessments(1); }, [loadAssessments]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadAssessments(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try { await createAssessment({ lead_id: Number(form.lead_id), teacher_id: Number(form.teacher_id), scheduled_at: form.scheduled_at }); setShowForm(false); loadAssessments(1); }
    catch (err) { setError(err instanceof Error ? err.message : "فشل إنشاء التقييم"); }
  }

  async function handleResult(e: React.FormEvent) {
    e.preventDefault();
    if (!selectedAssessment) return;
    try {
      await updateAssessmentResult(selectedAssessment.id, {
        reading_score: resultForm.reading_score ? Number(resultForm.reading_score) : undefined,
        tajweed_score: resultForm.tajweed_score ? Number(resultForm.tajweed_score) : undefined,
        memorization_score: resultForm.memorization_score ? Number(resultForm.memorization_score) : undefined,
        recommended_level: resultForm.recommended_level || undefined,
        notes: resultForm.notes || undefined,
        result: resultForm.result as Assessment["result"],
      });
      setSelectedAssessment(null);
      setResultForm({ reading_score: "", tajweed_score: "", memorization_score: "", recommended_level: "", notes: "", result: "" });
      loadAssessments(page);
    } catch (err) { setError(err instanceof Error ? err.message : "فشل حفظ النتيجة"); }
  }

  return (
    <div>
      <div className="mb-5 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">التقييمات التجريبية</h1>
        <button onClick={() => setShowForm(!showForm)} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">{showForm ? "إخفاء" : "+ حجز تقييم"}</button>
      </div>

      {/* إحصائيات — من الداتابيز على كل التقييمات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">إجمالي التقييمات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">جاهز للاشتراك</p>
          <p className="mt-1 text-xl font-bold text-green-800">{resultCounts.ready_to_subscribe ?? 0}</p>
        </div>
        <div className="rounded-xl bg-amber-50 p-4 text-center">
          <p className="text-xs text-amber-600">يحتاج متابعة</p>
          <p className="mt-1 text-xl font-bold text-amber-800">{resultCounts.needs_follow_up ?? 0}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">غير مناسب</p>
          <p className="mt-1 text-xl font-bold text-red-800">{resultCounts.not_suitable ?? 0}</p>
        </div>
      </div>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">حجز تقييم</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-1 gap-3">
            <input placeholder="معرّف الـ Lead" value={form.lead_id} onChange={(e) => setForm({ ...form, lead_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="معرّف المعلم" value={form.teacher_id} onChange={(e) => setForm({ ...form, teacher_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حجز</button>
          </form>
        </div>
      )}
      {!loading && !error && (
        <div className="space-y-3">
          {assessments.length === 0 && <p className="text-slate-400">لا توجد تقييمات</p>}
          {assessments.map((a) => (
            <div key={a.id} className="rounded-xl border border-slate-200 bg-white p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="font-medium text-slate-800">{a.lead?.full_name ?? `Lead ${a.lead_id}`}</p>
                  <p className="text-sm text-slate-500">المعلم: {a.teacher?.full_name ?? a.teacher_id} — {new Date(a.scheduled_at).toLocaleString("ar-EG")}</p>
                  <div className="mt-1 flex gap-3 text-xs text-slate-500">
                    <span>قراءة: {a.reading_score ?? "—"}</span>
                    <span>تجويد: {a.tajweed_score ?? "—"}</span>
                    <span>تحفيظ: {a.memorization_score ?? "—"}</span>
                  </div>
                  {a.result && <span className={`mt-1 inline-block rounded-full px-2 py-1 text-xs ${a.result === "ready_to_subscribe" ? "bg-green-100 text-green-700" : a.result === "needs_follow_up" ? "bg-amber-100 text-amber-700" : "bg-red-100 text-red-700"}`}>{RESULT_LABEL[a.result]}</span>}
                </div>
                {!a.result && <button onClick={() => setSelectedAssessment(a)} className="rounded-lg bg-blue-100 px-3 py-2 text-sm text-blue-700 hover:bg-blue-200">تسجيل النتيجة</button>}
              </div>
            </div>
          ))}
        </div>
      )}
      {selectedAssessment && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-xl bg-white p-6">
            <h3 className="mb-4 text-lg font-semibold text-slate-800">نتيجة التقييم</h3>
            <form onSubmit={handleResult} className="space-y-3">
              <div className="grid grid-cols-3 gap-2">
                <input type="number" min="1" max="5" placeholder="قراءة" value={resultForm.reading_score} onChange={(e) => setResultForm({ ...resultForm, reading_score: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <input type="number" min="1" max="5" placeholder="تجويد" value={resultForm.tajweed_score} onChange={(e) => setResultForm({ ...resultForm, tajweed_score: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                <input type="number" min="1" max="5" placeholder="تحفيظ" value={resultForm.memorization_score} onChange={(e) => setResultForm({ ...resultForm, memorization_score: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              </div>
              <input placeholder="المستوى المقترح" value={resultForm.recommended_level} onChange={(e) => setResultForm({ ...resultForm, recommended_level: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <textarea placeholder="ملاحظات" value={resultForm.notes} onChange={(e) => setResultForm({ ...resultForm, notes: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" rows={2} />
              <select value={resultForm.result ?? ""} onChange={(e) => setResultForm({ ...resultForm, result: e.target.value as Assessment["result"] })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required>
                <option value="">النتيجة</option>
                {Object.entries(RESULT_LABEL).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
              <div className="flex gap-2">
                <button type="submit" className="flex-1 rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
                <button type="button" onClick={() => setSelectedAssessment(null)} className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="تقييم"
      />
    </div>
  );
}
