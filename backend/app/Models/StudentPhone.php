<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class StudentPhone extends Model
{
    protected $fillable = [
        'student_id', 'phone_number', 'country_code', 'is_personal', 'is_parent',
        'is_whatsapp', 'is_call', 'is_primary', 'parent_name', 'parent_relationship',
    ];

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'is_parent' => 'boolean',
            'is_whatsapp' => 'boolean',
            'is_call' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function getPhoneTypeLabel(): string
    {
        $types = [];
        if ($this->is_personal) $types[] = 'شخصي';
        if ($this->is_parent) $types[] = 'ولي أمر';
        if ($this->is_whatsapp) $types[] = 'واتساب';
        if ($this->is_call) $types[] = 'تواصل';
        return implode(' + ', $types);
    }

    public function getPhoneTypeColor(): string
    {
        if ($this->is_parent) return 'text-purple-600';
        if ($this->is_whatsapp) return 'text-green-600';
        return 'text-blue-600';
    }

    protected static function booted(): void
    {
        // Ensure only one primary phone per student
        static::saving(function ($phone) {
            if ($phone->is_primary) {
                static::where('student_id', $phone->student_id)
                    ->where('id', '!=', $phone->id ?? 0)
                    ->update(['is_primary' => false]);
            }

            // Auto-set first phone as primary if none exists
            if (!$phone->exists) {
                $hasPrimary = static::where('student_id', $phone->student_id)
                    ->where('is_primary', true)
                    ->exists();
                if (!$hasPrimary) {
                    $phone->is_primary = true;
                }
            }

            // Validate parent fields
            if ($phone->is_parent && (empty($phone->parent_name) || empty($phone->parent_relationship))) {
                throw new \Illuminate\Validation\ValidationException(
                    \Illuminate\Validation\Validator::make([], [])
                );
            }
        });

        // Audit logging
        static::created(function ($phone) {
            self::logAudit($phone, 'created', null, $phone->toArray());
        });

        static::updated(function ($phone) {
            self::logAudit($phone, 'updated', $phone->getOriginal(), $phone->getChanges());
        });

        static::deleted(function ($phone) {
            self::logAudit($phone, 'deleted', $phone->toArray(), null);
        });
    }

    private static function logAudit($phone, $action, $oldValue, $newValue): void
    {
        try {
            \App\Models\AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'entity_type' => 'student_phone',
                'entity_id' => $phone->id,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Exception $e) {
            // Fail silently to not break the main operation
        }
    }

    public function formatForCountry(string $countryCode = '+20'): string
    {
        $number = preg_replace('/[^0-9]/', '', $this->phone_number);
        
        return match ($countryCode) {
            '+20' => $this->formatEgypt($number),
            '+966' => $this->formatSaudi($number),
            '+971' => $this->formatUAE($number),
            '+965' => $this->formatKuwait($number),
            '+974' => $this->formatQatar($number),
            default => $this->phone_number,
        };
    }

    private function formatEgypt(string $number): string
    {
        // Egypt: 01xxxxxxxxx (11 digits)
        if (str_starts_with($number, '20')) $number = substr($number, 2);
        if (str_starts_with($number, '0')) $number = substr($number, 1);
        if (strlen($number) === 10 && str_starts_with($number, '1')) {
            return '+20 ' . substr($number, 0, 3) . ' ' . substr($number, 3, 3) . ' ' . substr($number, 6);
        }
        return $this->phone_number;
    }

    private function formatSaudi(string $number): string
    {
        // Saudi: 05xxxxxxxx (10 digits)
        if (str_starts_with($number, '966')) $number = substr($number, 3);
        if (str_starts_with($number, '0')) $number = substr($number, 1);
        if (strlen($number) === 9 && str_starts_with($number, '5')) {
            return '+966 ' . substr($number, 0, 3) . ' ' . substr($number, 3, 3) . ' ' . substr($number, 6);
        }
        return $this->phone_number;
    }

    private function formatUAE(string $number): string
    {
        // UAE: 05xxxxxxxx (9 digits after country code)
        if (str_starts_with($number, '971')) $number = substr($number, 3);
        if (str_starts_with($number, '0')) $number = substr($number, 1);
        if (strlen($number) === 9 && str_starts_with($number, '5')) {
            return '+971 ' . substr($number, 0, 2) . ' ' . substr($number, 2, 3) . ' ' . substr($number, 5);
        }
        return $this->phone_number;
    }

    private function formatKuwait(string $number): string
    {
        // Kuwait: 8 digits
        if (str_starts_with($number, '965')) $number = substr($number, 3);
        if (strlen($number) === 8) {
            return '+965 ' . substr($number, 0, 4) . ' ' . substr($number, 4);
        }
        return $this->phone_number;
    }

    private function formatQatar(string $number): string
    {
        // Qatar: 8 digits
        if (str_starts_with($number, '974')) $number = substr($number, 3);
        if (strlen($number) === 8) {
            return '+974 ' . substr($number, 0, 4) . ' ' . substr($number, 4);
        }
        return $this->phone_number;
    }
}