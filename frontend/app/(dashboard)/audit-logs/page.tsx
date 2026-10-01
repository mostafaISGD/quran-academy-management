"use client";

import { useCallback, useEffect, useState } from "react";
import { getAuditLogs, type AuditLog } from "@/lib/api";
import Pagination from "@/components/Pagination";

const PAGE_SIZE = 100;

const ACTION_LABEL: Record<string, string> = {
  create: "إنشاء", update: "تعديل", delete: "حذف",
  login: "تسجيل دخول", logout: "تسجيل خروج", export: "تصدير",
};

const ENTITY_LABEL: Record<string, string> = {
  student: "طالب", teacher: "معلم", invoice: "فاتورة", payment: "دفعة",
  lesson: "حصة", subscription: "اشتراك", program: "برنامج", lead: "عميل محتمل",
  student_phone: "رقم هاتف", parent: "ولي أمر", expense: "مصروف",
};

export default function AuditLogsPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filterEntity, setFilterEntity] = useState("");
  const [filterAction, setFilterAction] = useState("");

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [actionCounts, setActionCounts] = useState<Record<string, number>>({});

  const loadLogs = useCallback(async (targetPage = 1, entity = "", action = "") => {
    setLoading(true);
    setError(null);
    try {
      const r = await getAuditLogs({
        per_page: PAGE_SIZE,
        page: targetPage,
        entity_type: entity || undefined,
        action: action || undefined,
      });
      setLogs(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
      const c = r.counts as Record<string, unknown> | undefined;
      const a = c?.action;
      setActionCounts((a && typeof a === "object" ? a : {}) as Record<string, number>);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر التحميل");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    setPage(1);
    loadLogs(1, filterEntity, filterAction);
  }, [loadLogs, filterEntity, filterAction]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadLogs(target, filterEntity, filterAction);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">سجل التدقيق</h1>

      {/* إحصائيات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">إجمالي العمليات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">إنشاء</p>
          <p className="mt-1 text-xl font-bold text-green-800">{actionCounts.create ?? 0}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">تعديل</p>
          <p className="mt-1 text-xl font-bold text-blue-800">{actionCounts.update ?? 0}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">حذف</p>
          <p className="mt-1 text-xl font-bold text-red-800">{actionCounts.delete ?? 0}</p>
        </div>
      </div>

      <div className="mb-4 flex flex-wrap gap-2">
        <select value={filterEntity} onChange={(e) => setFilterEntity(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">كل الكيانات</option>
          {Object.entries(ENTITY_LABEL).map(([k, v]) => (
            <option key={k} value={k}>{v}</option>
          ))}
        </select>
        <select value={filterAction} onChange={(e) => setFilterAction(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">كل الإجراءات</option>
          {Object.entries(ACTION_LABEL).map(([k, v]) => (
            <option key={k} value={k}>{v} ({actionCounts[k] ?? 0})</option>
          ))}
        </select>
      </div>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {!loading && !error && (
        <div className="space-y-2">
          {logs.length === 0 && <p className="text-slate-400">لا توجد سجلات</p>}
          {logs.map((log) => (
            <div key={log.id} className="rounded-xl border border-slate-200 bg-white p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-medium text-slate-800">
                    {ACTION_LABEL[log.action] ?? log.action}
                  </p>
                  <p className="text-xs text-slate-500">
                    {ENTITY_LABEL[log.entity_type] ?? log.entity_type} —{" "}
                    {log.user?.name ?? `مستخدم ${log.user_id}`}
                  </p>
                </div>
                <span className="text-xs text-slate-400">{new Date(log.created_at).toLocaleString("ar-EG")}</span>
              </div>
              {(log.old_value || log.new_value) && (
                <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                  {log.old_value && (
                    <div className="rounded bg-red-50 p-2">
                      <p className="text-red-600">القديم:</p>
                      <pre className="mt-1 overflow-x-auto text-red-700">{JSON.stringify(log.old_value, null, 2)}</pre>
                    </div>
                  )}
                  {log.new_value && (
                    <div className="rounded bg-green-50 p-2">
                      <p className="text-green-600">الجديد:</p>
                      <pre className="mt-1 overflow-x-auto text-green-700">{JSON.stringify(log.new_value, null, 2)}</pre>
                    </div>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="عملية"
      />
    </div>
  );
}
