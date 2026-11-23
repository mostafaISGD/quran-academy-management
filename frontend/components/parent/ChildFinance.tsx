"use client";

import type { ParentInvoices, ParentPayments } from "@/lib/api";
import { date, dateTime, money } from "@/lib/format";
import {
  Badge,
  Card,
  Empty,
  ErrorBox,
  INVOICE_STATUS_LABEL,
  INVOICE_STATUS_TONE,
  LoadingRows,
  PAYMENT_METHOD_LABEL,
  PAYMENT_STATUS_LABEL,
  PAYMENT_STATUS_TONE,
  labelOf,
} from "./parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — الفواتير والمدفوعات.**
 *
 * ⚠️ القبول: الولي ما يشوفش مال ابنه لو `can_view_finance=false`.
 * الحماية في السيرفر (`requireFinance` → 403)، والتبويب أصلاً
 * مش بيتعرض. المكوّن ده ما بيتنادى إلا والتبويب مسموح.
 *
 * ⭐ «متأخرة» جاية من السيرفر محسوبة من `due_date` (قاعدة 10) —
 * مش حالة مخزّنة في الفاتورة.
 */
export default function ChildFinance({
  invoices,
  payments,
  invoicesLoading,
  paymentsLoading,
  invoicesError,
  paymentsError,
  timeZone,
}: {
  invoices: ParentInvoices | null;
  payments: ParentPayments | null;
  invoicesLoading: boolean;
  paymentsLoading: boolean;
  invoicesError: string | null;
  paymentsError: string | null;
  timeZone?: string;
}) {
  const invoiceRows = invoices?.invoices ?? [];
  const paymentRows = payments?.payments ?? [];

  return (
    <div className="space-y-4">
      <Card title="الفواتير" hint="الأحدث الأول — آخر 100 فاتورة">
        {invoicesLoading && !invoices ? (
          <LoadingRows count={3} />
        ) : invoicesError ? (
          <ErrorBox message={invoicesError} />
        ) : invoiceRows.length === 0 ? (
          <Empty title="مفيش فواتير" hint="هتظهر هنا أول ما تصدر فاتورة." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {invoiceRows.map((invoice) => (
              <li key={invoice.id} className="py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {invoice.invoice_number}
                  </p>
                  <div className="flex flex-wrap items-center gap-1.5">
                    <Badge tone={INVOICE_STATUS_TONE[invoice.status] ?? "slate"}>
                      {labelOf(INVOICE_STATUS_LABEL, invoice.status)}
                    </Badge>
                    {invoice.is_overdue && <Badge tone="red">متأخرة السداد</Badge>}
                  </div>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  صدرت {date(invoice.issue_date)} · تستحق {date(invoice.due_date)}
                </p>

                <dl className="mt-2 grid grid-cols-3 gap-2 text-xs">
                  <div>
                    <dt className="text-slate-500">الإجمالي</dt>
                    <dd className="mt-0.5 font-medium text-slate-800">
                      {money(invoice.total, invoice.currency)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">المدفوع</dt>
                    <dd className="mt-0.5 font-medium text-emerald-700">
                      {money(invoice.paid_amount, invoice.currency)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">المتبقي</dt>
                    <dd
                      className={`mt-0.5 font-medium ${
                        invoice.balance_due > 0 ? "text-red-600" : "text-slate-800"
                      }`}
                    >
                      {money(invoice.balance_due, invoice.currency)}
                    </dd>
                  </div>
                </dl>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="المدفوعات" hint="الأحدث الأول — آخر 100 دفعة">
        {paymentsLoading && !payments ? (
          <LoadingRows count={3} />
        ) : paymentsError ? (
          <ErrorBox message={paymentsError} />
        ) : paymentRows.length === 0 ? (
          <Empty title="مفيش مدفوعات" hint="هتظهر هنا أول ما يتم تسجيل دفعة." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {paymentRows.map((payment) => (
              <li key={payment.id} className="py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {money(payment.amount, payment.currency)}
                  </p>
                  <Badge tone={PAYMENT_STATUS_TONE[payment.status] ?? "slate"}>
                    {labelOf(PAYMENT_STATUS_LABEL, payment.status)}
                  </Badge>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  {[
                    labelOf(PAYMENT_METHOD_LABEL, payment.payment_method),
                    dateTime(payment.paid_at, timeZone),
                    payment.invoice_number ? `فاتورة ${payment.invoice_number}` : null,
                    payment.transaction_reference,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
