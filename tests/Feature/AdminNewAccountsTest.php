<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNewAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function section(?User $teacher = null, string $name = 'Rizal'): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);

        return SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher?->id,
            'grade_level' => 4, 'section' => $name, 'school_year' => '2026-2027', 'is_active' => true]);
    }

    private function register(string $role, string $email = 'new@example.com', string $name = 'Newcomer Person')
    {
        $this->mock(\App\Services\OtpMailer::class, fn ($m) => $m->shouldIgnoreMissing());

        return $this->post('/register', [
            'name' => $name, 'email' => $email, 'role' => $role,
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ]);
    }

    // ── Notifying the admin ──

    public function test_admin_is_notified_when_a_teacher_registers(): void
    {
        $admin = $this->user('admin');
        $inactiveAdmin = $this->user('admin', ['is_active' => false]);
        $teacher = $this->user('teacher');

        $this->register('teacher', 'newteacher@example.com', 'Tea Cher');

        $n = $admin->fresh()->notifications()->firstOrFail();
        $this->assertSame('New teacher account', $n->data['title']);
        $this->assertStringContainsString('Tea Cher', $n->data['message']);
        $this->assertStringContainsString('needs a grade & section', $n->data['message']);
        $this->assertSame(route('admin.users', ['role' => 'teacher', 'unassigned' => 1]), $n->data['url']);

        $this->assertSame(0, $inactiveAdmin->fresh()->notifications()->count());   // inactive admins are skipped
        $this->assertSame(0, $teacher->fresh()->notifications()->count());          // only admins are told
    }

    public function test_parent_registration_is_announced_but_without_the_assign_prompt(): void
    {
        $admin = $this->user('admin');
        $this->register('parent', 'newparent@example.com', 'Pa Rent');

        $n = $admin->fresh()->notifications()->firstOrFail();
        $this->assertSame('New parent account', $n->data['title']);
        $this->assertStringNotContainsString('grade', $n->data['message']);
    }

    public function test_notification_appears_in_the_bell_and_opens_the_users_list(): void
    {
        $admin = $this->user('admin');
        $this->register('teacher', 'newteacher@example.com', 'Tea Cher');
        $n = $admin->fresh()->notifications()->firstOrFail();

        $this->actingAs($admin)->get(route('notifications.index'))->assertOk()->assertSee('Tea Cher registered and needs a grade &amp; section', false);
        $this->actingAs($admin)->post(route('notifications.read', $n->id))
            ->assertRedirect(route('admin.users', ['role' => 'teacher', 'unassigned' => 1]));
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_accounts_created_by_the_admin_do_not_notify(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post(route('admin.users.create'), [
            'name' => 'Made By Admin', 'email' => 'made@example.com', 'role' => 'teacher',
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $admin->fresh()->notifications()->count());
    }

    public function test_api_registration_notifies_too(): void
    {
        $admin = $this->user('admin');
        $this->postJson('/api/auth/register', [
            'name' => 'Api Teacher', 'email' => 'api@example.com', 'role' => 'teacher',
            'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1',
        ])->assertSuccessful();

        $this->assertSame(1, $admin->fresh()->notifications()->count());
    }

    // ── Attention on the dashboard and the Users page ──

    public function test_dashboard_and_users_page_flag_teachers_without_a_section(): void
    {
        $admin = $this->user('admin');
        $assigned = $this->user('teacher', ['name' => 'Has Class']);
        $this->section($assigned);
        $waiting = $this->user('teacher', ['name' => 'Waiting Teacher']);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Teachers waiting for a grade &amp; section', false)
            ->assertSee(route('admin.users', ['role' => 'teacher', 'unassigned' => 1]));

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()
            ->assertSee('waiting for a grade &amp; section', false)
            ->assertSee('Assign')
            ->assertSee('data-bs-target="#editUser' . $waiting->id . '"', false);

        // the filter lists only the teacher who still needs a section
        $this->actingAs($admin)->get(route('admin.users', ['unassigned' => 1]))->assertOk()
            ->assertSee('<strong>Waiting Teacher</strong>', false)->assertDontSee('<strong>Has Class</strong>', false)
            ->assertSee('Showing teachers without a grade');
    }

    public function test_attention_clears_once_every_teacher_has_a_section(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $this->section($teacher);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Teachers waiting for a grade')->assertSee('Nothing needs your attention right now');
        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertDontSee('waiting for a grade');
    }

    public function test_new_accounts_get_a_new_chip(): void
    {
        $admin = $this->user('admin');
        $this->user('teacher', ['name' => 'Fresh Face']);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertSee('Fresh Face')->assertSee('>New<', false);
    }

    // ── Create / edit user form ──

    public function test_create_form_offers_no_student_role_and_only_teachers_get_a_section(): void
    {
        $admin = $this->user('admin');

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<option value="student"', $html);
        $this->assertStringContainsString('data-class-wrap', $html);   // wrapper the page script shows/hides by role
        $this->assertStringContainsString("role.value === 'teacher'", $html);
    }

    public function test_student_role_is_refused_everywhere_in_admin(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('parent');
        $payload = ['name' => 'S Tudent', 'email' => 's@example.com', 'role' => 'student', 'password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1'];

        $this->actingAs($admin)->post(route('admin.users.create'), $payload)->assertSessionHasErrors('role');
        $this->actingAs($admin)->put(route('admin.users.update', $other), ['role' => 'student'])->assertSessionHasErrors('role');
        $this->actingAs($admin)->post(route('admin.users.role', $other), ['role' => 'student'])->assertSessionHasErrors('role');
        $this->assertSame('parent', $other->fresh()->role);
    }

    public function test_a_class_is_only_assigned_to_teachers(): void
    {
        $admin = $this->user('admin');
        $c1 = $this->section(null, 'Rizal');
        $c2 = $this->section(null, 'Bonifacio');
        $base = ['password' => 'Abcdefg1', 'password_confirmation' => 'Abcdefg1'];

        $this->actingAs($admin)->post(route('admin.users.create'), $base + ['name' => 'A Parent', 'email' => 'p@example.com', 'role' => 'parent', 'class_id' => $c1->id]);
        $this->assertNull($c1->fresh()->teacher_id);                     // a parent never becomes an adviser

        $this->actingAs($admin)->post(route('admin.users.create'), $base + ['name' => 'A Teacher', 'email' => 't@example.com', 'role' => 'teacher', 'class_id' => $c2->id]);
        $this->assertSame(User::byEmail('t@example.com')->first()->id, $c2->fresh()->teacher_id);
    }

    public function test_changing_a_teacher_to_parent_releases_their_section(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $class = $this->section($teacher);

        $this->actingAs($admin)->put(route('admin.users.update', $teacher), ['role' => 'parent', 'class_id' => $class->id])->assertSessionHasNoErrors();

        $this->assertNull($class->fresh()->teacher_id);
        $this->assertSame('parent', $teacher->fresh()->role);
    }

    public function test_assigning_a_section_to_a_teacher_from_the_row_works(): void
    {
        $admin = $this->user('admin');
        $teacher = $this->user('teacher');
        $class = $this->section();

        $this->actingAs($admin)->put(route('admin.users.update', $teacher), ['role' => 'teacher', 'class_id' => $class->id])->assertSessionHasNoErrors();

        $this->assertSame($teacher->id, $class->fresh()->teacher_id);
        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('Teachers waiting for a grade');
    }
}
