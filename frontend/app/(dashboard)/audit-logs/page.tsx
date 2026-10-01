"use client";

import { useEffect, useState } from "react";
import { getAuditLogs, type AuditLog } from "@/lib/api";

export default function AuditLogsPage() {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [filterEntity, setFilterEntity] = useState("");

  function loadLogs() {
    setLoading(true);
    getAuditLogs(filterEntity ? { entity_type: filterEntity } : undefined)
      .then((r) => setLogs(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر التحميل"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadLogs(); }, [filterEntity]);

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">سجل التدقيق</h1>
      <div className="mb-4 flex gap-2">
        <select value={filterEntity} onChange={(e) => setFilterEntity(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">كل الكيانات</option>
          <option value="student">طلاب</option>
          <option value="teacher">معلمين</option>
          <option value="lesson">حصص</option>
          <option value="payment">مدفوعات</option>
          <option value="invoice">فواتير</option>
          <option value="subscription">اشتراكات</option>
          <option value="lead">Leads</option>
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
                  <p className="text-sm font-medium text-slate-800">{log.action}</p>
                  <p className="text-xs text-slate-500">{log.entity_type} — {log.user?.name ?? log.user_id}</p>
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
    </div>
  );
}
