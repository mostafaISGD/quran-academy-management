"use client";

import { useEffect, useState, useMemo, useCallback, useRef, memo } from "react";
import { currencyLabel, date, dateNoYear, dateSmart, egp, money, monthYear, num, time, weekday } from "@/lib/format";
import {
  apiFetch, getStudents, createStudent, updateStudent, deleteStudent,
  getTeachers, getPrograms, getSubscriptions, getSchedule, getStudent,
  getParents, getStudentPhones, addStudentPhone, updateStudentPhone,
  deleteStudentPhone, setPrimaryPhone,
  bulkChangeTeacher, bulkCreateInvoices, bulkNotify,
  createSubscription, updateSubscription,
  getAvailableTeachers,
  getAttendanceReport, getSubscriptionReport,
  searchParents, PARENT_RELATIONSHIPS, parentRelationshipLabel,
  getStudentParents, setPrimaryParent,
  type Student, type Teacher, type Program, type Subscription, type Lesson, type StudentPhone,
  type StudentParent,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import LessonCalendarPreview, { WEEKDAY_NAMES } from "@/components/LessonCalendarPreview";
import TeacherPicker from "@/components/TeacherPicker";
import { useUI, IconTrash } from "@/components/ui";

const STATUS_LABEL: Record<Student["status"], string> = {
  lead: "Lead", active: "نشط", paused: "متوقف", inactive: "غير نشط", graduated: "تخرج", archived: "مؤرشف",
};

const STATUS_COLORS: Record<Student["status"], string> = {
  lead: "bg-blue-100 text-blue-700", active: "bg-green-100 text-green-700", paused: "bg-amber-100 text-amber-700",
  inactive: "bg-slate-100 text-slate-500", graduated: "bg-purple-100 text-purple-700", archived: "bg-red-100 text-red-700",
};

/** أسماء الأيام المختصرة — نفس ترتيب dayOfWeek (0=الأحد) */
const DAYS_SHORT = ["أحد", "إثنين", "ثلاثاء", "أربعاء", "خميس", "جمعة", "سبت"];

const COUNTRIES = [
  { code: "+20", name: "مصر", flag: "🇪🇬" },
  { code: "+966", name: "السعودية", flag: "🇸🇦" },
  { code: "+971", name: "الإمارات", flag: "🇦🇪" },
  { code: "+965", name: "الكويت", flag: "🇰🇼" },
  { code: "+974", name: "قطر", flag: "🇶🇦" },
  { code: "+962", name: "الأردن", flag: "🇯🇴" },
  { code: "+218", name: "ليبيا", flag: "🇱🇾" },
  { code: "+212", name: "المغرب", flag: "🇲🇦" },
];

type Parent = {
  id: number;
  name: string;
  phone: string;
  email: string | null;
};

// Extended Student type with additional fields returned by API
type StudentWithRelations = Student & {
  phones?: StudentPhone[];
  notes?: string | null;
  lessons_count?: number;
};

type StudentDetail = {
  student: StudentWithRelations;
  lessons: Lesson[];
  attendance: { status: string; marked_at: string }[];
  progress: { category: string; score: number; recorded_at: string }[];
  subscription: Subscription | null;
};

type AttendanceReport = {
  period: { from: string; to: string };
  summary: {
    total_lessons_scheduled: number;
    completed_lessons: number;
    cancelled_lessons: number;
    student_absent_lessons: number;
    teacher_absent_lessons: number;
    total_attendance_records: number;
    present: number;
    absent: number;
    late: number;
    attendance_rate: string;
  };
  by_student: Array<{
    student_id: number;
    student_name: string;
    student_code: string;
    total_sessions: number;
    present: number;
    absent: number;
    late: number;
    attendance_rate: number;
  }>;
  by_date: Array<{
    date: string;
    total: number;
    present: number;
    absent: number;
    late: number;
    rate: number;
  }>;
};

type SubscriptionReport = {
  period: { from: string; to: string };
  summary: {
    total: number;
    active: number;
    expired: number;
    paused: number;
    cancelled: number;
    expiring_soon: number;
  };
  revenue_by_currency: Record<string, { total: number; count: number }>;
  by_program: Array<{
    program_id: number;
    program_name: string;
    total: number;
    active: number;
    expired: number;
    revenue: number;
  }>;
  by_teacher: Array<{
    teacher_id: number;
    teacher_name: string;
    total_students: number;
    active: number;
    expired: number;
  }>;
  details: Array<{
    id: number;
    student: { id: number; name: string; code: string; status: string };
    program: string;
    teacher: string;
    start_date: string;
    end_date: string | null;
    days_left: number | null;
    is_expiring_soon: boolean;
    status: string;
    billing_type: string;
    price: number;
    currency: string;
    lessons_included: number | null;
    lesson_duration_minutes: number;
  }>;
};

export default function StudentsPage() {
  const { toast, confirm, prompt } = useUI();

  const [students, setStudents] = useState<Student[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [parents, setParents] = useState<Parent[]>([]);
  const [studentPhones, setStudentPhones] = useState<Record<number, StudentPhone[]>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // === Pagination ===
  const PAGE_SIZE = 100;
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  /** الإحصائيات الحقيقية من الداتابيز على كل النتائج (مش الصفحة الحالية) */
  const [counts, setCounts] = useState<Record<string, number>>({});
  /** الإجمالي الكلي غير المتفلتر — للكارت "إجمالي الطلاب" */
  const [countsAll, setCountsAll] = useState<{ total: number }>({ total: 0 });
  const [searchQuery, setSearchQuery] = useState("");
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [showFilters, setShowFilters] = useState(false);
  const [showColumns, setShowColumns] = useState(false);
  const [selectedStudent, setSelectedStudent] = useState<Student | null>(null);
  const [studentDetail, setStudentDetail] = useState<StudentDetail | null>(null);
  const [studentTab, setStudentTab] = useState("overview");

  // أولياء أمر الطالب (للتبويب الجديد)
  const [guardians, setGuardians] = useState<StudentParent[]>([]);
  const [guardiansLoading, setGuardiansLoading] = useState(false);
  const [guardianBusyId, setGuardianBusyId] = useState<number | null>(null);
  const [filterStatus, setFilterStatus] = useState("");
  const [filterCountry, setFilterCountry] = useState("");
  const [filterProgram, setFilterProgram] = useState("");
  const [filterTeacher, setFilterTeacher] = useState("");
  const [smartSegment, setSmartSegment] = useState("");

  // Phone Manager Modal
  const [showPhoneManager, setShowPhoneManager] = useState(false);
  const [phoneManagerStudent, setPhoneManagerStudent] = useState<Student | null>(null);

  // Reports Modal
  const [showReports, setShowReports] = useState(false);
  const [reportType, setReportType] = useState<'attendance' | 'subscriptions'>('attendance');
  const [attendanceReport, setAttendanceReport] = useState<AttendanceReport | null>(null);
  const [subscriptionReport, setSubscriptionReport] = useState<SubscriptionReport | null>(null);
  const [reportLoading, setReportLoading] = useState(false);

  const [phoneForm, setPhoneForm] = useState<{
    phone_number: string;
    country_code: string;
    is_personal: boolean;
    is_parent: boolean;
    is_whatsapp: boolean;
    is_call: boolean;
    is_primary: boolean;
    parent_name: string;
    parent_relationship: string;
    editingId: number | null;
  }>({
    phone_number: "",
    country_code: "+20",
    is_personal: true,
    is_parent: false,
    is_whatsapp: false,
    is_call: true,
    is_primary: false,
    parent_name: "",
    parent_relationship: "",
    editingId: null,
  });

  // Form state type with temp phone fields for new student creation
  type StudentFormState = {
    first_name: string;
    last_name: string;
    middle_name: string;
    date_of_birth: string;
    gender: string;
    country_code: string;
    email: string;
    status: Student["status"];
    notes: string;
    program_id: string;
    teacher_id: string;
    start_date: string;
    end_date: string;
    /** أيام الحصص الأسبوعية (0=الأحد) — بتتحدد قبل اختيار المعلم */
    weekdays: number[];
    /** وقت بداية الحصة HH:MM */
    start_time: string;
    billing_type: "monthly" | "per_lesson";
    price: number;
    currency: string;
    lesson_duration_minutes: number;
    lessons_included: number;
    phones: Array<{
      phone_number: string;
      country_code: string;
      is_personal: boolean;
      is_parent: boolean;
      is_whatsapp: boolean;
      is_call: boolean;
      is_primary: boolean;
      parent_name: string;
      parent_relationship: string;
    }>;
    // Temp fields for phone management in new student form
    phones_temp_country?: string;
    phones_temp_personal?: boolean;
    phones_temp_whatsapp?: boolean;
    phones_temp_call?: boolean;
    phones_temp_parent?: boolean;
    phones_temp_primary?: boolean;
    phones_temp_parent_name?: string;
    phones_temp_parent_relationship?: string;
    phones_temp_number?: string;
  };

  const emptyForm: StudentFormState = {
    // البيانات الأساسية
    first_name: "", last_name: "", middle_name: "", date_of_birth: "", gender: "",
    country_code: "", email: "", status: "active" as Student["status"], notes: "",
    // الاشتراك
    program_id: "", teacher_id: "",
    start_date: new Date().toISOString().split("T")[0],
    end_date: new Date(new Date().setMonth(new Date().getMonth() + 1)).toISOString().split("T")[0],
    weekdays: [] as number[],
    start_time: "16:00",
    billing_type: "monthly" as "monthly" | "per_lesson",
    price: 500, currency: "EGP",
    lesson_duration_minutes: 30, lessons_included: 8,
    // أرقام الهواتف (للطلاب الجدد) — ولي الأمر بيتسجل من هنا
    phones: [] as Array<{
      phone_number: string;
      country_code: string;
      is_personal: boolean;
      is_parent: boolean;
      is_whatsapp: boolean;
      is_call: boolean;
      is_primary: boolean;
      parent_name: string;
      parent_relationship: string;
    }>,
    phones_temp_number: "",
  };
  const [form, setForm] = useState(emptyForm);

  // Current date for subscription calculations (memoized to avoid impure Date.now in render)
  const now = useMemo(() => new Date(), []);

  // Helper functions for rendering complex columns
  const renderSubscriptionColumn = (sub: Subscription) => {
    const daysLeft = sub.end_date ? Math.ceil((new Date(sub.end_date).getTime() - now.getTime()) / (1000 * 60 * 60 * 24)) : null;
    return (
      <div>
        <span className={`rounded-full px-2 py-0.5 text-xs ${sub.status === "active" ? "bg-green-100 text-green-700" : sub.status === "expired" ? "bg-red-100 text-red-700" : "bg-amber-100 text-amber-700"}`}>
          {sub.status === "active" ? "نشط" : sub.status === "expired" ? "منتهي" : sub.status === "paused" ? "متوقف" : sub.status}
        </span>
        {daysLeft !== null && daysLeft > 0 && <p className="mt-1 text-xs text-slate-400">{daysLeft} يوم متبقي</p>}
      </div>
    );
  };

  const renderPhoneColumn = (s: Student | StudentWithRelations) => {
    const phones = 'phones' in s ? (s as StudentWithRelations).phones ?? [] : [];
    const primary = phones.find((p: StudentPhone) => p.is_primary) ?? phones[0];
    if (!primary) return (
      <div className="flex items-center gap-1">
        <span className="text-slate-400">—</span>
        <button onClick={() => openPhoneManager(s)} className="rounded bg-purple-50 px-2 py-1 text-xs text-purple-600 hover:bg-purple-100" title="إدارة الأرقام">⚙</button>
      </div>
    );
    return (
      <div className="group relative flex flex-col items-start gap-1">
        {/* Quick Actions - Show ABOVE the number on hover */}
        <div className="absolute bottom-full left-0 z-10 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity duration-150 mb-1">
          {primary.is_call && (
            <a href={`tel:${primary.phone_number}`} className="rounded bg-blue-50 px-2 py-1 text-xs text-blue-600 hover:bg-blue-100 transition-colors shadow-lg" title="اتصال مباشر">
              📞
            </a>
          )}
          {primary.is_whatsapp && (
            <a href={`https://wa.me/${primary.phone_number.replace(/[^0-9]/g, '')}`} target="_blank" rel="noopener noreferrer" className="rounded bg-green-50 px-2 py-1 text-xs text-green-600 hover:bg-green-100 transition-colors shadow-lg" title="واتساب ويب">
              💬
            </a>
          )}
          <button onClick={(e) => { e.preventDefault(); e.stopPropagation(); navigator.clipboard.writeText(primary.phone_number); }} className="rounded bg-slate-50 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 transition-colors shadow-lg" title="نسخ الرقم">
            📋
          </button>
        </div>
        {/* Main phone number row */}
        <div className="flex items-center gap-1 w-full">
          <span className="font-medium text-slate-800" dir="ltr">{primary.phone_number}</span>
          <div className="flex gap-0.5">
            {primary.is_call && <span className="text-xs text-blue-500" title="تواصل هاتفي">📞</span>}
            {primary.is_whatsapp && <span className="text-xs text-green-500" title="واتساب">💬</span>}
            {primary.is_parent && <span className="text-xs text-purple-500" title="ولي أمر">👨‍👩‍👧</span>}
            {primary.is_primary && <span className="text-xs text-amber-500" title="أساسي">⭐</span>}
          </div>
          <button onClick={() => openPhoneManager(s)} className="rounded bg-slate-100 px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200 ml-auto" title="إدارة الأرقام">⚙</button>
        </div>
        {/* Tooltip with details */}
        <div className="absolute left-0 top-full z-10 hidden rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg group-hover:block mt-1">
          <p className="font-medium text-slate-800">{primary.phone_number}</p>
          <p className="text-slate-500">
            {primary.is_personal ? 'شخصي' : ''} {primary.is_parent ? 'ولي أمر' : ''} {primary.is_whatsapp ? 'واتساب' : ''} {primary.is_call ? 'تواصل' : ''}
          </p>
          {primary.is_parent && primary.parent_name && (
            <p className="text-purple-600">{primary.parent_name} ({parentRelationshipLabel(primary.parent_relationship)})</p>
          )}
        </div>
      </div>
    );
  };

  const renderNextLessonColumn = (s: Student) => {
    if (!s.next_lesson_date) return "—";
    // ⭐ تاريخ **من غير السنة** — الحصة الجاية قريبة، والسنة بتزحم
    return dateNoYear(s.next_lesson_date);
  };

  // Phone Manager Functions
  const openPhoneManager = (student: Student) => {
    setPhoneManagerStudent(student);
    // Load phones for this student
    getStudentPhones(student.id).then((r) => {
      setStudentPhones((prev) => ({ ...prev, [student.id]: r.data }));
    }).catch(() => {});
    setShowPhoneManager(true);
    resetPhoneForm();
  };

  const resetPhoneForm = () => {
    setPhoneForm({
      phone_number: "",
      country_code: "+20",
      is_personal: true,
      is_parent: false,
      is_whatsapp: false,
      is_call: true,
      is_primary: false,
      parent_name: "",
      parent_relationship: "",
      editingId: null,
    });
  };


  // Local Phone Manager Component for new students
const PhoneManagerLocal = memo(({ form, setForm }: { form: any; setForm: React.Dispatch<React.SetStateAction<any>> }) => {
    const { toast } = useUI();
    const phones = form.phones || [];
    const phoneInputRef = useRef<HTMLInputElement>(null);
    const parentNameInputRef = useRef<HTMLInputElement>(null);
    
    // Use local state for ALL temporary fields - no setForm until adding phone
    const [localPhoneValue, setLocalPhoneValue] = useState("");
    const [localCountryCode, setLocalCountryCode] = useState("+20");
    const [localIsPersonal, setLocalIsPersonal] = useState(true);
    const [localIsParent, setLocalIsParent] = useState(false);
    const [localIsWhatsapp, setLocalIsWhatsapp] = useState(false);
    const [localIsCall, setLocalIsCall] = useState(true);
    const [localIsPrimary, setLocalIsPrimary] = useState(false);
    const [localParentName, setLocalParentName] = useState("");
    const [localParentRelationship, setLocalParentRelationship] = useState("");
    const [localCustomRelationship, setLocalCustomRelationship] = useState("");

    // الإكمال التلقائي: بحث في أولياء الأمر
    const [suggestions, setSuggestions] = useState<(Parent & { students_count: number })[]>([]);
    const [showSuggestions, setShowSuggestions] = useState(false);

    useEffect(() => {
      if (!localIsParent || localPhoneValue.replace(/\D/g, '').length < 3) {
        setSuggestions([]);
        return;
      }

      const timer = setTimeout(() => {
        searchParents(localPhoneValue)
          .then((r) => setSuggestions(r.data))
          .catch(() => setSuggestions([]));
      }, 300);

      return () => clearTimeout(timer);
    }, [localPhoneValue, localIsParent]);

    const applySuggestion = (parent: Parent & { students_count: number }) => {
      setLocalParentName(parent.name);
      setLocalIsParent(true);
      setSuggestions([]);
      setShowSuggestions(false);
    };

    const validatePhone = (number: string, countryCode: string): boolean => {
      const cleaned = number.replace(/[\s\-\(\)]/g, '');
      
      if (!/^\d+$/.test(cleaned)) {
        toast.error("الرقم يجب أن يحتوي على أرقام فقط", "شيل المسافات والرموز واكتب الأرقام فقط.");
        return false;
      }
      
      const lengths: Record<string, { min: number; max: number }> = {
        '+20': { min: 10, max: 11 },
        '+966': { min: 9, max: 10 },
        '+971': { min: 9, max: 10 },
        '+965': { min: 8, max: 8 },
        '+974': { min: 8, max: 8 },
        '+962': { min: 9, max: 10 },
        '+212': { min: 9, max: 10 },
        '+216': { min: 8, max: 8 },
        '+249': { min: 9, max: 10 },
      };
      
      const rule = lengths[countryCode] ?? { min: 7, max: 15 };
      if (cleaned.length < rule.min || cleaned.length > rule.max) {
        toast.error(
          "رقم غير صحيح",
          `أرقام الدولة ${countryCode} لازم تكون من ${rule.min} لـ ${rule.max} رقماً — دلوقتي ${cleaned.length}.`,
        );
        return false;
      }
      
      return true;
    };

    const addPhone = () => {
      const number = localPhoneValue.trim();
      if (!number) {
        toast.error("الرقم مطلوب", "اكتب رقم التليفون الأول.");
        return;
      }

      if (!validatePhone(number, localCountryCode)) return;

      // لو رقم لولي أمر → الاسم والصلة مطلوبين
      if (localIsParent) {
        if (!localParentName.trim()) {
          toast.error("اسم ولي الأمر مطلوب", "اكتب اسم صاحب الرقم.");
          return;
        }
        const rel = localParentRelationship === "other" ? localCustomRelationship.trim() : localParentRelationship;
        if (!rel) {
          toast.error("صلة القرابة مطلوبة", "اختر صلة القرابة بين الطالب وولي الأمر.");
          return;
        }
      }

      const newPhone = {
        phone_number: number,
        country_code: localCountryCode,
        is_personal: localIsPersonal,
        is_parent: localIsParent,
        is_whatsapp: localIsWhatsapp,
        is_call: localIsCall,
        is_primary: localIsPrimary,
        parent_name: localParentName || "",
        parent_relationship:
          localParentRelationship === "other"
            ? localCustomRelationship.trim()
            : localParentRelationship || "",
      };
      
      // Only call setForm when actually adding a phone
      setForm((prev: any) => ({ 
        ...prev, 
        phones: [...prev.phones, newPhone],
      }));
      
      // Reset local state
      setLocalPhoneValue("");
      setLocalCountryCode("+20");
      setLocalIsPersonal(true);
      setLocalIsParent(false);
      setLocalIsWhatsapp(false);
      setLocalIsCall(true);
      setLocalIsPrimary(false);
      setLocalParentName("");
      setLocalParentRelationship("");
      setLocalCustomRelationship("");
      setSuggestions([]);

      setTimeout(() => phoneInputRef.current?.focus(), 0);
    };

    const handleKeyDown = (e: React.KeyboardEvent) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addPhone();
      }
    };

    const handleParentNameKeyDown = (e: React.KeyboardEvent) => {
      if (e.key === "Enter") {
        e.preventDefault();
        addPhone();
      }
    };

    return (
      <div className="space-y-3">
        <div className="rounded-lg bg-slate-50 p-4 space-y-3">
          <h5 className="font-medium text-slate-700 flex items-center gap-2">
            إضافة رقم جديد
            <span className="text-xs text-slate-400">(اضغط Enter للإضافة)</span>
          </h5>
          <div className="grid grid-cols-2 gap-3">
            <div className="relative">
              <label className="mb-1 block text-xs text-slate-500">الرقم *</label>
              <input
                type="tel"
                placeholder="01xxxxxxxxx"
                ref={phoneInputRef}
                value={localPhoneValue}
                onChange={(e) => {
                  setLocalPhoneValue(e.target.value);
                  setShowSuggestions(true);
                }}
                onFocus={() => setShowSuggestions(true)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dir-ltr"
                dir="ltr"
                onKeyDown={handleKeyDown}
              />

              {/* الإكمال التلقائي: أولياء أمر موجودين بنفس الرقم */}
              {showSuggestions && localIsParent && suggestions.length > 0 && (
                <ul className="absolute z-30 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                  {suggestions.map((s) => (
                    <li key={s.id}>
                      <button
                        type="button"
                        onClick={() => applySuggestion(s)}
                        className="flex w-full items-center justify-between px-3 py-2 text-start text-sm hover:bg-slate-50"
                      >
                        <span>
                          <span className="font-medium text-slate-800">{s.name}</span>
                          <span className="ms-2 text-xs text-slate-400" dir="ltr">{s.phone}</span>
                        </span>
                        <span className="shrink-0 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] text-blue-700">
                          {s.students_count} طالب
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-500">الدولة</label>
              <select
                value={localCountryCode}
                onChange={(e) => setLocalCountryCode(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              >
                {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.flag} {c.name} ({c.code})</option>)}
              </select>
            </div>
          </div>
          <div className="flex flex-wrap gap-3">
            <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
              <input type="checkbox" checked={localIsPersonal} onChange={(e) => setLocalIsPersonal(e.target.checked)} className="rounded" />
              شخصي
            </label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
              <input type="checkbox" checked={localIsWhatsapp} onChange={(e) => setLocalIsWhatsapp(e.target.checked)} className="rounded" />
              واتساب 💬
            </label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
              <input type="checkbox" checked={localIsCall} onChange={(e) => setLocalIsCall(e.target.checked)} className="rounded" />
              تواصل 📞
            </label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
              <input type="checkbox" checked={localIsParent} onChange={(e) => setLocalIsParent(e.target.checked)} className="rounded" />
              ولي أمر 👨‍👩‍👧
            </label>
            <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
              <input type="checkbox" checked={localIsPrimary} onChange={(e) => setLocalIsPrimary(e.target.checked)} className="rounded" />
              أساسي ⭐
            </label>
          </div>
          {localIsParent && (
            <div className="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200">
              <div>
                <label className="mb-1 block text-xs text-slate-500">اسم ولي الأمر *</label>
                <input
                  placeholder="مثال: فاطمة أحمد"
                  ref={parentNameInputRef}
                  value={localParentName}
                  onChange={(e) => setLocalParentName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                  onKeyDown={handleParentNameKeyDown}
                />
              </div>
              <div>
                <label className="mb-1 block text-xs text-slate-500">صلة القرابة *</label>
                <select
                  value={localParentRelationship}
                  onChange={(e) => {
                    setLocalParentRelationship(e.target.value);
                    if (e.target.value !== "other") setLocalCustomRelationship("");
                  }}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                >
                  <option value="">اختر الصلة</option>
                  {PARENT_RELATIONSHIPS.map((r) => (
                    <option key={r.value} value={r.value}>{r.label}</option>
                  ))}
                </select>
              </div>
            </div>
          )}

          {/* خانة الكتابة الحرة لو اختار "أخرى" */}
          {localIsParent && localParentRelationship === "other" && (
            <div className="pt-2">
              <label className="mb-1 block text-xs text-slate-500">اكتب صلة القرابة *</label>
              <input
                placeholder="مثال: قرابة، زوجة الأب، مدرّبة..."
                value={localCustomRelationship}
                onChange={(e) => setLocalCustomRelationship(e.target.value)}
                onKeyDown={handleParentNameKeyDown}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </div>
          )}

          {/* تنبيه: نفس الرقم ممكن يكون عند أكتر من طالب */}
          {localIsParent && suggestions.length > 0 && (
            <p className="rounded-lg bg-blue-50 px-3 py-2 text-xs text-blue-700">
              الرقم ده مسجل بالفعل لأولياء أمر — لو حد منهم، اختار اسمه من الاقتراحات.
            </p>
          )}

          <div className="flex gap-2">
            <button
              type="button"
              onClick={addPhone}
              className="flex-1 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
            >
              ➕ إضافة الرقم
            </button>
            {form.phones.length > 0 && (
              <button
                type="button"
                onClick={() => setForm((prev: any) => ({ ...prev, phones: [] }))}
                className="rounded-lg bg-red-50 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-100"
              >
                🗑 مسح الكل
              </button>
            )}
          </div>
        </div>

        {phones.length > 0 && (
          <div className="rounded-xl border border-slate-200">
            <h5 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">أرقام الهاتف ({phones.length})</h5>
            <ul className="divide-y divide-slate-100">
              {phones.map((phone: { phone_number: string; country_code: string; is_personal: boolean; is_parent: boolean; is_whatsapp: boolean; is_call: boolean; is_primary: boolean; parent_name: string; parent_relationship: string }, idx: number) => (
                <li key={`phone-${idx}`} className="p-4 hover:bg-slate-50">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <span className="font-mono text-lg font-medium text-slate-800" dir="ltr">{phone.phone_number}</span>
                      <div className="flex gap-1.5">
                        {phone.is_personal && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-50 text-blue-700">👤 شخصي</span>}
                        {phone.is_whatsapp && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">💬 واتساب</span>}
                        {phone.is_call && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">📞 تواصل</span>}
                        {phone.is_parent && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-purple-50 text-purple-700">👨‍👩‍👧 ولي أمر</span>}
                        {phone.is_primary && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700">⭐ أساسي</span>}
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      {phone.is_parent && phone.parent_name && (
                        <span className="text-xs text-purple-600">{phone.parent_name} ({parentRelationshipLabel(phone.parent_relationship)})</span>
                      )}
                      <button onClick={() => setForm((prev: any) => ({ ...prev, phones: prev.phones.filter((_: any, i: number) => i !== idx) }))} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100" title="حذف">🗑</button>
                    </div>
                  </div>
                  {phone.is_parent && phone.parent_name && (
                    <p className="mt-1 text-xs text-purple-600">{phone.parent_name} ({parentRelationshipLabel(phone.parent_relationship)})</p>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    );
  });

  const editPhone = (phone: StudentPhone) => {
    setPhoneForm({
      phone_number: phone.phone_number,
      country_code: phoneManagerStudent?.country_code ?? "+20",
      is_personal: phone.is_personal,
      is_parent: phone.is_parent,
      is_whatsapp: phone.is_whatsapp,
      is_call: phone.is_call,
      is_primary: phone.is_primary,
      parent_name: phone.parent_name ?? "",
      parent_relationship: phone.parent_relationship ?? "",
      editingId: phone.id,
    });
  };

  const handlePhoneSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!phoneManagerStudent) return;

    const number = phoneForm.phone_number.trim();
    if (!number) {
      toast.error("الرقم مطلوب", "اكتب رقم التليفون الأول.");
      return;
    }

    // نفس قواعد الطول المستخدمة في نموذج الطالب الجديد
    const LENGTHS: Record<string, { min: number; max: number }> = {
      "+20": { min: 10, max: 11 }, "+966": { min: 9, max: 10 }, "+971": { min: 9, max: 10 },
      "+965": { min: 8, max: 8 }, "+974": { min: 8, max: 8 }, "+962": { min: 9, max: 10 },
      "+212": { min: 9, max: 10 }, "+216": { min: 8, max: 8 }, "+249": { min: 9, max: 10 },
    };
    const cleaned = number.replace(/[\s\-\(\)]/g, "");
    if (!/^\d+$/.test(cleaned)) {
      toast.error("الرقم يجب أن يحتوي على أرقام فقط", "شيل المسافات والرموز واكتب الأرقام فقط.");
      return;
    }
    const rule = LENGTHS[phoneForm.country_code] ?? { min: 7, max: 15 };
    if (cleaned.length < rule.min || cleaned.length > rule.max) {
      toast.error(
        "رقم غير صحيح",
        `أرقام الدولة ${phoneForm.country_code} لازم تكون من ${rule.min} لـ ${rule.max} رقماً — دلوقتي ${cleaned.length}.`,
      );
      return;
    }
    if (phoneForm.is_parent && !phoneForm.parent_name.trim()) {
      toast.error("اسم ولي الأمر مطلوب", "اكتب اسم صاحب الرقم.");
      return;
    }

    try {
      const payload = {
        phone_number: number,
        country_code: phoneForm.country_code,
        is_personal: phoneForm.is_personal,
        is_parent: phoneForm.is_parent,
        is_whatsapp: phoneForm.is_whatsapp,
        is_call: phoneForm.is_call,
        is_primary: phoneForm.is_primary,
        parent_name: phoneForm.parent_name || undefined,
        parent_relationship: phoneForm.parent_relationship || undefined,
      };

      if (phoneForm.editingId) {
        await updateStudentPhone(phoneManagerStudent.id, phoneForm.editingId, payload);
        toast.success("تم تعديل الرقم", number);
      } else {
        await addStudentPhone(phoneManagerStudent.id, payload);
        toast.success("تمت إضافة الرقم", number);
      }

      // Reload phones
      const phones = await getStudentPhones(phoneManagerStudent.id);
      setStudentPhones((prev) => ({ ...prev, [phoneManagerStudent.id]: phones.data }));
      resetPhoneForm();
    } catch (err) {
      toast.error("فشل حفظ الرقم", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحفظ");
    }
  };

  const handleDeletePhone = async (phoneId: number, phoneNumber: string) => {
    if (!phoneManagerStudent) return;

    const ok = await confirm({
      title: "حذف رقم التليفون",
      message: `متأكد إنك عايز تحذف الرقم «${phoneNumber}» من ${phoneManagerStudent.full_name}؟\n\nلو الرقم ده لولي أمر، علاقة القرابة هتتشال من قائمة أولياء الأمور كمان.`,
      confirmLabel: "احذف الرقم",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;

    try {
      await deleteStudentPhone(phoneManagerStudent.id, phoneId);
      toast.success("تم حذف الرقم", phoneNumber);
      const phones = await getStudentPhones(phoneManagerStudent.id);
      setStudentPhones((prev) => ({ ...prev, [phoneManagerStudent.id]: phones.data }));
    } catch (err) {
      toast.error("فشل حذف الرقم", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  };

  const handleSetPrimaryPhone = async (phoneId: number) => {
    if (!phoneManagerStudent) return;
    try {
      await setPrimaryPhone(phoneManagerStudent.id, phoneId);
      const phones = await getStudentPhones(phoneManagerStudent.id);
      setStudentPhones((prev) => ({ ...prev, [phoneManagerStudent.id]: phones.data }));
      toast.success("تم تعيين الرقم الأساسي");
    } catch (err) {
      toast.error("فشل التحديث", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل التحديث");
    }
  };

  const handleTogglePhoneField = async (phoneId: number, field: keyof StudentPhone) => {
    if (!phoneManagerStudent) return;
    try {
      const phone = studentPhones[phoneManagerStudent.id]?.find(p => p.id === phoneId);
      if (!phone) return;
      await updateStudentPhone(phoneManagerStudent.id, phoneId, { [field]: !phone[field] });
      const phones = await getStudentPhones(phoneManagerStudent.id);
      setStudentPhones((prev) => ({ ...prev, [phoneManagerStudent.id]: phones.data }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل التحديث");
    }
  };

  const ALL_COLUMNS = [
    { key: "student", label: "الطالب", visible: true },
    { key: "program", label: "البرنامج", visible: true },
    { key: "teacher", label: "المدرس", visible: true },
    { key: "subscription", label: "الاشتراك (الحالة)", visible: true },
    { key: "lessons", label: "الحصص", visible: true },
    { key: "finance", label: "المالي", visible: true },
    { key: "status", label: "الحالة", visible: true },
    { key: "country", label: "الدولة", visible: true },
    { key: "whatsapp", label: "الهاتف", visible: true },
    { key: "date_of_birth", label: "تاريخ الميلاد", visible: true },
    { key: "next_lesson", label: "الحصة القادمة", visible: true },
  ];
  const [visibleColumns, setVisibleColumns] = useState(ALL_COLUMNS);

  // الفلاتر اللي بتتبعت للـ API (البحث + الحالة + الفرع)
  const apiFilters = useMemo(
    () => ({
      search: searchQuery.trim() || undefined,
      status: filterStatus || undefined,
      branch_id: undefined,
    }),
    [searchQuery, filterStatus],
  );

  const fetchPage = useCallback(async (targetPage: number) => {
    const r = await getStudents({
      per_page: PAGE_SIZE,
      page: targetPage,
      status: apiFilters.status,
      search: apiFilters.search,
      branch_id: apiFilters.branch_id,
    });
    setStudents(r.data);
    setMeta({ total: r.total, last_page: r.last_page });
    // StudentsController بيبني counts مسطّحة بنفسه (مش عن طريق
    // paginatedWithCounts زي باقي الصفحات) — فمفيش تداخل هنا
    setCounts((r.counts ?? {}) as Record<string, number>);
  }, [apiFilters]);

  /** الإجمالي الكلي غير المتفلتر — بيتحسب مرة واحدة عند التحميل الأول */
  const loadUnfilteredTotal = useCallback(async () => {
    try {
      const r = await getStudents({ per_page: 1 });
      setCountsAll({ total: r.total });
    } catch {
      // لو فشل نخلي الإجمالي fallback لعدد الصفحة
    }
  }, []);

  const loadStudents = useCallback(async () => {
    try {
      await fetchPage(1);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, [fetchPage]);

  const goToPage = useCallback(async (target: number) => {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    setLoading(true);
    setError(null);
    try {
      await fetchPage(target);
      window.scrollTo({ top: 0, behavior: "smooth" });
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, [page, meta.last_page, fetchPage]);

  // Initial data load - only run once
  useEffect(() => {
    let mounted = true;
    const initialize = async () => {
      setLoading(true);
      await loadStudents();
      if (!mounted) return;
      getTeachers().then((r) => setTeachers(r.data)).catch(() => {});
      getPrograms().then((r) => setPrograms(r.data)).catch(() => {});
      getSubscriptions().then((r) => setSubscriptions(r.data)).catch(() => {});
      getParents().then((r) => setParents(r.data)).catch(() => {});
      loadUnfilteredTotal();
    };
    initialize();
    return () => { mounted = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // إعادة التحميل + الرجوع للصفحة الأولى عند تغيير الفلاتر
  useEffect(() => {
    const t = setTimeout(() => {
      setPage(1);
      setLoading(true);
      fetchPage(1)
        .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
        .finally(() => setLoading(false));
    }, 300);
    return () => clearTimeout(t);
  }, [fetchPage]);

  // Load student detail when selected
  useEffect(() => {
    let cancelled = false;
    
    const loadDetail = async () => {
      if (!selectedStudent) {
        if (!cancelled) setStudentDetail(null);
        return;
      }
      try {
        const [student, lessons, attendance, progress, phones] = await Promise.all([
          getStudent(selectedStudent.id),
          getSchedule({ view: "month", student_id: selectedStudent.id }),
          apiFetch<{ data: { status: string; marked_at: string }[] }>(`/students/${selectedStudent.id}/attendance`),
          apiFetch<{ data: { category: string; score: number; recorded_at: string }[] }>(`/students/${selectedStudent.id}/progress`),
          getStudentPhones(selectedStudent.id),
        ]);
        if (cancelled) return;
        const sub = subscriptions.find((s) => s.student_id === selectedStudent.id) ?? null;
        setStudentDetail({ student, lessons, attendance: attendance.data, progress: progress.data, subscription: sub });
        setStudentPhones((prev) => ({ ...prev, [selectedStudent.id]: phones.data }));
      } catch {
        if (!cancelled) setStudentDetail(null);
      }
    };
    
    loadDetail();
    return () => { cancelled = true; };
  }, [selectedStudent, subscriptions]);

  // Stats
  const stats = useMemo(() => ({
    // الإجمالي = العدد الكلي في النظام (مش المتفلتر)
    total: countsAll.total || meta.total || students.length,
    active: counts.active ?? 0,
    paused: counts.paused ?? 0,
    expired: counts.inactive ?? 0,
    trials: counts.lead ?? 0,
  }), [counts, countsAll]);

  // Filtered students
  const filteredStudents = useMemo(() => {
    let result = students;
    if (filterStatus) result = result.filter((s) => s.status === filterStatus);
    if (filterCountry) result = result.filter((s) => s.country_code === filterCountry);
    if (filterProgram) result = result.filter((s) => {
      const sub = subscriptions.find((sub) => sub.student_id === s.id);
      return sub && sub.program_id === Number(filterProgram);
    });
    if (filterTeacher) result = result.filter((s) => {
      const sub = subscriptions.find((sub) => sub.student_id === s.id);
      return sub && sub.teacher_id === Number(filterTeacher);
    });
    if (searchQuery) {
      const q = searchQuery.toLowerCase();
      result = result.filter((s) =>
        s.full_name.toLowerCase().includes(q) ||
        s.student_code.toLowerCase().includes(q) ||
        s.phone?.includes(q) ||
        s.email?.toLowerCase().includes(q)
      );
    }
    if (smartSegment === "expiring") result = result.filter((s) => s.status === "active");
    if (smartSegment === "trials") result = result.filter((s) => s.status === "lead");
    return result;
  }, [students, filterStatus, filterCountry, filterProgram, filterTeacher, searchQuery, smartSegment, subscriptions]);

  /** تحميل أولياء أمر الطالب */
  const loadGuardians = useCallback(async () => {
    if (!selectedStudent) {
      setGuardians([]);
      return;
    }
    setGuardiansLoading(true);
    try {
      const r = await getStudentParents(selectedStudent.id);
      setGuardians(r.data);
    } catch {
      setGuardians([]);
    } finally {
      setGuardiansLoading(false);
    }
  }, [selectedStudent]);

  /** تغيير ولي الأمر الأساسي */
  async function handleSetPrimaryGuardian(parentId: number) {
    if (!selectedStudent) return;
    setGuardianBusyId(parentId);
    try {
      const r = await setPrimaryParent(selectedStudent.id, parentId);
      setGuardians(r.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تغيير ولي الأمر الأساسي");
    } finally {
      setGuardianBusyId(null);
    }
  }

  // تحميل أولياء الأمر لما يبقى التبويب مفتوح
  useEffect(() => {
    if (selectedStudent && studentTab === "guardians") {
      loadGuardians();
    }
  }, [selectedStudent, studentTab, loadGuardians]);

  // تصفير القائمة لما نقفل الدروار
  useEffect(() => {
    if (!selectedStudent) {
      setGuardians([]);
      setGuardianBusyId(null);
    }
  }, [selectedStudent]);

  function openCreate() { setForm(emptyForm); setEditingId(null); setShowForm(true); }
  function openEdit(s: StudentWithRelations) {
    const sub = subscriptions.find((sub) => sub.student_id === s.id);
    // Map phones to match form's expected type (include country_code, remove extra fields, handle nulls)
    const mappedPhones = (s.phones ?? []).map(ph => ({
      phone_number: ph.phone_number,
      country_code: "+20", // Default, API doesn't return country_code on StudentPhone
      is_personal: ph.is_personal,
      is_parent: ph.is_parent,
      is_whatsapp: ph.is_whatsapp,
      is_call: ph.is_call,
      is_primary: ph.is_primary,
      parent_name: ph.parent_name ?? "",
      parent_relationship: ph.parent_relationship ?? "",
    }));
    setForm({
      first_name: s.first_name, last_name: s.last_name, middle_name: s.middle_name ?? "",
      date_of_birth: s.date_of_birth ?? "", gender: s.gender ?? "", country_code: s.country_code ?? "",
      email: s.email ?? "", status: s.status,
      notes: (s as StudentWithRelations).notes ?? "",
      program_id: sub?.program_id?.toString() ?? "", teacher_id: sub?.teacher_id?.toString() ?? "",
      start_date: sub?.start_date ?? new Date().toISOString().split("T")[0],
      end_date: sub?.end_date ?? new Date(new Date().setMonth(new Date().getMonth() + 1)).toISOString().split("T")[0],
      billing_type: (sub?.billing_type as "monthly" | "per_lesson") ?? "monthly",
      // المواعيد محفوظة مع الاشتراك — لو مش موجودة نخليها فاضية
      // عشان الأدمن يختارها تاني بدل ما يتعامل مع قيمة غلط
      weekdays: Array.isArray(sub?.schedule_weekdays)
        ? (sub.schedule_weekdays as number[]).slice().sort((a, b) => a - b)
        : [],
      start_time: sub?.schedule_start_time ?? "16:00",
      price: sub?.price ? Number(sub.price) : 500, currency: sub?.currency ?? "EGP",
      lesson_duration_minutes: sub?.lesson_duration_minutes ?? 30, lessons_included: sub?.lessons_included ?? 8,
      phones: mappedPhones,
    });
    setEditingId(s.id); setShowForm(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      // Validation: New student must have at least one phone number
      if (!editingId && (!form.phones || form.phones.length === 0)) {
        setError("يجب إضافة رقم هاتف واحد على الأقل للطالب الجديد");
        return;
      }

      // لازم أيام الحصة والوقت قبل ما نختار معلم
      const wantsSubscription = Boolean(form.program_id && form.teacher_id);
      if (wantsSubscription && form.weekdays.length === 0) {
        toast.warning("اختر أيام الحصة الأسبوعية", "من غيرها مش هينفع نحجز الحصص للمعلم.");
        return;
      }

      // نتأكد من توافر المعلم في المواعيد دي قبل الحفظ (الفلترة لوحدها مش كفاية
      // — ممكن يكون اتحجز بعد ما القائمة اتحمّلت)
      if (wantsSubscription) {
        try {
          const check = await getAvailableTeachers({
            weekdays: form.weekdays,
            start_time: form.start_time,
            duration: form.lesson_duration_minutes,
            on_date: form.start_date || undefined,
            period_to: form.end_date || undefined,
          });
          const me = check.teachers.find((t) => String(t.id) === form.teacher_id);

          if (!me) {
            toast.error("المعلم مش موجود", "حاول تختاره تاني من القائمة.");
            return;
          }
          if (!me.available) {
            const proceed = await confirm({
              title: "المعلم مشغول في المواعيد دي",
              message: `${me.name} ${me.reason ?? "مشغول"}.\n\nتحب تكمل برضه؟ الحجز ممكن يفشل لو في تعارض.`,
              confirmLabel: "اكمل",
              cancelLabel: "رجوع",
              tone: "warning",
            });
            if (!proceed) return;
          }

          // منع التعارض: لو السعة أقل من الحصص المطلوبة، ما بنحفظش
          // (قرار إداري: ممنوع أكتر من طالب في نفس الموعد مع نفس المعلم)
          const wanted = Number(form.lessons_included) || 0;
          if (wanted > 0 && me.capacity < wanted) {
            const perDay = Object.entries(me.per_day ?? {})
              .filter(([, v]) => (v as number) > 0)
              .map(([d, v]) => `${WEEKDAY_NAMES[Number(d)]} ${v}`);

            toast.error(
              me.capacity === 0
                ? `المواعيد دي كلها محجوزة مع ${me.name}`
                : `السعة مش مكفية — ${me.name} عنده ${me.capacity} موعد بس`,
              `${wanted} حصة مطلوبة${perDay.length ? ` (${perDay.join(" · ")})` : ""}. قلّل الحصص المشمولة أو اختار معلم تاني.`,
              9000,
            );
            return;
          }
        } catch {
          // لو فشل التحقق ما بنمنعش الحفظ — السيرفر هيحمي نفسه
        }
      }

      const payload = {
        first_name: form.first_name, last_name: form.last_name, middle_name: form.middle_name || undefined,
        date_of_birth: form.date_of_birth || undefined, gender: (form.gender || undefined) as "male" | "female" | undefined,
        country_code: form.country_code || undefined,
        email: form.email || undefined, status: form.status,
        notes: form.notes || undefined,
      };
      let studentId: number;
      if (editingId) {
        await updateStudent(editingId, payload);
        studentId = editingId;
      } else {
        const newStudent = await createStudent(payload);
        studentId = newStudent.id;
      }
      // Auto-create/update subscription if program and teacher selected
      if (form.program_id && form.teacher_id) {
        const programId = Number(form.program_id);
        const teacherId = Number(form.teacher_id);
        const existingSub = subscriptions.find((s) => s.student_id === studentId);
        const subPayload = {
          // ملاحظة: مفيش plan_id — الاشتراك بيتسجّل بالسعر ونوع الفوترة مباشرة
          student_id: studentId, program_id: programId, teacher_id: teacherId,
          start_date: form.start_date,
          end_date: form.end_date,
          billing_type: form.billing_type as "monthly" | "per_lesson",
          price: form.price, currency: form.currency,
          lesson_duration_minutes: form.lesson_duration_minutes,
          lessons_included: form.lessons_included,
          // المواعيد الأسبوعية — دي اللي بتتحوّل لحصص على تقويم المعلم
          weekdays: form.weekdays, start_time: form.start_time,
        };
        const result = existingSub
          ? await updateSubscription(existingSub.id, subPayload)
          : await createSubscription(subPayload);

        // نقول للـ admin إيه اللي حصل تلقائياً: فاتورة، رصيد، وحصص
        const { activation } = result;
        const skipped = activation.lessons_skipped.length;

        if (skipped > 0) {
          toast.warning(
            `${activation.lessons_created} حصة اتجدولت`,
            `${skipped} موعد اتخطّى لأن المعلم كان مشغول فيه: ${activation.lessons_skipped
              .slice(0, 3)
              .map((s) => s.date)
              .join("، ")}${skipped > 3 ? "…" : ""}`,
            10000,
          );
        } else if (activation.lessons_created > 0) {
          toast.success(
            `تم إنشاء الاشتراك · ${activation.lessons_created} حصة اتجدولت`,
            activation.invoice_id ? `فاتورة #${activation.invoice_id} جاهزة` : undefined,
          );
        }
      }
      // Save phone numbers — ولي الأمر بيتسجل تلقائي من الأرقام اللي عليها parent_name
      if (form.phones && form.phones.length > 0) {
        for (let i = 0; i < form.phones.length; i++) {
          const phone = form.phones[i];
          await addStudentPhone(studentId, {
            phone_number: phone.phone_number,
            country_code: phone.country_code || "+20",
            is_personal: phone.is_personal,
            is_parent: phone.is_parent,
            is_whatsapp: phone.is_whatsapp,
            is_call: phone.is_call,
            is_primary: phone.is_primary ?? (i === 0), // أول رقم أساسي تلقائياً
            parent_name: phone.parent_name || undefined,
            parent_relationship: phone.parent_relationship || undefined,
          });
        }
      }
      setShowForm(false);
      loadStudents();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل الحفظ"); }
  }

  async function handleDelete(id: number, name: string) {
    const ok = await confirm({
      title: "حذف الطالب",
      message: `متأكد إنك عايز تحذف الطالب «${name}»؟\n\n• الحصص والفواتير السابقة ليه هتفضل في التقارير.\n• البيانات هتتشال من القوائم النشطة على طول.`,
      confirmLabel: "احذف الطالب",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;
    try {
      await deleteStudent(id);
      toast.success("تم حذف الطالب", name);
      setSelectedIds((prev) => prev.filter((i) => i !== id));
      loadStudents();
    } catch (err) {
      toast.error("فشل حذف الطالب", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  function toggleSelect(id: number) {
    setSelectedIds((prev) => prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]);
  }

  function toggleSelectAll() {
    if (selectedIds.length === filteredStudents.length) setSelectedIds([]);
    else setSelectedIds(filteredStudents.map((s) => s.id));
  }

  function toggleSmartSegment(key: string) {
    setSmartSegment((prev) => prev === key ? "" : key);
  }

  /* ============================================================
     الإجراءات الجماعية — بتطلب اختيار المعلم/المبلغ/نص الإشعار
     من مودال، وبعدين بتنادي الـ API فعلاً
     ========================================================== */
  const [bulkBusy, setBulkBusy] = useState<string | null>(null);

  async function handleBulkChangeTeacher() {
    if (selectedIds.length === 0) return;

    const picked = await prompt({
      title: "تغيير المدرس",
      message: `هتغيّر مدرس ${selectedIds.length} طالب. هيتم التحديث على الاشتراكات النشطة والحصص المجدولة بس — أما الحصص السابقة فتفضل زي ما هي عشان التقارير.`,
      label: "اختر المعلم الجديد",
      options: teachers
        .filter((t) => t.status === "active")
        .map((t) => ({ value: String(t.id), label: t.full_name, description: t.specialization ?? undefined })),
      required: true,
      confirmLabel: "غيّر المدرس",
    });
    if (!picked) return;

    const teacher = teachers.find((t) => String(t.id) === picked);
    setBulkBusy("teacher");
    try {
      const r = await bulkChangeTeacher({ student_ids: selectedIds, teacher_id: Number(picked) });
      toast.success(
        `تم تغيير مدرس ${r.students} طالب إلى ${r.teacher.full_name}`,
        `${r.subscriptions_updated} اشتراك · ${r.lessons_updated} حصة مجدولة`,
      );
      setSelectedIds([]);
      loadStudents();
    } catch (err) {
      toast.error("فشل تغيير المدرس", err instanceof Error ? err.message : undefined);
    } finally {
      setBulkBusy(null);
    }
  }

  async function handleBulkCreateInvoices() {
    if (selectedIds.length === 0) return;

    const amountStr = await prompt({
      title: "إنشاء فواتير",
      message: `هنعمل فاتورة واحدة لكل طالب عنده اشتراك نشط (من ${selectedIds.length} محدد).\nسيبها فاضي عشان ناخد المبلغ من سعر اشتراك كل طالب — أو اكتب مبلغ موحّد للجميع.`,
      label: "المبلغ لكل فاتورة (اختياري)",
      placeholder: "مثال: 500 — اتركه فاضي للاشتراك",
      validate: (v) => (v && Number(v) <= 0 ? "المبلغ لازم يكون أكبر من صفر" : null),
      confirmLabel: "أنشئ الفواتير",
    });
    if (amountStr === null) return;

    setBulkBusy("invoices");
    try {
      const r = await bulkCreateInvoices({
        student_ids: selectedIds,
        amount: amountStr ? Number(amountStr) : undefined,
      });

      if (r.created_count === 0) {
        toast.warning(
          "مفيش فواتير اتعملت",
          "الطلاب المحددين كلهم مفيش ليهم اشتراك نشط.",
        );
      } else if (r.skipped_count > 0) {
        toast.warning(
          // ⭐ `num` + `egp` من `lib/format` — مش `toLocaleString` مباشرة
          `تم إنشاء ${num(r.created_count, 0)} فاتورة بإجمالي ${egp(r.total_amount)}`,
          `${num(r.skipped_count, 0)} طالب اتخطوا (مفيش ليهم اشتراك نشط).`,
        );
      } else {
        toast.success(
          `تم إنشاء ${num(r.created_count, 0)} فاتورة`,
          `إجمالي ${egp(r.total_amount)}`,
        );
      }
      setSelectedIds([]);
      loadStudents();
    } catch (err) {
      toast.error("فشل إنشاء الفواتير", err instanceof Error ? err.message : undefined);
    } finally {
      setBulkBusy(null);
    }
  }

  async function bulkSendNotification() {
    if (selectedIds.length === 0) return;

    const text = await prompt({
      title: "إرسال إشعار",
      message: `هيتسجل الإشعار لـ ${selectedIds.length} طالب.`,
      label: "نص الإشعار",
      placeholder: "مثال: اجتماع أولياء الأمور يوم الأحد الساعة 10",
      multiline: true,
      rows: 4,
      required: true,
      confirmLabel: "أرسل الإشعار",
    });
    if (!text) return;

    setBulkBusy("notify");
    try {
      const r = await bulkNotify({
        student_ids: selectedIds,
        title: "إشعار جديد",
        message: text,
        channel: "in_app",
      });
      toast.success(r.message, "الإشعار اتسجل في صفحة الإشعارات.");
      setSelectedIds([]);
    } catch (err) {
      toast.error("فشل إرسال الإشعار", err instanceof Error ? err.message : undefined);
    } finally {
      setBulkBusy(null);
    }
  }

  function bulkExportCsv() {
    const data = filteredStudents.filter((s) => selectedIds.includes(s.id));
    if (data.length === 0) {
      toast.warning("مفيش بيانات للتصدير");
      return;
    }
    const csv = data.map((s) => `${s.student_code},${s.full_name},${s.phone},${s.status}`).join("\n");
    const blob = new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `students-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success(`تم تصدير ${data.length} طالب`, a.download);
  }

  async function bulkDeleteStudents() {
    if (selectedIds.length === 0) return;

    const ok = await confirm({
      title: `حذف ${selectedIds.length} طالب`,
      message: `متأكد إنك عايز تحذف الطلاب المحددين كلهم؟\n\n• الحصص والفواتير السابقة هتفضل في التقارير.\n• العملية دي مش هتتراجع.`,
      confirmLabel: `احذف ${selectedIds.length} طالب`,
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;

    setBulkBusy("delete");
    let failed = 0;
    try {
      // حذف واحد واحد — الـ endpoint الحالي مافيش bulk delete
      for (const id of selectedIds) {
        try {
          await deleteStudent(id);
        } catch {
          failed += 1;
        }
      }
      if (failed === 0) {
        toast.success(`تم حذف ${selectedIds.length} طالب`);
      } else {
        toast.warning(
          `تم حذف ${selectedIds.length - failed} طالب`,
          `${failed} فشلت — يمكن ليهم فواتير مدفوعة.`,
        );
      }
      setSelectedIds([]);
      loadStudents();
    } finally {
      setBulkBusy(null);
    }
  }

  // Report Functions
  const loadAttendanceReport = async () => {
    setReportLoading(true);
    try {
      const data = await getAttendanceReport({
        from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
        to: new Date().toISOString().split('T')[0],
      });
      setAttendanceReport(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل تحميل تقرير الحضور");
    } finally {
      setReportLoading(false);
    }
  };

  const loadSubscriptionReport = async () => {
    setReportLoading(true);
    try {
      const data = await getSubscriptionReport({
        from: new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0],
        to: new Date().toISOString().split('T')[0],
      });
      setSubscriptionReport(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل تحميل تقرير الاشتراكات");
    } finally {
      setReportLoading(false);
    }
  };

  const handlePrintTable = () => {
    const printWindow = window.open("", "_blank");
    if (!printWindow) {
      toast.warning(
        "المتصفح 막ّ نافذة الطباعة",
        "اسمح بالنوافذ المنبثقة (Popups) للموقع من إعدادات المتصفح وبعدين جرّب تاني.",
        8000,
      );
      return;
    }

    const now = new Date();
    // ⭐ `dateSmart` مش `dateLong` — التقرير بيقول إمتى اتعمل،
    // فلازم يظهر **السنة** لو التقرير من سنة فاتتة
    const dateStr = dateSmart(now);
    const timeStr = time(now);

    // Build filter description
    const activeFilters: string[] = [];
    if (filterStatus) activeFilters.push(`الحالة: ${STATUS_LABEL[filterStatus as Student["status"]]}`);
    if (filterCountry) activeFilters.push(`الدولة: ${COUNTRIES.find(c => c.code === filterCountry)?.name ?? filterCountry}`);
    if (filterProgram) activeFilters.push(`البرنامج: ${programs.find(p => p.id === Number(filterProgram))?.name ?? filterProgram}`);
    if (filterTeacher) activeFilters.push(`المدرس: ${teachers.find(t => t.id === Number(filterTeacher))?.full_name ?? filterTeacher}`);
    if (searchQuery) activeFilters.push(`البحث: "${searchQuery}"`);
    if (smartSegment === "expiring") activeFilters.push("شريحة: اشتراكات تنتهي قريبًا");
    if (smartSegment === "trials") activeFilters.push("شريحة: طلاب التجربة");

    const visibleCols = ALL_COLUMNS.filter(c => c.visible);
    const colsToPrint = visibleCols.map(c => c.label);

    const html = `
      <!DOCTYPE html>
      <html dir="rtl" lang="ar">
      <head>
        <meta charset="UTF-8">
        <title>تقرير الطلاب - ${dateStr}</title>
        <style>
          body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 20px; direction: rtl; }
          .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
          .header h1 { margin: 0; color: #1f2937; font-size: 24px; }
          .header .meta { color: #6b7280; font-size: 14px; margin-top: 5px; }
          .filters { background: #f9fafb; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
          .filters strong { color: #374151; }
          table { width: 100%; border-collapse: collapse; font-size: 12px; }
          /* ⭐ start مش right — عشان لو الورقة اتقلبتلهوش RTL
             الأرقام تفصل عن الكلام العربي */
          th, td { border: 1px solid #d1d5db; padding: 8px 6px; text-align: start; }
          th { background: #f3f4f6; font-weight: 600; color: #374151; }
          tr:nth-child(even) td { background: #f9fafb; }
          .status-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 500; }
          .status-active { background: #dcfce7; color: #166534; }
          .status-lead { background: #dbeafe; color: #1e40af; }
          .status-paused { background: #fef3c7; color: #92400e; }
          .status-inactive { background: #fee2e2; color: #991b1b; }
          .status-graduated { background: #f3e8ff; color: #7e22ce; }
          .status-archived { background: #fecaca; color: #991b1b; }
          .text-center { text-align: center; }
          .text-end { text-align: end; }
          @media print { body { margin: 0; } .no-print { display: none; } }
        </style>
      </head>
      <body>
        <div class="header">
          <h1>تقرير طلاب أكاديمية القرآن</h1>
          <div class="meta">تاريخ الطباعة: ${dateStr} - ${timeStr} | عدد السجلات: ${filteredStudents.length}</div>
        </div>

        ${activeFilters.length > 0 ? `
        <div class="filters">
          <strong>الفلاتر المطبقة:</strong> ${activeFilters.join(" | ") || "لا توجد فلاتر"}
        </div>
        ` : ""}

        <table>
          <thead>
            <tr>
              ${colsToPrint.map(label => `<th>${label}</th>`).join("")}
            </tr>
          </thead>
          <tbody>
            ${filteredStudents.map(s => {
              const sub = subscriptions.find(sub => sub.student_id === s.id);
              const country = COUNTRIES.find(c => c.code === s.country_code);
              const phones = ('phones' in s ? (s as StudentWithRelations).phones : undefined) ?? [];
              const primary = phones.find(p => p.is_primary) ?? phones[0];
              const phoneDisplay = primary ? primary.phone_number : "—";
              const statusClass = `status-${s.status}`;
              return `
                <tr>
                  ${visibleCols.map(col => {
                    let cellContent = "";
                    switch(col.key) {
                      case "student":
                        cellContent = `<div><strong>${s.full_name}</strong><br><small>${s.student_code}</small></div>`;
                        break;
                      case "program":
                        cellContent = sub ? programs.find(p => p.id === sub.program_id)?.name ?? "—" : "—";
                        break;
                      case "teacher":
                        cellContent = sub ? teachers.find(t => t.id === sub.teacher_id)?.full_name ?? "—" : "—";
                        break;
                      case "subscription":
                        if (sub) {
                          const statusMap: Record<string, string> = { active: "نشط", expired: "منتهي", paused: "متوقف" };
                          cellContent = `<span class="status-badge ${statusClass}">${statusMap[sub.status] ?? sub.status}</span>`;
                          if (sub.end_date) {
                            const days = Math.ceil((new Date(sub.end_date).getTime() - Date.now()) / 86400000);
                            if (days > 0) cellContent += `<br><small>${days} يوم متبقي</small>`;
                          }
                        } else cellContent = "—";
                        break;
                      case "lessons":
                        cellContent = String((s as StudentWithRelations).lessons_count ?? 0);
                        break;
                      case "finance":
                        cellContent = sub ? money(sub.price, sub.currency) : "—";
                        break;
                      case "status":
                        const statusLabels: Record<string, string> = { lead: "Lead", active: "نشط", paused: "متوقف", inactive: "غير نشط", graduated: "تخرج", archived: "مؤرشف" };
                        cellContent = `<span class="status-badge ${statusClass}">${statusLabels[s.status]}</span>`;
                        break;
                      case "country":
                        cellContent = country ? `${country.flag} ${country.name}` : "—";
                        break;
                      case "whatsapp":
                        cellContent = phoneDisplay;
                        break;
                      case "date_of_birth":
                        cellContent = s.date_of_birth ? date(s.date_of_birth) : "—";
                        break;
                      case "next_lesson":
                        // ⭐ `dateNoYear` — الحصة الجاية، السنة بتزحم
                        cellContent = s.next_lesson_date ? dateNoYear(s.next_lesson_date) : "—";
                        break;
                      default:
                        cellContent = "—";
                    }
                    return `<td>${cellContent}</td>`;
                  }).join("")}
                </tr>
              `;
            }).join("")}
          </tbody>
        </table>

        <script>
          window.onload = function() { window.print(); };
        </script>
      </body>
      </html>
    `;

    printWindow.document.write(html);
    printWindow.document.close();
  };

  const displayStudent = studentDetail?.student ?? selectedStudent;
  const studentSub = displayStudent ? subscriptions.find((sub) => sub.student_id === displayStudent.id) ?? studentDetail?.subscription ?? null : null;

  const months = useMemo(() => {
    const result: { label: string; value: string }[] = [];
    const now = new Date();
    for (let i = 0; i < 6; i++) {
      const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      // ⭐ «أكتوبر 2026» — اسم الشهر والسنة، من غير يوم
      result.push({ label: monthYear(d), value: d.toISOString().split("T")[0] });
    }
    return result;
  }, []);

  return (
    <div>
      {/* Header */}
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-xl font-bold text-slate-800">الطلاب</h1>
        <div className="flex items-center gap-2">
          <button onClick={() => { setReportType('attendance'); loadAttendanceReport(); setShowReports(true); }} className="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">📊 تقارير</button>
          <button onClick={handlePrintTable} className="rounded-lg bg-slate-700 px-4 py-2 text-sm font-medium text-white hover:bg-slate-600">🖨 طباعة الجدول</button>
          <button onClick={openCreate} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">+ إضافة طالب</button>
        </div>
      </div>

      {/* Stats Dashboard */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-5">
        {[
          { label: "إجمالي الطلاب", value: stats.total, color: "bg-slate-800 text-white", key: "" },
          { label: "نشطون", value: stats.active, color: "bg-green-100 text-green-800", key: "active" },
          { label: "متوقفون", value: stats.paused, color: "bg-amber-100 text-amber-800", key: "paused" },
          { label: "منتهون", value: stats.expired, color: "bg-red-100 text-red-800", key: "inactive" },
          { label: "تجربة", value: stats.trials, color: "bg-blue-100 text-blue-800", key: "lead" },
        ].map((stat) => (
          <button key={stat.label} onClick={() => { setFilterStatus(stat.key); setSmartSegment(""); }} className={`rounded-xl p-4 text-center transition-shadow hover:shadow-md ${stat.color}`}>
            <p className="text-3xl font-bold">{stat.value}</p>
            <p className="mt-1 text-sm">{stat.label}</p>
          </button>
        ))}
      </div>

      {/* Smart Segments */}
      <div className="mb-4 flex gap-2">
        <span className="text-sm font-medium text-slate-600">شرائح ذكية:</span>
        {[{ key: "expiring", label: "اشتراكات تنتهي قريبًا" }, { key: "trials", label: "طلاب التجربة" }].map((seg) => (
          <button key={seg.key} onClick={() => toggleSmartSegment(seg.key)} className={`rounded-lg px-3 py-1.5 text-sm transition-colors ${smartSegment === seg.key ? "bg-purple-600 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}>
            {smartSegment === seg.key ? "✓ " : ""}{seg.label}
          </button>
        ))}
      </div>

      {/* Search + Filters */}
      <div className="mb-4 flex items-center gap-3">
        <div className="flex-1">
          <input placeholder="🔎 ابحث بالاسم / الهاتف / الكود / البريد..." value={searchQuery} onChange={(e) => setSearchQuery(e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm" />
        </div>
        <button onClick={() => { if (showFilters) { setFilterStatus(""); setFilterCountry(""); setFilterProgram(""); setFilterTeacher(""); setSmartSegment(""); setSearchQuery(""); } setShowFilters(!showFilters); }} className={`rounded-xl px-4 py-2.5 text-sm transition-colors ${showFilters ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}>⚙ الفلاتر</button>
        <button onClick={() => setShowColumns(!showColumns)} className={`rounded-xl px-4 py-2.5 text-sm transition-colors ${showColumns ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}>⚙ الأعمدة</button>
      </div>

      {/* Filters Panel */}
      {showFilters && (
        <div className="mb-4 rounded-xl border border-slate-200 bg-white p-4">
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">كل الحالات</option>
              {Object.entries(STATUS_LABEL).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
            <select value={filterCountry} onChange={(e) => setFilterCountry(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">كل الدول</option>
              {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.flag} {c.name}</option>)}
            </select>
            <select value={filterProgram} onChange={(e) => setFilterProgram(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">كل البرامج</option>
              {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
            <select value={filterTeacher} onChange={(e) => setFilterTeacher(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">كل المدرسين</option>
              {teachers.map((t) => <option key={t.id} value={t.id}>{t.full_name}</option>)}
            </select>
          </div>
        </div>
      )}

      {/* Column Manager */}
      {showColumns && (
        <div className="mb-4 rounded-xl border border-slate-200 bg-white p-4">
          <h3 className="mb-3 text-sm font-semibold text-slate-800">إدارة الأعمدة</h3>
          <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
            {ALL_COLUMNS.map((col) => (
              <label key={col.key} className="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" checked={visibleColumns.find((c) => c.key === col.key)?.visible ?? false}
                  onChange={(e) => setVisibleColumns((prev) => prev.map((c) => c.key === col.key ? { ...c, visible: e.target.checked } : c))} className="rounded" />
                {col.label}
              </label>
            ))}
          </div>
        </div>
      )}

      {/* Bulk Actions — شريط الإجراءات الجماعية */}
      {selectedIds.length > 0 && (
        <div className="sticky top-3 z-40 mb-4 rounded-2xl bg-slate-800 p-3 text-white shadow-lg shadow-slate-900/20">
          <div className="flex flex-wrap items-center gap-3">
            <span className="inline-flex items-center gap-2 rounded-xl bg-white/15 px-3 py-1.5 text-sm font-semibold">
              <span className="flex size-6 items-center justify-center rounded-full bg-white text-xs font-bold text-slate-800">
                {num(selectedIds.length, 0)}
              </span>
              طالب محدد
            </span>

            <div className="flex flex-wrap gap-2">
              <button
                onClick={handleBulkChangeTeacher}
                disabled={bulkBusy !== null}
                className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-medium transition hover:bg-white/25 disabled:opacity-50"
              >
                {bulkBusy === "teacher" ? "جارٍ التنفيذ..." : "👨‍🏫 تغيير المدرس"}
              </button>
              <button
                onClick={handleBulkCreateInvoices}
                disabled={bulkBusy !== null}
                className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-medium transition hover:bg-white/25 disabled:opacity-50"
              >
                {bulkBusy === "invoices" ? "جارٍ الإنشاء..." : "🧾 إنشاء فواتير"}
              </button>
              <button
                onClick={bulkSendNotification}
                disabled={bulkBusy !== null}
                className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-medium transition hover:bg-white/25 disabled:opacity-50"
              >
                {bulkBusy === "notify" ? "جارٍ الإرسال..." : "🔔 إرسال إشعار"}
              </button>
              <button
                onClick={bulkExportCsv}
                disabled={bulkBusy !== null}
                className="inline-flex items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-medium transition hover:bg-white/25 disabled:opacity-50"
              >
                ⬇️ تصدير CSV
              </button>
              <button
                onClick={bulkDeleteStudents}
                disabled={bulkBusy !== null}
                className="inline-flex items-center gap-1.5 rounded-lg bg-red-500/90 px-3 py-1.5 text-xs font-medium transition hover:bg-red-500 disabled:opacity-50"
              >
                {bulkBusy === "delete" ? "جارٍ الحذف..." : "🗑 حذف"}
              </button>
            </div>

            <button
              onClick={() => setSelectedIds([])}
              className="ms-auto rounded-lg px-3 py-1.5 text-xs text-slate-300 transition hover:bg-white/10 hover:text-white"
            >
              إلغاء التحديد
            </button>
          </div>
        </div>
      )}

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {/* Students Table */}
      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-3 py-3"><input type="checkbox" checked={selectedIds.length === filteredStudents.length && filteredStudents.length > 0} onChange={toggleSelectAll} className="rounded" /></th>
                {visibleColumns.filter((c) => c.visible).map((col) => <th key={col.key} className="px-4 py-3 text-start">{col.label}</th>)}
                <th className="px-4 py-3 text-start">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {filteredStudents.length === 0 && <tr><td colSpan={visibleColumns.filter((c) => c.visible).length + 2} className="px-4 py-6 text-center text-slate-400">لا يوجد طلاب</td></tr>}
              {filteredStudents.map((s) => {
                const sub = subscriptions.find((sub) => sub.student_id === s.id);
                const country = COUNTRIES.find((c) => c.code === s.country_code);
                return (
                  <tr key={s.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-3 py-3"><input type="checkbox" checked={selectedIds.includes(s.id)} onChange={() => toggleSelect(s.id)} className="rounded" /></td>
                    {visibleColumns.filter((c) => c.visible).map((col) => (
                      <td key={col.key} className="px-4 py-3">
                        {col.key === "student" && <div><p className="font-medium text-slate-800">{s.full_name}</p><p className="text-xs text-slate-400">{s.student_code}</p></div>}
                        {col.key === "program" && <span className="text-slate-600">{sub ? programs.find((p) => p.id === sub.program_id)?.name ?? "—" : "—"}</span>}
                        {col.key === "teacher" && <span className="text-slate-600">{sub ? teachers.find((t) => t.id === sub.teacher_id)?.full_name ?? "—" : "—"}</span>}
                        {col.key === "subscription" && sub && renderSubscriptionColumn(sub)}
                        {col.key === "lessons" && <span className="text-slate-600">{(s as StudentWithRelations).lessons_count ?? 0}</span>}
                        {col.key === "finance" && <span className="text-slate-600">{sub ? money(sub.price, sub.currency) : "—"}</span>}
                        {col.key === "status" && <span className={`rounded-full px-2 py-1 text-xs ${STATUS_COLORS[s.status]}`}>{STATUS_LABEL[s.status]}</span>}
                        {col.key === "country" && <span className="text-slate-600">{country ? `${country.flag} ${country.name}` : "—"}</span>}
                        {col.key === "whatsapp" && renderPhoneColumn(s)}
                        {col.key === "date_of_birth" && <span className="text-slate-600">{s.date_of_birth ? date(s.date_of_birth) : "—"}</span>}
                        {col.key === "next_lesson" && <span className="text-slate-600">{renderNextLessonColumn(s)}</span>}
                      </td>
                    ))}
                    <td className="px-4 py-3">
                      <div className="flex gap-1">
                        <button onClick={() => setSelectedStudent(s)} className="rounded bg-slate-100 px-2 py-1 text-xs text-slate-600 hover:bg-slate-200">👁</button>
                        <button onClick={() => openEdit(s)} className="rounded bg-blue-50 px-2 py-1 text-xs text-blue-600 hover:bg-blue-100">✏</button>
                        <button onClick={() => handleDelete(s.id, s.full_name)} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100">🗑</button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* ===== Pagination ===== */}
      {/* ===== Pagination ===== */}
      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="طالب"
      />

      {/* Add/Edit Form Modal */}
      {showForm && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center overflow-y-auto p-4">
          <div className="ui-anim-backdrop fixed inset-0 bg-slate-900/45 backdrop-blur-[2px]" onClick={() => setShowForm(false)} />
          <div className="ui-anim-dialog relative my-8 w-full max-w-2xl rounded-2xl bg-white shadow-2xl shadow-slate-900/25">
            <form onSubmit={handleSubmit} className="max-h-[85vh] overflow-y-auto">
              <div className="p-5 space-y-4">
                  <input placeholder="الاسم الأول *" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              <div className="grid grid-cols-2 gap-3">
                  <input placeholder="اسم العائلة *" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                  <input placeholder="الاسم الأوسط" value={form.middle_name} onChange={(e) => setForm({ ...form, middle_name: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">تاريخ الميلاد</label>
                    <input type="date" value={form.date_of_birth} onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  </div>
                  <select value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">الجنس</option><option value="male">ذكر</option><option value="female">أنثى</option>
                  </select>
                  <select value={form.country_code} onChange={(e) => setForm({ ...form, country_code: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    <option value="">الدولة</option>
                    {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.flag} {c.name}</option>)}
                  </select>
                  <input placeholder="البريد الإلكتروني" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as Student["status"] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
                    {Object.entries(STATUS_LABEL).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                  </select>
                </div>
              </div>

              {/* Section: Subscription */}
              <div className="rounded-lg bg-emerald-50 p-4 space-y-3 border border-emerald-100">
                <h4 className="font-medium text-emerald-700 flex items-center gap-2">📋 الاشتراك</h4>

                {/* ① البرنامج */}
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">البرنامج *</label>
                    <select value={form.program_id} onChange={(e) => setForm({ ...form, program_id: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                      <option value="">اختر البرنامج</option>
                      {programs.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">المدة</label>
                    <select value={form.lesson_duration_minutes} onChange={(e) => setForm({ ...form, lesson_duration_minutes: Number(e.target.value) })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                      <option value="30">30 دقيقة</option>
                      <option value="45">45 دقيقة</option>
                      <option value="60">60 دقيقة</option>
                    </select>
                  </div>
                </div>

                {/* ② أيام الحصة الأسبوعية — بتتحدد قبل اختيار المعلم */}
                <div className="rounded-lg border border-emerald-200 bg-white p-3">
                  <div className="mb-2 flex items-center justify-between">
                    <label className="text-xs font-medium text-slate-700">
                      أيام الحصة الأسبوعية <span className="text-red-500">*</span>
                    </label>
                    {form.weekdays.length > 0 && (
                      <span className="text-[11px] text-slate-500">
                        {form.weekdays.length} يوم في الأسبوع
                      </span>
                    )}
                  </div>

                  <div className="flex flex-wrap gap-1.5">
                    {DAYS_SHORT.map((day, i) => {
                      const on = form.weekdays.includes(i);
                      return (
                        <button
                          key={day}
                          type="button"
                          onClick={() => setForm({
                            ...form,
                            weekdays: on
                              ? form.weekdays.filter((d) => d !== i)
                              : [...form.weekdays, i].sort(),
                          })}
                          className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                            on
                              ? "bg-emerald-600 text-white shadow-sm"
                              : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                          }`}
                        >
                          {day}
                        </button>
                      );
                    })}
                  </div>

                  <div className="mt-2.5 grid grid-cols-2 gap-2">
                    <div>
                      <label className="mb-1 block text-xs text-slate-500">وقت الحصة</label>
                      <input
                        type="time"
                        value={form.start_time}
                        onChange={(e) => setForm({ ...form, start_time: e.target.value })}
                        className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                      />
                    </div>
                    <div>
                      <label className="mb-1 block text-xs text-slate-500">من / إلى</label>
                      <div className="flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">
                        <span className="tabular-nums">{form.start_date || "—"}</span>
                        <span className="text-slate-400">←</span>
                        <span className="tabular-nums">{form.end_date || "—"}</span>
                      </div>
                    </div>
                  </div>

                  <div className="mt-2.5 grid grid-cols-2 gap-2">
                    <div>
                      <label className="mb-1 block text-xs text-slate-500">تاريخ البداية</label>
                      <input type="date" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                      <label className="mb-1 block text-xs text-slate-500">تاريخ النهاية</label>
                      <input type="date" min={form.start_date || undefined} value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                  </div>

                  {/* معاينة التقويم */}
                  <div className="mt-2.5">
                    <p className="mb-1.5 text-xs font-medium text-slate-700">معاينة الحصص</p>
                    <LessonCalendarPreview
                      from={form.start_date}
                      to={form.end_date}
                      weekdays={form.weekdays}
                      durationMinutes={form.lesson_duration_minutes}
                    />
                  </div>
                </div>

                {/* ③ المعلم — مفلتر على المواعيد اللي فوق */}
                <TeacherPicker
                  weekdays={form.weekdays}
                  startTime={form.start_time}
                  durationMinutes={form.lesson_duration_minutes}
                  fromDate={form.start_date}
                  toDate={form.end_date}
                  value={form.teacher_id}
                  onChange={(id) => setForm({ ...form, teacher_id: id })}
                  requiredLessons={form.lessons_included}
                />

                {/* ④ باقي بيانات الاشتراك */}
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">نوع الفوترة *</label>
                    <select value={form.billing_type} onChange={(e) => setForm({ ...form, billing_type: e.target.value as "monthly" | "per_lesson" })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                      <option value="monthly">شهري</option>
                      <option value="per_lesson">لكل حصة</option>
                    </select>
                  </div>
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">الحصص المشمولة</label>
                    <input type="number" value={form.lessons_included} onChange={(e) => setForm({ ...form, lessons_included: Number(e.target.value) })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">السعر</label>
                    <input type="number" value={form.price} onChange={(e) => setForm({ ...form, price: Number(e.target.value) })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                  </div>
                  <div>
                    <label className="mb-1 block text-xs text-slate-500">العملة</label>
                    <select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                      <option value="EGP">جنيه مصري</option>
                      <option value="SAR">ريال سعودي</option>
                      <option value="USD">دولار أمريكي</option>
                    </select>
                  </div>
                </div>

              {/* Section: Phone Numbers — ولي الأمر بيتسجل من هنا */}
              <div className="rounded-lg bg-blue-50 p-4 space-y-3 border border-blue-100">
                <div className="flex items-center justify-between">
                  <h4 className="font-medium text-blue-700 flex items-center gap-2">📞 أرقام الهاتف</h4>
                  {!editingId && form.phones.length > 0 && (
                    <span className="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-700">
                      {form.phones.length} رقم مضاف
                    </span>
                  )}
                </div>
                {!editingId ? (
                  // Local phone management for new students
                  <div className="space-y-3">
                    <p className="text-xs text-slate-500">أضف أرقام الهاتف لهذا الطالب الجديد (سيتم حفظها مع إنشاء الطالب)</p>
                    <PhoneManagerLocal form={form} setForm={setForm} />
                  </div>
                ) : (
                  // Server-side phone management for existing students
                  <button type="button" onClick={() => { const student = students.find(s => s.id === editingId); if (student) openPhoneManager(student); }} className="w-full rounded-lg bg-blue-100 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-200 transition-colors">⚙ إدارة الأرقام</button>
                )}
              </div>

              {/* Section: Notes */}
              <div className="rounded-lg bg-slate-50 p-4 space-y-3">
                <h4 className="font-medium text-slate-700 flex items-center gap-2">📝 ملاحظات</h4>
                <textarea placeholder="ملاحظات إضافية..." value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm min-h-[80px] resize-none" rows={3} />
              </div>

              {/* Submit Buttons */}
              <div className="flex gap-2 pt-2 border-t border-slate-200">
                <button type="submit" className="flex-1 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                  {editingId ? "حفظ التعديلات" : "إضافة الطالب"}
                </button>
                <button type="button" onClick={() => setShowForm(false)} className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
              </div>
            </div>
            </form>
          </div>
        </div>
      )}
      {/* Student Profile Drawer */}
      {selectedStudent && (
        <div className="fixed inset-0 z-50 flex">
          <div
            className="flex-1 bg-slate-900/45 backdrop-blur-[2px]"
            onClick={() => { setSelectedStudent(null); setStudentDetail(null); }}
          />
          <div className="flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-slate-200 p-4">
              <div>
                <h2 className="text-lg font-bold text-slate-800">{displayStudent?.full_name}</h2>
                <p className="text-sm text-slate-500">
                  {displayStudent?.student_code} — {COUNTRIES.find((c) => c.code === displayStudent?.country_code)?.flag ?? "🏳️"} {COUNTRIES.find((c) => c.code === displayStudent?.country_code)?.name ?? displayStudent?.country_code ?? "—"}
                </p>
              </div>
              <div className="flex gap-2">
                <span className={`rounded-full px-3 py-1 text-xs ${displayStudent ? STATUS_COLORS[displayStudent.status] : "bg-slate-100"}`}>{displayStudent ? STATUS_LABEL[displayStudent.status] : ""}</span>
                <button onClick={() => { setSelectedStudent(null); setStudentDetail(null); }} className="text-slate-400 hover:text-slate-600">✕</button>
              </div>
            </div>
            <div className="flex gap-1 border-b border-slate-200 px-4">
              {[{ key: "overview", label: "نظرة عامة" }, { key: "academic", label: "الأكاديمي" }, { key: "lessons", label: "الحصص" }, { key: "subscription", label: "الاشتراك" }, { key: "guardians", label: "أولياء الأمور" }, { key: "finance", label: "المالية" }, { key: "attendance", label: "الحضور" }].map((tab) => (
                <button key={tab.key} onClick={() => setStudentTab(tab.key)} className={`shrink-0 rounded-t-lg px-4 py-2 text-sm ${studentTab === tab.key ? "bg-slate-100 font-medium text-slate-800" : "text-slate-500 hover:text-slate-700"}`}>{tab.label}</button>
              ))}
            </div>
            <div className="flex-1 overflow-y-auto p-4">
              {studentTab === "overview" && (
                <div className="space-y-4">
                  <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div className="rounded-xl bg-green-50 p-3 text-center"><p className="text-xs text-green-600">الاشتراك</p><p className="mt-1 text-lg font-bold text-green-800">{studentSub?.status === "active" ? "نشط" : studentSub?.status ?? "لا يوجد"}</p></div>
                    <div className="rounded-xl bg-blue-50 p-3 text-center"><p className="text-xs text-blue-600">الحصص</p><p className="mt-1 text-lg font-bold text-blue-800">{studentDetail?.lessons.length ?? 0}</p></div>
                    <div className="rounded-xl bg-purple-50 p-3 text-center"><p className="text-xs text-purple-600">الحضور</p><p className="mt-1 text-lg font-bold text-purple-800">{studentDetail?.attendance.length ?? 0}</p></div>
                    <div className="rounded-xl bg-amber-50 p-3 text-center"><p className="text-xs text-amber-600">الرصيد</p><p className="mt-1 text-lg font-bold text-amber-800">{studentSub ? money(studentSub.price, studentSub.currency) : "—"}</p></div>
                  </div>
                </div>
              )}
              {studentTab === "academic" && (
                <div className="space-y-4">
                  <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
                    <h3 className="mb-3 text-sm font-semibold text-blue-800">التقييم العام</h3>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                      <div className="rounded-lg bg-white p-2 text-center"><p className="text-xs text-slate-500">الحفظ</p><p className="text-lg font-bold text-green-700">80%</p></div>
                      <div className="rounded-lg bg-white p-2 text-center"><p className="text-xs text-slate-500">التجويد</p><p className="text-lg font-bold text-blue-700">60%</p></div>
                      <div className="rounded-lg bg-white p-2 text-center"><p className="text-xs text-slate-500">القراءة</p><p className="text-lg font-bold text-purple-700">90%</p></div>
                      <div className="rounded-lg bg-white p-2 text-center"><p className="text-xs text-slate-500">النطق</p><p className="text-lg font-bold text-amber-700">70%</p></div>
                    </div>
                  </div>
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">سجل التقدم</h3>
                    {(studentDetail?.progress ?? []).length === 0 && <p className="text-sm text-slate-400">لا يوجد سجل تقدم</p>}
                    {(studentDetail?.progress ?? []).slice(0, 10).map((p, i) => (
                      <div key={i} className="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
                        <div><p className="text-sm text-slate-800">{p.category}</p><p className="text-xs text-slate-500">{date(p.recorded_at)}</p></div>
                        <span className="text-sm font-medium text-slate-800">{p.score}/5</span>
                      </div>
                    ))}
                  </div>
                  {/* Previous Months */}
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">الأشهر السابقة</h3>
                    <div className="space-y-1">
                      {months.map((m) => (
                        <div key={m.value} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                          <span className="text-slate-600">{m.label}</span>
                          <span className="text-slate-400">—</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}
              {studentTab === "lessons" && (
                <div className="space-y-4">
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">الحصص</h3>
                    {(studentDetail?.lessons ?? []).length === 0 && <p className="text-sm text-slate-400">لا توجد حصص</p>}
                    {(studentDetail?.lessons ?? []).slice(0, 10).map((lesson) => (
                      <div key={lesson.id} className="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
                        {/* ⭐ `dateSmart` — كان `toLocaleString` بيطلع
                            السطر الطويل بالثواني */}
                        <div><p className="text-sm text-slate-800">{dateSmart(lesson.scheduled_start_at)}</p><p className="text-xs text-slate-500">{programs.find((p) => p.id === lesson.program_id)?.name ?? "—"}</p></div>
                        <span className={`rounded-full px-2 py-0.5 text-xs ${lesson.status === "completed" ? "bg-green-100 text-green-700" : lesson.status === "scheduled" ? "bg-yellow-100 text-yellow-700" : "bg-slate-100 text-slate-500"}`}>{lesson.status}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
              {studentTab === "subscription" && (
                <div className="space-y-4">
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-3 text-sm font-semibold text-slate-800">الاشتراك الحالي</h3>
                    {studentSub ? (
                      <div className="space-y-2 text-sm text-slate-600">
                        <p>البرنامج: {programs.find((p) => p.id === studentSub.program_id)?.name ?? "—"}</p>
                        <p>المعلم: {teachers.find((t) => t.id === studentSub.teacher_id)?.full_name ?? "—"}</p>
                        <p>بدأ: {studentSub.start_date}</p>
                        <p>ينتهي: {studentSub.end_date ?? "—"}</p>
                        <p>الحصص: {studentSub.lessons_included ?? "∞"}</p>
                        <p>القيمة: {money(studentSub.price, studentSub.currency)}</p>
                        <p>الحالة: {studentSub.status}</p>
                      </div>
                    ) : <p className="text-sm text-slate-400">لا يوجد اشتراك</p>}
                  </div>
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">الأشهر السابقة</h3>
                    <div className="space-y-1">
                      {months.map((m) => (
                        <div key={m.value} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                          <span className="text-slate-600">{m.label}</span>
                          <span className="text-slate-400">—</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}
              {studentTab === "guardians" && (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <h3 className="text-sm font-semibold text-slate-800">👨‍👩‍👧 أولياء الأمر ({guardians.length})</h3>
                    <button
                      onClick={loadGuardians}
                      className="rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-200"
                    >
                      ↻ تحديث
                    </button>
                  </div>

                  {guardiansLoading && <p className="text-sm text-slate-400">جارٍ التحميل...</p>}

                  {!guardiansLoading && guardians.length === 0 && (
                    <div className="rounded-lg border border-dashed border-slate-200 py-6 text-center">
                      <p className="text-sm text-slate-400">
                        لا يوجد أولياء أمر مسجلين — أضفهم من قسم أرقام الهاتف
                      </p>
                    </div>
                  )}

                  {guardians.map((g) => (
                    <div key={g.id} className="rounded-xl border border-slate-200 p-3">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="flex items-center gap-2">
                            <p className="truncate text-sm font-medium text-slate-800">{g.name}</p>
                            {g.is_primary && (
                              <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-700">
                                ⭐ الأساسي
                              </span>
                            )}
                          </div>
                          <p className="mt-0.5 text-xs text-slate-500" dir="ltr">{g.phone}</p>
                          <div className="mt-1.5 flex flex-wrap gap-1.5">
                            <span className="rounded-full bg-purple-50 px-2 py-0.5 text-[11px] text-purple-700">
                              {parentRelationshipLabel(g.relationship)}
                            </span>
                            {g.students_count > 1 && (
                              <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] text-blue-700">
                                ولي أمر لـ {g.students_count} طلاب
                              </span>
                            )}
                          </div>
                        </div>

                        {!g.is_primary && (
                          <button
                            onClick={() => handleSetPrimaryGuardian(g.id)}
                            disabled={guardianBusyId === g.id}
                            className="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                          >
                            {guardianBusyId === g.id ? "..." : "اجعله الأساسي"}
                          </button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
              {studentTab === "finance" && (
                <div className="space-y-4">
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">الرصيد الحالي</h3>
                    <p className="text-2xl font-bold text-slate-800">{studentSub ? money(studentSub.price, studentSub.currency) : "—"}</p>
                  </div>
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">الأشهر السابقة</h3>
                    <div className="space-y-1">
                      {months.map((m) => (
                        <div key={m.value} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                          <span className="text-slate-600">{m.label}</span>
                          <span className="text-slate-400">—</span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}
              {studentTab === "attendance" && (
                <div className="space-y-4">
                  <div className="rounded-xl border border-slate-200 p-4">
                    <h3 className="mb-2 text-sm font-semibold text-slate-800">سجل الحضور</h3>
                    {(studentDetail?.attendance ?? []).length === 0 && <p className="text-sm text-slate-400">لا يوجد سجل حضور</p>}
                    {(studentDetail?.attendance ?? []).slice(0, 10).map((a, i) => (
                      <div key={i} className="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
                        <span className="text-sm text-slate-600">{date(a.marked_at)}</span>
                        <span className={`rounded-full px-2 py-0.5 text-xs ${a.status === "present" ? "bg-green-100 text-green-700" : a.status === "absent" ? "bg-red-100 text-red-700" : "bg-amber-100 text-amber-700"}`}>
                          {a.status === "present" ? "حاضر" : a.status === "absent" ? "غائب" : "متأخر"}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Phone Manager Modal */}
      {showPhoneManager && phoneManagerStudent && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
          <div className="ui-anim-backdrop fixed inset-0 bg-slate-900/45 backdrop-blur-[2px]" onClick={() => setShowPhoneManager(false)} />
          <div className="ui-anim-dialog relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl shadow-slate-900/25">
            <div className="flex items-center justify-between mb-4">
              <h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
                <span className="flex size-8 items-center justify-center rounded-full bg-purple-50 text-base ring-4 ring-purple-50">📱</span>
                أرقام الطالب
                <span className="font-normal text-slate-500">· {phoneManagerStudent.full_name}</span>
              </h3>
              <button onClick={() => setShowPhoneManager(false)} aria-label="إغلاق" className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">✕</button>
            </div>

            {/* Add/Edit Phone Form */}
            <form onSubmit={handlePhoneSubmit} className="mb-6 rounded-lg bg-slate-50 p-4 space-y-3">
              <h4 className="font-medium text-slate-700">{phoneForm.editingId ? "تعديل رقم" : "إضافة رقم جديد"}</h4>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="mb-1 block text-xs text-slate-500">الرقم</label>
                  <input
                    type="tel"
                    placeholder="01xxxxxxxxx"
                    value={phoneForm.phone_number}
                    onChange={(e) => setPhoneForm({ ...phoneForm, phone_number: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm dir-ltr"
                    required
                    dir="ltr"
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs text-slate-500">الدولة</label>
                  <select
                    value={phoneForm.country_code}
                    onChange={(e) => setPhoneForm({ ...phoneForm, country_code: e.target.value })}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                  >
                    {COUNTRIES.map((c) => <option key={c.code} value={c.code}>{c.flag} {c.name} ({c.code})</option>)}
                  </select>
                </div>
              </div>

              <div className="flex flex-wrap gap-3">
                <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                  <input type="checkbox" checked={phoneForm.is_personal} onChange={(e) => setPhoneForm({ ...phoneForm, is_personal: e.target.checked })} className="rounded" />
                  شخصي
                </label>
                <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                  <input type="checkbox" checked={phoneForm.is_whatsapp} onChange={(e) => setPhoneForm({ ...phoneForm, is_whatsapp: e.target.checked })} className="rounded" />
                  واتساب 💬
                </label>
                <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                  <input type="checkbox" checked={phoneForm.is_call} onChange={(e) => setPhoneForm({ ...phoneForm, is_call: e.target.checked })} className="rounded" />
                  تواصل 📞
                </label>
                <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                  <input type="checkbox" checked={phoneForm.is_parent} onChange={(e) => setPhoneForm({ ...phoneForm, is_parent: e.target.checked })} className="rounded" />
                  ولي أمر 👨‍👩‍👧
                </label>
                <label className="flex items-center gap-1.5 text-sm text-slate-600 cursor-pointer">
                  <input type="checkbox" checked={phoneForm.is_primary} onChange={(e) => setPhoneForm({ ...phoneForm, is_primary: e.target.checked })} className="rounded" />
                  أساسي ⭐
                </label>
              </div>

              {phoneForm.is_parent && (
                <div className="grid grid-cols-2 gap-3 pt-2 border-t border-slate-200">
                  <input
                    placeholder="اسم ولي الأمر *"
                    value={phoneForm.parent_name}
                    onChange={(e) => setPhoneForm({ ...phoneForm, parent_name: e.target.value })}
                    className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                  />
                  <select
                    value={phoneForm.parent_relationship}
                    onChange={(e) => setPhoneForm({ ...phoneForm, parent_relationship: e.target.value })}
                    className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                  >
                    <option value="">صلة القرابة *</option>
                    {PARENT_RELATIONSHIPS.map((r) => (
                      <option key={r.value} value={r.value}>{r.label}</option>
                    ))}
                  </select>
                </div>
              )}

              <div className="flex gap-2 pt-2 border-t border-slate-200">
                <button type="submit" className="flex-1 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                  {phoneForm.editingId ? "حفظ التعديلات" : "إضافة الرقم"}
                </button>
                <button type="button" onClick={resetPhoneForm} className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">
                  مسح النموذج
                </button>
              </div>
            </form>

            {/* Phones List */}
            <div className="rounded-xl border border-slate-200">
              <h4 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">أرقام الطالب ({studentPhones[phoneManagerStudent.id]?.length ?? 0})</h4>
              {studentPhones[phoneManagerStudent.id]?.length === 0 ? (
                <p className="px-4 py-6 text-center text-slate-400">لا توجد أرقام مسجلة</p>
              ) : (
                <ul className="divide-y divide-slate-100">
                  {studentPhones[phoneManagerStudent.id]?.map((phone) => (
                    <li key={phone.id} className="p-4 hover:bg-slate-50">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3">
                          <span className="font-mono text-lg font-medium text-slate-800" dir="ltr">{phone.phone_number}</span>
                          <div className="flex gap-1.5">
                            {phone.is_personal && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-50 text-blue-700">👤 شخصي</span>}
                            {phone.is_whatsapp && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">💬 واتساب</span>}
                            {phone.is_call && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-700">📞 تواصل</span>}
                            {phone.is_parent && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-purple-50 text-purple-700">👨‍👩‍👧 ولي أمر</span>}
                            {phone.is_primary && <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-50 text-amber-700">⭐ أساسي</span>}
                          </div>
                        </div>
                        <div className="flex items-center gap-2">
                          {phone.is_parent && phone.parent_name && (
                            <span className="text-xs text-purple-600">{phone.parent_name} ({parentRelationshipLabel(phone.parent_relationship)})</span>
                          )}
                          <div className="flex gap-1">
                            <button onClick={() => editPhone(phone)} className="rounded bg-slate-100 px-2 py-1 text-xs text-slate-600 hover:bg-slate-200" title="تعديل">✏</button>
                            {!phone.is_primary && (
                              <button onClick={() => handleSetPrimaryPhone(phone.id)} className="rounded bg-amber-50 px-2 py-1 text-xs text-amber-600 hover:bg-amber-100" title="تعيين كافتراضي">⭐</button>
                            )}
                            <button onClick={() => handleDeletePhone(phone.id, phone.phone_number)} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100" title="حذف">🗑</button>
                          </div>
                        </div>
                      </div>
                      {/* Quick Actions Row */}
                      <div className="mt-2 flex gap-2">
                        {phone.is_call && (
                          <a href={`tel:${phone.phone_number}`} className="inline-flex items-center gap-1 rounded bg-blue-50 px-3 py-1.5 text-xs text-blue-600 hover:bg-blue-100">
                            📞 اتصال
                          </a>
                        )}
                        {phone.is_whatsapp && (
                          <a href={`https://wa.me/${phone.phone_number.replace(/[^0-9]/g, '')}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 rounded bg-green-50 px-3 py-1.5 text-xs text-green-600 hover:bg-green-100">
                            💬 واتساب
                          </a>
                        )}
                        <button onClick={() => navigator.clipboard.writeText(phone.phone_number)} className="inline-flex items-center gap-1 rounded bg-slate-50 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100">
                          📋 نسخ
                        </button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Reports Modal */}
      {showReports && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4">
          <div className="ui-anim-backdrop absolute inset-0 bg-slate-900/45 backdrop-blur-[2px]" onClick={() => setShowReports(false)} />
          <div className="ui-anim-dialog relative max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl shadow-slate-900/25">
            <div className="flex items-center justify-between mb-4">
              <h3 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
                <span className="flex size-8 items-center justify-center rounded-full bg-slate-100 text-slate-600 ring-4 ring-slate-100">📊</span>
                تقارير الطلاب
              </h3>
              <button onClick={() => setShowReports(false)} aria-label="إغلاق" className="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600">✕</button>
            </div>

            {/* Report Type Tabs */}
            <div className="flex gap-2 mb-4 border-b border-slate-200">
              <button
                onClick={() => { setReportType('attendance'); if (!attendanceReport) loadAttendanceReport(); }}
                className={`pb-2 px-3 text-sm font-medium border-b-2 transition-colors ${reportType === 'attendance' ? 'border-purple-600 text-purple-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
              >
                📅 الحضور والغياب
              </button>
              <button
                onClick={() => { setReportType('subscriptions'); if (!subscriptionReport) loadSubscriptionReport(); }}
                className={`pb-2 px-3 text-sm font-medium border-b-2 transition-colors ${reportType === 'subscriptions' ? 'border-emerald-600 text-emerald-600' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
              >
                📋 حالة الاشتراكات
              </button>
            </div>

            {reportLoading && (
              <div className="flex items-center justify-center py-8">
                <div className="animate-spin rounded-full h-8 w-8 border-4 border-purple-600 border-t-transparent"></div>
                <span className="ml-2 text-slate-600">جاري تحميل التقرير...</span>
              </div>
            )}

            {/* Attendance Report */}
            {reportType === 'attendance' && attendanceReport && (
              <div className="space-y-4">
                {/* Summary Cards */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                  <div className="rounded-xl bg-blue-50 p-4">
                    <p className="text-xs text-blue-600">إجمالي الحصص المجدولة</p>
                    <p className="mt-1 text-2xl font-bold text-blue-800">{attendanceReport.summary.total_lessons_scheduled}</p>
                  </div>
                  <div className="rounded-xl bg-green-50 p-4">
                    <p className="text-xs text-green-600">معدل الحضور</p>
                    <p className="mt-1 text-2xl font-bold text-green-800">{attendanceReport.summary.attendance_rate}</p>
                  </div>
                  <div className="rounded-xl bg-amber-50 p-4">
                    <p className="text-xs text-amber-600">حاضر</p>
                    <p className="mt-1 text-2xl font-bold text-amber-800">{attendanceReport.summary.present}</p>
                  </div>
                  <div className="rounded-xl bg-red-50 p-4">
                    <p className="text-xs text-red-600">غائب</p>
                    <p className="mt-1 text-2xl font-bold text-red-800">{attendanceReport.summary.absent}</p>
                  </div>
                </div>

                {/* By Student Table */}
                <div className="rounded-xl border border-slate-200 overflow-hidden">
                  <h4 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">الحضور حسب الطالب ({attendanceReport.by_student?.length ?? 0})</h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-start">الطالب</th>
                          <th className="px-4 py-2 text-start">الكود</th>
                          <th className="px-4 py-2 text-center">إجمالي</th>
                          <th className="px-4 py-2 text-center">حاضر</th>
                          <th className="px-4 py-2 text-center">غائب</th>
                          <th className="px-4 py-2 text-center">متأخر</th>
                          <th className="px-4 py-2 text-center">نسبة الحضور</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {attendanceReport.by_student?.map((s: AttendanceReport['by_student'][0]) => (
                          <tr key={s.student_id} className="hover:bg-slate-50">
                            <td className="px-4 py-2">{s.student_name}</td>
                            <td className="px-4 py-2 text-slate-500">{s.student_code}</td>
                            <td className="px-4 py-2 text-center">{s.total_sessions}</td>
                            <td className="px-4 py-2 text-center text-green-600">{s.present}</td>
                            <td className="px-4 py-2 text-center text-red-600">{s.absent}</td>
                            <td className="px-4 py-2 text-center text-amber-600">{s.late}</td>
                            <td className="px-4 py-2 text-center font-medium">{s.attendance_rate}%</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* Daily Breakdown */}
                <div className="rounded-xl border border-slate-200 overflow-hidden">
                  <h4 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">الحضور اليومي</h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-start">التاريخ</th>
                          <th className="px-4 py-2 text-center">إجمالي</th>
                          <th className="px-4 py-2 text-center">حاضر</th>
                          <th className="px-4 py-2 text-center">غائب</th>
                          <th className="px-4 py-2 text-center">متأخر</th>
                          <th className="px-4 py-2 text-center">نسبة الحضور</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {attendanceReport.by_date?.slice(-14).reverse().map((d: AttendanceReport['by_date'][0]) => (
                          <tr key={d.date} className="hover:bg-slate-50">
                            {/* ⭐ «الأحد 7 أكتوبر» — يوم + يوم مختصر */}
                            <td className="px-4 py-2">
                              {weekday(d.date)} {dateNoYear(d.date)}
                            </td>
                            <td className="px-4 py-2 text-center">{num(d.total, 0)}</td>
                            <td className="px-4 py-2 text-center text-green-600">{num(d.present, 0)}</td>
                            <td className="px-4 py-2 text-center text-red-600">{num(d.absent, 0)}</td>
                            <td className="px-4 py-2 text-center text-amber-600">{num(d.late, 0)}</td>
                            {/* ⭐ `num` بحد أقصى رقم عشري — النسبة كسر */}
                            <td className="px-4 py-2 text-center font-medium">{num(d.rate, 1)}%</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            )}

            {/* Subscription Report */}
            {reportType === 'subscriptions' && subscriptionReport && (
              <div className="space-y-4">
                {/* Summary Cards */}
                <div className="grid grid-cols-2 md:grid-cols-6 gap-3">
                  <div className="rounded-xl bg-slate-800 p-4 text-white">
                    <p className="text-xs text-slate-300">إجمالي الاشتراكات</p>
                    <p className="mt-1 text-2xl font-bold">{subscriptionReport.summary.total}</p>
                  </div>
                  <div className="rounded-xl bg-green-50 p-4">
                    <p className="text-xs text-green-600">نشط</p>
                    <p className="mt-1 text-2xl font-bold text-green-800">{subscriptionReport.summary.active}</p>
                  </div>
                  <div className="rounded-xl bg-red-50 p-4">
                    <p className="text-xs text-red-600">منتهي</p>
                    <p className="mt-1 text-2xl font-bold text-red-800">{subscriptionReport.summary.expired}</p>
                  </div>
                  <div className="rounded-xl bg-amber-50 p-4">
                    <p className="text-xs text-amber-600">متوقف</p>
                    <p className="mt-1 text-2xl font-bold text-amber-800">{subscriptionReport.summary.paused}</p>
                  </div>
                  <div className="rounded-xl bg-purple-50 p-4">
                    <p className="text-xs text-purple-600">ملغي</p>
                    <p className="mt-1 text-2xl font-bold text-purple-800">{subscriptionReport.summary.cancelled}</p>
                  </div>
                  <div className="rounded-xl bg-orange-50 p-4">
                    <p className="text-xs text-orange-600">ينتهي قريباً</p>
                    <p className="mt-1 text-2xl font-bold text-orange-800">{subscriptionReport.summary.expiring_soon}</p>
                  </div>
                </div>

                {/* Revenue by Currency */}
                <div className="rounded-xl border border-slate-200 p-4">
                  <h4 className="mb-3 font-medium text-slate-800">الإيرادات بالعملة</h4>
                  <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    {Object.entries(subscriptionReport.revenue_by_currency || {}).map(([currency, data]: [string, { total: number; count: number }]) => (
                      <div key={currency} className="rounded-lg bg-slate-50 p-3 text-center">
                        {/* ⭐ `currencyLabel` — الكود `EGP` كان بيبان للمستخدم */}
                        <p className="text-xs text-slate-500">{currencyLabel(currency)}</p>
                        <p className="text-lg font-bold text-slate-800">
                          {money(data.total, currency)}
                        </p>
                        <p className="text-xs text-slate-500">{num(data.count, 0)} اشتراك</p>
                      </div>
                    ))}
                  </div>
                </div>

                {/* By Program */}
                <div className="rounded-xl border border-slate-200 overflow-hidden">
                  <h4 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">حسب البرنامج</h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-start">البرنامج</th>
                          <th className="px-4 py-2 text-center">إجمالي</th>
                          <th className="px-4 py-2 text-center">نشط</th>
                          <th className="px-4 py-2 text-center">منتهي</th>
                          <th className="px-4 py-2 text-start">الإيرادات</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {subscriptionReport.by_program?.map((p: SubscriptionReport['by_program'][0]) => (
                          <tr key={p.program_id} className="hover:bg-slate-50">
                            <td className="px-4 py-2">{p.program_name}</td>
                            <td className="px-4 py-2 text-center">{num(p.total, 0)}</td>
                            <td className="px-4 py-2 text-center text-green-600">{num(p.active, 0)}</td>
                            <td className="px-4 py-2 text-center text-red-600">{num(p.expired, 0)}</td>
                            <td className="px-4 py-2 text-start font-medium">{egp(p.revenue)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* By Teacher */}
                <div className="rounded-xl border border-slate-200 overflow-hidden">
                  <h4 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">حسب المدرس</h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-start">المدرس</th>
                          <th className="px-4 py-2 text-center">إجمالي الطلاب</th>
                          <th className="px-4 py-2 text-center">نشط</th>
                          <th className="px-4 py-2 text-center">منتهي</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {subscriptionReport.by_teacher?.map((t: SubscriptionReport['by_teacher'][0]) => (
                          <tr key={t.teacher_id} className="hover:bg-slate-50">
                            <td className="px-4 py-2">{t.teacher_name}</td>
                            <td className="px-4 py-2 text-center">{t.total_students}</td>
                            <td className="px-4 py-2 text-center text-green-600">{t.active}</td>
                            <td className="px-4 py-2 text-center text-red-600">{t.expired}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* Detailed List */}
                <div className="rounded-xl border border-slate-200 overflow-hidden">
                  <h4 className="px-4 py-3 border-b border-slate-200 font-medium text-slate-800">تفاصيل الاشتراكات ({subscriptionReport.details?.length ?? 0})</h4>
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="bg-slate-50">
                        <tr>
                          <th className="px-4 py-2 text-start">الطالب</th>
                          <th className="px-4 py-2 text-start">البرنامج</th>
                          <th className="px-4 py-2 text-start">المدرس</th>
                          <th className="px-4 py-2 text-center">الحالة</th>
                          <th className="px-4 py-2 text-center">أيام متبقية</th>
                          <th className="px-4 py-2 text-start">القيمة</th>
                          <th className="px-4 py-2 text-center">نوع الفوترة</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-slate-100">
                        {subscriptionReport.details?.map((s: SubscriptionReport['details'][0]) => (
                          <tr key={s.id} className={`hover:bg-slate-50 ${s.is_expiring_soon ? 'bg-orange-50' : ''}`}>
                            <td className="px-4 py-2">
                              <div>
                                <p className="font-medium">{s.student.name}</p>
                                <p className="text-xs text-slate-500">{s.student.code}</p>
                              </div>
                            </td>
                            <td className="px-4 py-2">{s.program}</td>
                            <td className="px-4 py-2">{s.teacher}</td>
                            <td className="px-4 py-2 text-center">
                              <span className={`inline-flex items-center px-2 py-1 rounded-full text-xs ${
                                s.status === 'active' ? 'bg-green-100 text-green-700' :
                                s.status === 'expired' ? 'bg-red-100 text-red-700' :
                                s.status === 'paused' ? 'bg-amber-100 text-amber-700' :
                                'bg-slate-100 text-slate-700'
                              }`}>
                                {s.status === 'active' ? 'نشط' : s.status === 'expired' ? 'منتهي' : s.status === 'paused' ? 'متوقف' : 'ملغي'}
                              </span>
                              {s.is_expiring_soon && <span className="ml-1 text-orange-600">⚠</span>}
                            </td>
                            <td className="px-4 py-2 text-center">
                              {s.days_left !== null ? (
                                s.days_left >= 0 ? `${s.days_left} يوم` : <span className="text-red-600">منتهي منذ {Math.abs(s.days_left)} يوم</span>
                              ) : '—'}
                            </td>
                            <td className="px-4 py-2 text-start font-medium">{money(s.price, s.currency)}</td>
                            <td className="px-4 py-2 text-center">{s.billing_type === 'monthly' ? 'شهري' : s.billing_type === 'per_lesson' ? 'لكل حصة' : 'مخصص'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            )}

            {!attendanceReport && !subscriptionReport && !reportLoading && (
              <div className="text-center py-8 text-slate-500">
                لا توجد بيانات للعرض
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

