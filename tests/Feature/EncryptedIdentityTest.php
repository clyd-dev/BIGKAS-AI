<?php

namespace Tests\Feature;

use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Notifications\ResetPassword;
use Tests\TestCase;

/** Step A of encrypting personal data: learners and users. */
class EncryptedIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create($extra + ['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function teacherWithClass(): array
    {
        $teacher = $this->user('teacher');
        $school = School::create(['name' => 'Old Sagay ES']);
        $class = SchoolClass::create(['school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);

        return [$teacher, $class];
    }

    // ── Stored encrypted ──

    public function test_learner_identity_is_encrypted_in_the_database_but_readable_through_the_model(): void
    {
        $l = Learner::factory()->create([
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'middle_name' => 'Reyes', 'lrn' => '123456789012',
            'birth_date' => '2016-05-17', 'mother_tongue' => 'Hiligaynon',
        ]);
        $l->notes = 'Needs glasses';
        $l->save();

        $raw = DB::table('learners')->where('id', $l->id)->first();
        foreach (['first_name' => 'Ana', 'last_name' => 'Cruz', 'middle_name' => 'Reyes', 'lrn' => '123456789012',
                  'birth_date' => '2016-05-17', 'mother_tongue' => 'Hiligaynon', 'notes' => 'Needs glasses'] as $col => $plain) {
            $this->assertStringNotContainsString($plain, (string) $raw->{$col}, "{$col} is stored readable");
            $this->assertStringStartsWith('eyJpdiI6', $raw->{$col}, "{$col} is not an encrypted payload");
        }

        $fresh = $l->fresh();
        $this->assertSame(['Ana', 'Cruz', 'Reyes', '123456789012', 'Hiligaynon', 'Needs glasses'],
            [$fresh->first_name, $fresh->last_name, $fresh->middle_name, $fresh->lrn, $fresh->mother_tongue, $fresh->notes]);
        $this->assertSame('2016-05-17', $fresh->birth_date->toDateString());
        $this->assertSame('Ana R. Cruz', $fresh->getFullName());
    }

    public function test_user_identity_is_encrypted_in_the_database(): void
    {
        $u = $this->user('teacher', ['name' => 'Maria Santos', 'email' => 'maria@school.test', 'phone' => '09171234567']);
        $raw = DB::table('users')->where('id', $u->id)->first();

        foreach (['name' => 'Maria Santos', 'email' => 'maria@school.test', 'phone' => '09171234567'] as $col => $plain) {
            $this->assertStringNotContainsString($plain, (string) $raw->{$col}, "{$col} is stored readable");
        }
        $this->assertSame(64, strlen($raw->email_index));
        $this->assertSame('Maria Santos', $u->fresh()->name);
        $this->assertSame('maria@school.test', $u->fresh()->email);
    }

    public function test_a_value_edited_in_the_database_is_detected_not_read(): void
    {
        $l = Learner::factory()->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        DB::table('learners')->where('id', $l->id)->update(['last_name' => 'eyJpdiI6InRhbXBlcmVkIiwidmFsdWUiOiJ4In0=']);

        $this->expectException(DecryptException::class);
        $l->fresh()->last_name;
    }

    // ── Login, email uniqueness, password reset ──

    public function test_login_works_through_the_blind_index_and_ignores_email_case(): void
    {
        $this->user('teacher', ['email' => 'Teacher@School.test', 'password' => 'Password-12345']);

        $this->post('/login', ['email' => 'teacher@school.test', 'password' => 'Password-12345'])->assertRedirect();
        $this->assertAuthenticated();
        auth()->logout();

        $this->post('/login', ['email' => 'TEACHER@SCHOOL.TEST', 'password' => 'Password-12345'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_wrong_password_and_unknown_email_do_not_log_in(): void
    {
        $this->user('teacher', ['email' => 'teacher@school.test', 'password' => 'Password-12345']);

        $this->post('/login', ['email' => 'teacher@school.test', 'password' => 'wrong-password']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'nobody@school.test', 'password' => 'Password-12345']);
        $this->assertGuest();
    }

    public function test_email_must_stay_unique_whatever_its_case(): void
    {
        $existing = $this->user('teacher', ['email' => 'taken@school.test']);
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('admin.users.create'), [
            'name' => 'Dup', 'email' => 'TAKEN@school.test', 'role' => 'teacher', 'password' => 'Password-12345', 'password_confirmation' => 'Password-12345',
        ])->assertSessionHasErrors('email');

        // A profile update may keep its own email.
        $this->actingAs($existing)->put(route('profile.update'), ['name' => 'Renamed', 'email' => 'taken@school.test'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $existing->fresh()->name);
    }

    public function test_password_reset_keys_on_the_blind_index_and_the_link_carries_the_real_email(): void
    {
        Notification::fake();
        $user = $this->user('teacher', ['email' => 'reset@school.test']);

        Password::sendResetLink(['email' => 'reset@school.test']);

        $row = DB::table('password_reset_tokens')->first();
        $this->assertSame($user->email_index, $row->email);                  // no readable email in the reset table
        $this->assertStringNotContainsString('reset@school.test', $row->email);

        Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
            $url = $n->toMail($user)->actionUrl;
            $this->assertStringContainsString('email=' . urlencode('reset@school.test'), $url);

            return true;
        });

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) { $token = $n->token; return true; });
        $status = Password::reset(['email' => 'reset@school.test', 'token' => $token, 'password' => 'New-Password-123', 'password_confirmation' => 'New-Password-123'],
            function ($u, $pw) { $u->password = $pw; $u->save(); });
        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertTrue(\Hash::check('New-Password-123', $user->fresh()->password));
    }

    // ── LRN ──

    public function test_lrn_uniqueness_ignores_formatting_and_lookup_uses_the_index(): void
    {
        [$teacher] = $this->teacherWithClass();
        $this->actingAs($teacher)->post(route('learners.store'), ['first_name' => 'Ana', 'last_name' => 'Cruz', 'lrn' => '1234-5678-9012'])
            ->assertSessionHasNoErrors();

        $this->actingAs($teacher)->post(route('learners.store'), ['first_name' => 'Ben', 'last_name' => 'Reyes', 'lrn' => '123456789012'])
            ->assertSessionHasErrors('lrn');

        $this->assertNotNull(Learner::byLrn('123456789012')->first());
        $this->assertSame(BlindIndex::make('123456789012', 'lrn'), Learner::all()->first()->lrn_index);
        $this->assertNull(Learner::byLrn('999999999999')->first());
    }

    // ── Search and sort in PHP ──

    public function test_learner_search_and_sort_work_on_encrypted_names(): void
    {
        [$teacher, $class] = $this->teacherWithClass();
        foreach ([['Zoe', 'Álvarez', '111111111111'], ['Ana', 'Cruz', '222222222222'], ['Ben', 'Bautista', '333333333333'], ['Carl', 'cruz-Diaz', '444444444444']] as [$f, $l, $lrn]) {
            Learner::factory()->create(['class_id' => $class->id, 'grade_level' => 4, 'first_name' => $f, 'last_name' => $l, 'lrn' => $lrn]);
        }

        // sorted by last name, accent- and case-insensitive: Álvarez, Bautista, Cruz, cruz-Diaz
        $this->actingAs($teacher)->get(route('learners.index'))->assertOk()
            ->assertSeeInOrder(['Álvarez', 'Bautista', 'Cruz', 'cruz-Diaz']);

        // partial, case-insensitive match on name; and on LRN
        $this->actingAs($teacher)->get(route('learners.index', ['search' => 'CRUZ']))->assertOk()
            ->assertSee('Ana')->assertSee('Carl')->assertDontSee('Bautista')->assertSee('Showing 1–2 of 2');
        $this->actingAs($teacher)->get(route('learners.index', ['search' => '3333']))->assertOk()
            ->assertSee('Bautista')->assertDontSee('Álvarez');
        $this->actingAs($teacher)->get(route('learners.index', ['search' => 'ana cruz']))->assertOk()->assertSee('Showing 1–1 of 1');
    }

    public function test_learner_list_stays_scoped_and_paginated_with_encrypted_names(): void
    {
        [$teacher, $class] = $this->teacherWithClass();
        Learner::factory()->count(12)->create(['class_id' => $class->id, 'grade_level' => 4]);
        Learner::factory()->count(5)->create(); // someone else's learners

        $this->actingAs($teacher)->get(route('learners.index'))->assertOk()->assertSee('Showing 1–10 of 12');
        $this->actingAs($teacher)->get(route('learners.index', ['page' => 2]))->assertOk()->assertSee('Showing 11–12 of 12');
    }

    public function test_admin_users_page_searches_and_sorts_encrypted_names_and_emails(): void
    {
        $admin = $this->user('admin', ['name' => 'Zed Admin']);
        $this->user('teacher', ['name' => 'Bea Santos', 'email' => 'bea@school.test']);
        $this->user('teacher', ['name' => 'Al Reyes', 'email' => 'al@elsewhere.test']);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertSeeInOrder(['Al Reyes', 'Bea Santos', 'Zed Admin']);
        $this->actingAs($admin)->get(route('admin.users', ['search' => 'elsewhere']))->assertOk()
            ->assertSee('Al Reyes')->assertDontSee('Bea Santos');
        $this->actingAs($admin)->get(route('admin.users', ['search' => 'bea']))->assertOk()->assertSee('Bea Santos')->assertDontSee('Al Reyes');
    }
}
