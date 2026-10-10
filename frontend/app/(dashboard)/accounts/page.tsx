"use client";

import { useCallback, useMemo, useState } from "react";
import { usePage } from "@/lib/use-page";
import Pagination from "@/components/Pagination";
import {
  createFinancialAccount,
  getAccountMovements,
  getFinancialAccounts,
  getPeriodLocks,
  lockPeriod,
  reverseMovement,
  transferBetweenAccounts,
  unlockPeriod,
  type AccountMovement,
  type AccountsResponse,
  type FinancialAccount,
  type MovementsResponse,
  type PeriodLock,
} from "@/lib/api";
import { date, money, num, text } from "@/lib/format";

/**
 * ⭐⭐ الخزائن ودفتر الحركات.
 *
 * ⭐ **الصفحة دي بتجيب إيه للمستخدم؟**
 *
 * سؤال واحد: «**معانا فلوس كام؟**» — وده مافيش مكان في
 * النظام كان بيجاوب عليه.
 *
 * ⭐ **القرارات المعروضة على الشاشة:**
 *
 *  ① **التحويل بين حسابين** — فصّلها تحت، مش income/expense.
 *  ② **العكس** — للمدير بس (السبب إجباري)، والأصلي ما بيتلمسش.
 *  ③ **قفل الفترات** — المحاسب بيقفل، المدير بس بيفتح.
 */

const PAGE_SIZE = 50;

/**
 * ⭐ نبرة ألوان الفئة.
 *
 * ⚠️ `transfer_*` **مقصود** إنه بنفس درجة `income`/`expense` في
 * اللون — لأن التحويل مش إيراد. التمييز في **العمود** (تحويل
 * وارد/صادر)، مش في اللون. لو لوّناه بلون تاني، الأدمن
 * يفتكره دخل.
 */
const CATEGORY_TONE: Record<string, string> = {
  income: "bg-green-100 text-green-700",
  expense: "bg-red-100 text-red-700",
  teacher_payment: "bg-orange-100 text-orange-700",
  refund: "bg-rose-100 text-rose-700",
  transfer_in: "bg-blue-100 text-blue-700",
  transfer_out: "bg-blue-100 text-blue-700",
};

const TYPE_OPTIONS: { value: FinancialAccount["type"]; label: string }[] = [
  { value: "cash", label: "نقدية" },
  { value: "bank", label: "بنك" },
  { value: "wallet", label: "محفظة" },
  { value: "gateway", label: "بوابة دفع" },
  { value: "other", label: "أخرى" },
];

const SCOPE_OPTIONS: { value: PeriodLock["scope"]; label: string }[] = [
  { value: "all", label: "كل الحسابات" },
  { value: "account", label: "حساب واحد" },
  { value: "expenses", label: "المصروفات بس" },
  { value: "payroll", label: "المرتبات بس" },
];

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
const BTN_PRIMARY =
  "rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50";
const BTN_GHOST =
  "rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50";

/** ⭐ أول تاريخ في الشهر الحالي بصيغة `YYYY-MM-DD` */
function monthStart(): string {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
}

/** ⭐ آخر تاريخ في الشهر الحالي */
function monthEnd(): string {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth() + 1, 0).toISOString().slice(0, 10);
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

type Modal = "transfer" | "account" | "lock" | null;

export default function AccountsPage() {
  const [notice, setNotice] = useState<string | null>(null);
  const [modal, setModal] = useState<Modal>(null);

  // ═══ الفلاتر ═══
  const [from, setFrom] = useState(monthStart);
  const [to, setTo] = useState(monthEnd);
  const [accountFilter, setAccountFilter] = useState<number | "">("");
  const [directionFilter, setDirectionFilter] = useState<"" | "in" | "out">("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [page, setPage] = useState(1);

  /**
   * ⭐ الجلب عن طريق `usePage` بدل `useEffect`.
   *
   * الـ fetcher بيرجّع **الثلاث مصادر مع بعض** في وعد واحد —
   * `usePage` شغّال مع أي `Promise`، فمش لازم نغيّره.
   *
   * ⚠️ `load` لسه موجود كـ `useCallback` لأن `refresh` (تحت)
   * بتناديه بعد العمليات. هنا بنلفّه في `usePage` والـ `reload`
   * بيرجّع النتيجة.
   *
   * @see usePage
   */
  const fetchAccounts = useCallback(async () => {
    const [accs, moves, lockRows] = await Promise.all([
      getFinancialAccounts({ from, to }),
      getAccountMovements({
        account_id: accountFilter === "" ? undefined : accountFilter,
        direction: directionFilter || undefined,
        category: categoryFilter || undefined,
        from,
        to,
        page,
        per_page: PAGE_SIZE,
      }),
      getPeriodLocks(),
    ]);
    return {
      accounts: accs.data,
      meta: accs.meta,
      movements: moves.data,
      movementMeta: moves.meta,
      locks: lockRows.data,
    };
  }, [from, to, accountFilter, directionFilter, categoryFilter, page]);

  type AccountsBundle = Awaited<ReturnType<typeof fetchAccounts>>;

  /**
   * ⭐ القيمة الابتدائية: بيانات فارغة **بأنواعها الصح**.
   *
   * الأرقام الصفرية هنا مقصودة — المستخدم لازم يشوف الصفحة
   * فوراً بدل ما يستنى الـ fetch يخلص.
   */
  const EMPTY: AccountsBundle = {
    accounts: [],
    meta: { total: 0, by_currency: {} },
    movements: [],
    movementMeta: {
      current_page: 1,
      last_page: 1,
      per_page: 0,
      total: 0,
      totals: { in: 0, out: 0 },
    },
    locks: [],
  };

  const {
    data: bundle,
    loading,
    error,
    reload: load,
  } = usePage<AccountsBundle>(
    `accounts:${from}:${to}:${accountFilter}:${directionFilter}:${categoryFilter}:${page}`,
    fetchAccounts,
    { initial: EMPTY },
  );

  const accounts = bundle.accounts;
  const meta = bundle.meta;
  const movements = bundle.movements;
  const movementMeta = bundle.movementMeta;
  const locks = bundle.locks;

  /** ⭐⭐ أي فترة مقفولة دلوقتي؟ — عشان نبيّن القفل على الشاشة */
  const activeLocks = useMemo(() => locks.filter((l) => l.is_active), [locks]);

  const refresh = async (message: string) => {
    setNotice(message);
    await load();
  };

  // ═══ أدوات مساعدة للعرض ═══

  /** ⭐⭐ subclasses: الأيقونة قبل المبلغ، والاتجاه بلون */
  const amountLine = (m: AccountMovement) => {
    const positive = m.direction === "in";
    // ⭐ الحركات المعلّقة بتتعرض مشفّتة — عشان形的 المستخدم يفهم
    // إن المبلغ ده **لسه** في الخزينة
    const muted = m.status === "pending";

    return (
      <span className={muted ? "opacity-55" : ""}>
        <span className={positive ? "text-emerald-700" : "text-red-700"}>
          {positive ? "+" : "−"} {money(m.amount, m.currency)}
        </span>
        {muted && (
          <span className="ms-1 text-[11px] font-normal text-amber-700">(معلّقة)</span>
        )}
      </span>
    );
  };

  const currentAccount = accounts.find((a) => a.id === accountFilter);

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">الخزائن ودفتر الحركات</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            كل فلوس الأكاديمية — وين رايحة ومن فين جاية
          </p>
        </div>

        <div className="flex gap-2">
          <button className={BTN_PRIMARY} onClick={() => setModal("transfer")}>
            تحويل بين حسابين
          </button>
          <button className={BTN_GHOST} onClick={() => setModal("account")}>
            خزينة جديدة
          </button>
          <button className={BTN_GHOST} onClick={() => setModal("lock")}>
            قفل فترة
          </button>
        </div>
      </div>

      {notice && (
        <div className="mb-4 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
          <span>{notice}</span>
          <button onClick={() => setNotice(null)} className="text-emerald-600 hover:underline">
            ✕
          </button>
        </div>
      )}

      {error && (
        <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700">
          {error}
        </div>
      )}

      {/* ⭐⭐ تنبيه القفل — لازم يبان قبل الأرقام */}
      {activeLocks.length > 0 && (
        <div className="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3">
          <p className="mb-1 text-sm font-medium text-amber-900">
            🔒 فيه فترات مقفولة — الكتابة فيها ممنوعة
          </p>
          <ul className="space-y-1 text-xs text-amber-800">
            {activeLocks.map((l) => (
              <li key={l.id}>
                <LockRow lock={l} onUnlock={refresh} />
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* ═══ فلاتر الفترة ═══ */}
      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3">
        <div>
          <label className="mb-1 block text-xs text-slate-500">من</label>
          <input
            type="date"
            value={from}
            onChange={(e) => {
              setFrom(e.target.value);
              setPage(1);
            }}
            className={FIELD}
          />
        </div>
        <div>
          <label className="mb-1 block text-xs text-slate-500">لـ</label>
          <input
            type="date"
            value={to}
            onChange={(e) => {
              setTo(e.target.value);
              setPage(1);
            }}
            className={FIELD}
          />
        </div>
        <button
          className="rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-600 hover:bg-slate-50"
          onClick={() => {
            setFrom(monthStart());
            setTo(monthEnd());
            setPage(1);
          }}
        >
          الشهر ده
        </button>

        <div className="ms-auto text-xs text-slate-500">
          {loading ? "بيحسب…" : <>الفترة من {date(from)} لحد {date(to)}</>}
        </div>
      </div>

      {/* ═══ الحسابات ═══ */}
      <h2 className="mb-3 text-sm font-semibold text-slate-700">
        الحسابات ({accounts.length})
      </h2>

      {accounts.length === 0 && !loading ? (
        <p className="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
          مفيش حسابات مالية. اعمل «خزينة جديدة» عشان تقدر تسجّل التحصيل والمصروفات.
        </p>
      ) : (
        <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {accounts.map((a) => {
            const selected = accountFilter === a.id;

            return (
              <button
                key={a.id}
                onClick={() => {
                  setAccountFilter(selected ? "" : a.id);
                  setPage(1);
                }}
                className={`rounded-xl border p-4 text-start transition ${
                  selected
                    ? "border-emerald-500 bg-emerald-50 ring-1 ring-emerald-300"
                    : "border-slate-200 bg-white hover:border-slate-300"
                } ${a.status === "inactive" ? "opacity-60" : ""}`}
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-slate-800">{a.name}</p>
                    <p className="text-xs text-slate-500">
                      {a.type_label}
                      {a.status === "inactive" && " · موقوف"}
                    </p>
                  </div>
                  {a.pending_count > 0 && (
                    <span
                      className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700"
                      title="حركات مسجّلة بس لسه معتمدة"
                    >
                      {a.pending_count} معلّقة
                    </span>
                  )}
                </div>

                <p className="mt-3 text-xl font-bold text-slate-900">
                  {money(a.balance, a.currency)}
                </p>

                <div className="mt-2 flex gap-3 text-[11px]">
                  <span className="text-emerald-700">+ {num(a.total_in)} داخل الفترة</span>
                  <span className="text-red-700">− {num(a.total_out)} خارج الفترة</span>
                </div>
              </button>
            );
          })}
        </div>
      )}

      {/* ⭐ الإجماليات مقسومة عملة عملة */}
      {meta && Object.keys(meta.by_currency).length > 1 && (
        <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
          ⭐ فيه أكتر من عملة، فالإجماليات متحسبين <b>لكل عملة لوحدها</b> — مش رقم
          واحد، لأن التحويل بين العملات بياخد سعر صرف بيتغيّر.
          {Object.entries(meta.by_currency).map(([cur, total]) => (
            <span key={cur} className="me-3 inline-block">
              {cur}: <b>{money(total, cur)}</b>
            </span>
          ))}
        </div>
      )}

      {/* ═══ دفتر الحركات ═══ */}
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-sm font-semibold text-slate-700">
          دفتر الحركات
          {currentAccount && (
            <span className="ms-2 font-normal text-slate-500">— {currentAccount.name}</span>
          )}
        </h2>

        <div className="flex flex-wrap gap-2">
          <select
            value={directionFilter}
            onChange={(e) => {
              setDirectionFilter(e.target.value as "" | "in" | "out");
              setPage(1);
            }}
            className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
          >
            <option value="">كل الاتجاهات</option>
            <option value="in">داخل فقط</option>
            <option value="out">خارج فقط</option>
          </select>

          <select
            value={categoryFilter}
            onChange={(e) => {
              setCategoryFilter(e.target.value);
              setPage(1);
            }}
            className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs"
          >
            <option value="">كل الفئات</option>
            <option value="income">تحصيل</option>
            <option value="expense">مصروف</option>
            <option value="teacher_payment">دفعة لمعلم</option>
            <option value="refund">استرداد</option>
            <option value="transfer_in">تحويل وارد</option>
            <option value="transfer_out">تحويل صادر</option>
          </select>

          {accountFilter !== "" && (
            <button
              className="rounded-lg border border-slate-300 px-2 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
              onClick={() => {
                setAccountFilter("");
                setPage(1);
              }}
            >
              كل الحسابات
            </button>
          )}
        </div>
      </div>

      {/* ⭐ إجماليات الفترة — من السيرفر مش من الصفحة */}
      {movementMeta && (
        <div className="mb-3 flex gap-4 text-xs">
          <span className="text-emerald-700">
            ⬆ داخل الفترة: <b>{num(movementMeta.totals.in)}</b>
          </span>
          <span className="text-red-700">
            ⬇ خارج الفترة: <b>{num(movementMeta.totals.out)}</b>
          </span>
          <span className="text-slate-500">
            الصافي: <b>{num(movementMeta.totals.in - movementMeta.totals.out)}</b>
          </span>
        </div>
      )}

      {movements.length === 0 && !loading ? (
        <p className="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
          مفيش حركات في الفترة دي.
        </p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-xs text-slate-600">
              <tr>
                <th className="p-3 text-start font-medium">التاريخ</th>
                <th className="p-3 text-start font-medium">الحساب</th>
                <th className="p-3 text-start font-medium">البند</th>
                <th className="p-3 text-start font-medium">الفئة</th>
                <th className="p-3 text-start font-medium">المبلغ</th>
                <th className="p-3 text-start font-medium">ملاحظة</th>
                <th className="p-3 text-end font-medium" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {movements.map((m) => (
                <tr key={m.id} className={m.status === "pending" ? "opacity-70" : ""}>
                  <td className="whitespace-nowrap p-3 text-slate-600">{date(m.movement_date)}</td>
                  <td className="whitespace-nowrap p-3 text-slate-700">
                    {text(m.account?.name)}
                  </td>
                  <td className="p-3 text-slate-700">
                    {m.is_reversed ? (
                      <span className="text-slate-400 line-through">{m.description}</span>
                    ) : (
                      m.description
                    )}
                    {m.has_reversal && (
                      <span className="ms-2 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">
                        اتعكست
                      </span>
                    )}
                  </td>
                  <td className="p-3">
                    <span
                      className={`rounded-full px-2 py-0.5 text-[11px] ${
                        CATEGORY_TONE[m.category] ?? "bg-slate-100 text-slate-600"
                      }`}
                    >
                      {m.category_label}
                    </span>
                  </td>
                  <td className="whitespace-nowrap p-3 font-medium">{amountLine(m)}</td>
                  <td className="max-w-[16rem] truncate p-3 text-xs text-slate-500">
                    {text(m.notes)}
                  </td>
                  <td className="p-3 text-end">
                    {!m.is_reversed && (
                      <ReverseButton movement={m} onDone={refresh} />
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {movementMeta && movementMeta.last_page > 1 && (
        <div className="mt-4">
          <Pagination
            page={movementMeta.current_page}
            lastPage={movementMeta.last_page}
            total={movementMeta.total}
            perPage={movementMeta.per_page}
            onChange={setPage}
            loading={loading}
            itemLabel="حركة"
          />
        </div>
      )}

      {/* ═══ النوافذ ═══ */}
      {modal === "transfer" && (
        <TransferModal
          accounts={accounts.filter((a) => a.status === "active")}
          onClose={() => setModal(null)}
          onDone={async (msg) => {
            setModal(null);
            await refresh(msg);
          }}
        />
      )}

      {modal === "account" && (
        <AccountModal
          onClose={() => setModal(null)}
          onDone={async (msg) => {
            setModal(null);
            await refresh(msg);
          }}
        />
      )}

      {modal === "lock" && (
        <LockModal
          accounts={accounts.filter((a) => a.status === "active")}
          onClose={() => setModal(null)}
          onDone={async (msg) => {
            setModal(null);
            await refresh(msg);
          }}
        />
      )}
    </div>
  );
}

/* ============================================================
   عكس حركة — المدير بس
   ============================================================ */

function ReverseButton({
  movement,
  onDone,
}: {
  movement: AccountMovement;
  onDone: (msg: string) => Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setErr(null);
    try {
      await reverseMovement(movement.id, reason);
      setOpen(false);
      setReason("");
      await onDone("اتعكس السطر — السطر الأصلي اتسجل زي ما هو");
    } catch (e) {
      setErr(e instanceof Error ? e.message : "العكس فشل");
    } finally {
      setBusy(false);
    }
  };

  if (!open) {
    return (
      <button
        className="text-xs text-slate-400 hover:text-red-600"
        onClick={() => setOpen(true)}
      >
        عكس
      </button>
    );
  }

  return (
    <div className="min-w-[16rem] rounded-lg border border-red-200 bg-red-50 p-2 text-start">
      <p className="mb-1 text-[11px] text-red-800">
        ⭐ السبب **إجباري** — من غيره محدش هيعرف ليه اتصحّح الرقم.
      </p>
      <textarea
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        rows={2}
        placeholder="مثال: الدفعة اتسجلت على الطالب الغلط"
        className="w-full rounded border border-red-200 px-2 py-1 text-xs focus:outline-none"
      />
      {err && <p className="mt-1 text-[11px] text-red-700">{err}</p>}
      <div className="mt-2 flex gap-2">
        <button
          className="rounded bg-red-600 px-2 py-1 text-[11px] text-white disabled:opacity-50"
          disabled={busy || reason.trim() === ""}
          onClick={submit}
        >
          {busy ? "…" : "أكّد العكس"}
        </button>
        <button
          className="rounded border border-slate-300 px-2 py-1 text-[11px]"
          onClick={() => setOpen(false)}
        >
          إلغاء
        </button>
      </div>
    </div>
  );
}

/* ============================================================
   تحويل بين حسابين
   ============================================================ */

function TransferModal({
  accounts,
  onClose,
  onDone,
}: {
  accounts: FinancialAccount[];
  onClose: () => void;
  onDone: (msg: string) => Promise<void>;
}) {
  const [fromId, setFromId] = useState<number | "">(accounts[0]?.id ?? "");
  const [toId, setToId] = useState<number | "">(accounts[1]?.id ?? accounts[0]?.id ?? "");
  const [amount, setAmount] = useState("");
  const [transferDate, setTransferDate] = useState(today);
  const [reference, setReference] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const from = accounts.find((a) => a.id === fromId);
  const to = accounts.find((a) => a.id === toId);
  const value = Number(amount);
  const amountNum = Number.isFinite(value) ? value : 0;

  // ⭐ تحذير الرصيد **قبل** الإرسال — مش بعده
  const short = from ? amountNum > from.balance : false;
  const sameAccount = fromId !== "" && fromId === toId;

  const submit = async () => {
    setBusy(true);
    setErr(null);
    try {
      const res = await transferBetweenAccounts({
        from_account_id: Number(fromId),
        to_account_id: Number(toId),
        amount: amountNum,
        transfer_date: transferDate,
        reference: reference || undefined,
      });
      await onDone(res.message);
    } catch (e) {
      setErr(e instanceof Error ? e.message : "التحويل فشل");
    } finally {
      setBusy(false);
    }
  };

  return (
    <ModalFrame title="تحويل بين حسابين" onClose={onClose}>
      <p className="mb-4 rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-800">
        ⭐ التحويل <b>مش</b> إيراد ومش مصروف — فلوس بتتحرك جوّه
        الأكاديمية. فمش هيظهر في تقرير الدخل ولا المصروفات.
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className="mb-1 block text-xs text-slate-600">من حساب</label>
          <select
            value={fromId}
            onChange={(e) => setFromId(Number(e.target.value))}
            className={FIELD}
          >
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name} — {money(a.balance, a.currency)}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="mb-1 block text-xs text-slate-600">إلى حساب</label>
          <select
            value={toId}
            onChange={(e) => setToId(Number(e.target.value))}
            className={FIELD}
          >
            {accounts.map((a) => (
              <option key={a.id} value={a.id}>
                {a.name}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="mb-1 block text-xs text-slate-600">المبلغ</label>
          <input
            type="number"
            step="0.01"
            min="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            className={FIELD}
          />
          {from && (
            <p className="mt-1 text-[11px] text-slate-500">
              المتاح: {money(from.balance, from.currency)}
            </p>
          )}
        </div>

        <div>
          <label className="mb-1 block text-xs text-slate-600">تاريخ التحويل</label>
          <input
            type="date"
            value={transferDate}
            onChange={(e) => setTransferDate(e.target.value)}
            className={FIELD}
          />
        </div>

        <div className="sm:col-span-2">
          <label className="mb-1 block text-xs text-slate-600">مرجع (رقم الإيصال)</label>
          <input
            value={reference}
            onChange={(e) => setReference(e.target.value)}
            className={FIELD}
          />
        </div>
      </div>

      {/* ⭐⭐ تحذيرات قبل الإرسال — دي بتوفّر 422 */}
      {sameAccount && (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
          المصدر والوجهة لازم يكونوا حسابين مختلفين.
        </p>
      )}
      {short && (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
          ⭐ المبلغ أكبر من رصيد {from?.name}. ماينفعش نتحوّل فلوس
          مش موجودة.
        </p>
      )}

      {/* ⭐ رصيد الوجهة **بعد** التحويل — عشان يتأكد قبل ما يضغط */}
      {to && from && !sameAccount && !short && amountNum > 0 && (
        <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700">
          رصيد <b>{to.name}</b> هيبقى{" "}
          <b className="text-emerald-700">
            {money(to.balance + amountNum, to.currency)}
          </b>{" "}
          بعد التحويل
        </p>
      )}
      {err && (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>
      )}

      <div className="mt-5 flex gap-2">
        <button
          className={BTN_PRIMARY}
          disabled={busy || sameAccount || short || amountNum <= 0 || fromId === "" || toId === ""}
          onClick={submit}
        >
          {busy ? "…" : "نفّذ التحويل"}
        </button>
        <button className={BTN_GHOST} onClick={onClose}>
          إلغاء
        </button>
      </div>
    </ModalFrame>
  );
}

/* ============================================================
   خزينة جديدة
   ============================================================ */

function AccountModal({
  onClose,
  onDone,
}: {
  onClose: () => void;
  onDone: (msg: string) => Promise<void>;
}) {
  const [name, setName] = useState("");
  const [type, setType] = useState<FinancialAccount["type"]>("cash");
  const [opening, setOpening] = useState("0");
  const [notes, setNotes] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setErr(null);
    try {
      const res = await createFinancialAccount({
        name,
        type,
        currency: "EGP",
        opening_balance: Number(opening) || 0,
        notes: notes || undefined,
      });
      await onDone(res.message);
    } catch (e) {
      setErr(e instanceof Error ? e.message : "إنشاء الحساب فشل");
    } finally {
      setBusy(false);
    }
  };

  return (
    <ModalFrame title="خزينة جديدة" onClose={onClose}>
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <label className="mb-1 block text-xs text-slate-600">اسم الحساب</label>
          <input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="مثال: الخزينة الرئيسية"
            className={FIELD}
          />
        </div>

        <div>
          <label className="mb-1 block text-xs text-slate-600">النوع</label>
          <select
            value={type}
            onChange={(e) => setType(e.target.value as FinancialAccount["type"])}
            className={FIELD}
          >
            {TYPE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </div>

        <div>
          <label className="mb-1 block text-xs text-slate-600">رصيد افتتاحي</label>
          <input
            type="number"
            step="0.01"
            value={opening}
            onChange={(e) => setOpening(e.target.value)}
            className={FIELD}
          />
          <p className="mt-1 text-[11px] text-amber-700">
            ⭐ ده رصيد <b>أول يوم</b> — مش رصيد دلوقتي. ومش هينفع
            تغيّره بعدين.
          </p>
        </div>

        <div className="sm:col-span-2">
          <label className="mb-1 block text-xs text-slate-600">ملاحظات</label>
          <input
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className={FIELD}
          />
        </div>
      </div>

      {err && (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>
      )}

      <div className="mt-5 flex gap-2">
        <button
          className={BTN_PRIMARY}
          disabled={busy || name.trim() === ""}
          onClick={submit}
        >
          {busy ? "…" : "اعمل الحساب"}
        </button>
        <button className={BTN_GHOST} onClick={onClose}>
          إلغاء
        </button>
      </div>
    </ModalFrame>
  );
}

/* ============================================================
   قفل فترة
   ============================================================ */

function LockModal({
  accounts,
  onClose,
  onDone,
}: {
  accounts: FinancialAccount[];
  onClose: () => void;
  onDone: (msg: string) => Promise<void>;
}) {
  const [scope, setScope] = useState<PeriodLock["scope"]>("all");
  const [accountId, setAccountId] = useState<number | "">("");
  const [start, setStart] = useState(monthStart);
  const [end, setEnd] = useState(monthEnd);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setErr(null);
    try {
      const res = await lockPeriod({
        start,
        end,
        scope,
        account_id: scope === "account" && accountId !== "" ? Number(accountId) : undefined,
        reason: reason || undefined,
      });
      await onDone(res.message);
    } catch (e) {
      setErr(e instanceof Error ? e.message : "القفل فشل");
    } finally {
      setBusy(false);
    }
  };

  return (
    <ModalFrame title="قفل فترة" onClose={onClose}>
      <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
        ⭐ القفل بيمنع <b>الكتابة</b> في الفترة دي — مش استلام
        الفلوس. فلو حد دفع وهو معلق، الدفعة بتتحفظ وبيرجع تحذير.
      </p>

      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label className="mb-1 block text-xs text-slate-600">النطاق</label>
          <select
            value={scope}
            onChange={(e) => setScope(e.target.value as PeriodLock["scope"])}
            className={FIELD}
          >
            {SCOPE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </div>

        {scope === "account" && (
          <div>
            <label className="mb-1 block text-xs text-slate-600">الحساب</label>
            <select
              value={accountId}
              onChange={(e) => setAccountId(Number(e.target.value))}
              className={FIELD}
            >
              <option value="">اختار…</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name}
                </option>
              ))}
            </select>
          </div>
        )}

        <div>
          <label className="mb-1 block text-xs text-slate-600">من</label>
          <input
            type="date"
            value={start}
            onChange={(e) => setStart(e.target.value)}
            className={FIELD}
          />
        </div>

        <div>
          <label className="mb-1 block text-xs text-slate-600">لـ</label>
          <input
            type="date"
            value={end}
            onChange={(e) => setEnd(e.target.value)}
            className={FIELD}
          />
        </div>

        <div className="sm:col-span-2">
          <label className="mb-1 block text-xs text-slate-600">السبب</label>
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="مثال: تم اعتماد مرتبات الشهر"
            className={FIELD}
          />
          <p className="mt-1 text-[11px] text-slate-500">
            ⭐ السبب بيظهر للمستخدم لو حاول يعدّل في الفترة دي — فاكتبه
            يفيد.
          </p>
        </div>
      </div>

      {err && (
        <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{err}</p>
      )}

      <div className="mt-5 flex gap-2">
        <button
          className={BTN_PRIMARY}
          disabled={busy || (scope === "account" && accountId === "")}
          onClick={submit}
        >
          {busy ? "…" : "اقفل الفترة"}
        </button>
        <button className={BTN_GHOST} onClick={onClose}>
          إلغاء
        </button>
      </div>
    </ModalFrame>
  );
}

/* ============================================================
   سطر القفل + زرار الفتح (للمدير بس)
   ============================================================ */

function LockRow({ lock, onUnlock }: { lock: PeriodLock; onUnlock: (msg: string) => Promise<void> }) {
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    setBusy(true);
    setErr(null);
    try {
      const res = await unlockPeriod(lock.id, reason);
      setReason("");
      await onUnlock(res.message);
    } catch (e) {
      setErr(e instanceof Error ? e.message : "الفتح فشل");
    } finally {
      setBusy(false);
    }
  };

  return (
    <div>
      <span className="font-medium">{text(lock.label, "فترة مقفلة")}</span>
      {lock.reason && <span className="ms-2">— {lock.reason}</span>}
      {lock.locked_by && <span className="ms-2 text-slate-600">قفلها {lock.locked_by}</span>}

      <details className="mt-1">
        <summary className="cursor-pointer text-[11px] text-amber-700 hover:underline">
          فتح الفترة (للمدير)
        </summary>
        <div className="mt-1 flex flex-wrap items-center gap-2">
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="سبب الفتح — إجباري"
            className="rounded border border-amber-300 px-2 py-1 text-[11px] focus:outline-none"
          />
          <button
            className="rounded bg-amber-600 px-2 py-1 text-[11px] text-white disabled:opacity-50"
            disabled={busy || reason.trim() === ""}
            onClick={submit}
          >
            {busy ? "…" : "افتح"}
          </button>
          {err && <span className="text-[11px] text-red-700">{err}</span>}
        </div>
      </details>
    </div>
  );
}

/* ============================================================
   إطار النافذة
   ============================================================ */

function ModalFrame({
  title,
  onClose,
  children,
}: {
  title: string;
  onClose: () => void;
  children: React.ReactNode;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 py-10">
      <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-base font-semibold text-slate-800">{title}</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-700">
            ✕
          </button>
        </div>
        {children}
      </div>
    </div>
  );
}
