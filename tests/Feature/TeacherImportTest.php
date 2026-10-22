<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TeacherImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function section(int $grade, string $name, ?User $teacher = null): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);

        return SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher?->id,
            'grade_level' => $grade, 'section' => $name, 'school_year' => '2026-2027', 'is_active' => true]);
    }

    private function upload(User $admin, string $csv)
    {
        return $this->actingAs($admin)->post(route('admin.teachers.import.preview'), [
            'teachers_file' => UploadedFile::fake()->createWithContent('teachers.csv', $csv),
        ]);
    }

    public function test_only_the_admin_can_open_the_import(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($teacher)->get(route('admin.teachers.import'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.teachers.import'))->assertOk();
    }

    public function test_import_creates_teachers_who_log_in_with_their_name_and_takes_free_sections(): void
    {
        $admin = $this->admin();
        $rizal = $this->section(4, 'Rizal');
        $taken = $this->section(5, 'Bonifacio', User::factory()->create(['role' => 'teacher']));

        $csv = "Teacher Name,Grade Level,Section,Phone\n"
             . "Ana Santos,Grade 4,rizal,0917\n"
             . "Ben Cruz,5,Bonifacio,\n"
             . "Cora Reyes,6,Nowhere,\n"
             . "Dan Lim,,,\n";

        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertSame($rizal->id, $rows[0]['class_id']);
        $this->assertNull($rows[1]['class_id']);   // section already has a teacher
        $this->assertNull($rows[2]['class_id']);   // section doesn't exist
        $this->assertNotNull($rows[2]['class_note']);
        $this->assertNull($rows[3]['class_note']); // no grade/section given: nothing to complain about

        $this->actingAs($admin)->post(route('admin.teachers.import.confirm'), ['rows' => [0, 1, 2, 3]])->assertOk();

        $ana = User::byEmail('Ana Santos')->first();
        $this->assertSame('teacher', $ana->role);
        $this->assertTrue($ana->is_active);
        $this->assertNotNull($ana->email_verified_at);
        $this->assertSame($ana->id, $rizal->fresh()->teacher_id);
        $this->assertNull(User::byEmail('Dan Lim')->first()->teacherClass ?? null);

        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => 'ana santos', 'password' => 'Bigkas123']));
    }

    public function test_two_rows_cannot_take_the_same_section_and_duplicate_names_get_a_suffix(): void
    {
        $admin = $this->admin();
        $this->section(3, 'Luna');

        $csv = "Teacher Name,Grade Level,Section\nMaria Cruz,3,Luna\nMaria Cruz,3,Luna\n";
        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertNotNull($rows[0]['class_id']);
        $this->assertNull($rows[1]['class_id']);
        $this->assertSame('Maria Cruz', $rows[0]['login']);
        $this->assertSame('Maria Cruz (2)', $rows[1]['login']);

        $this->actingAs($admin)->post(route('admin.teachers.import.confirm'), ['rows' => [0, 1]])->assertOk();
        $this->assertSame(2, User::where('role', 'teacher')->count());
    }
}
