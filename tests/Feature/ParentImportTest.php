<?php

namespace Tests\Feature;

use App\Http\Controllers\ParentImportController;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Bulk-creating parent accounts for families with no email address. A parent
 * logs in with their own full name, so these tests check the real thing a
 * mistake would cost: an account linked to the wrong child, or a parent who
 * cannot log in at all.
 */
class ParentImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function learner(string $first, string $last, int $grade, ?string $lrn = null): Learner
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);
        $class = SchoolClass::firstOrCreate(
            ['school_id' => $school->id, 'grade_level' => $grade, 'section' => 'G'.$grade, 'school_year' => '2026-2027'],
            ['is_active' => true]
        );

        return Learner::factory()->create([
            'class_id' => $class->id, 'grade_level' => $grade,
            'first_name' => $first, 'last_name' => $last, 'lrn' => $lrn,
        ]);
    }

    private function upload(User $admin, string $csv)
    {
        return $this->actingAs($admin)->post(route('admin.users.import.preview'), [
            'parents_file' => UploadedFile::fake()->createWithContent('parents.csv', $csv),
        ]);
    }

    public function test_only_the_admin_can_open_the_import(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($teacher)->get(route('admin.users.import'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.users.import'))->assertOk();
    }

    public function test_a_parent_is_matched_to_their_child_by_lrn_and_can_log_in_with_their_name(): void
    {
        $admin = $this->admin();
        $child = $this->learner('Juan', 'Dela Cruz', 4, '123456789012');

        $csv = "Parent Name,Phone,LRN,Grade Level\n"
             ."Maria Dela Cruz,0917123,123456789012,4\n";

        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertSame($child->id, $rows[0]['learner_id']);
        $this->assertFalse($rows[0]['already_linked']);
        $this->assertSame('Maria Dela Cruz', $rows[0]['login']);

        $this->actingAs($admin)->post(route('admin.users.import.confirm'), ['rows' => [0]])->assertOk();

        $parent = User::byEmail('Maria Dela Cruz')->first();
        $this->assertSame('parent', $parent->role);
        $this->assertNotNull($parent->email_verified_at, 'An imported parent must not be stopped by email verification.');
        $this->assertSame('0917123', $parent->phone);
        $this->assertTrue($child->fresh()->users->contains($parent->id));
        $this->assertSame([4], $parent->grades());

        Auth::logout();
        $this->assertTrue(
            Auth::attempt(['email' => 'maria dela cruz', 'password' => ParentImportController::DEFAULT_PASSWORD]),
            'The name must work as the login, case-insensitively.'
        );
    }

    /** No LRN in the sheet is the common case; the child's name has to carry it. */
    public function test_a_parent_is_matched_by_the_child_name_when_there_is_no_lrn(): void
    {
        $admin = $this->admin();
        $child = $this->learner('Ana', 'Reyes', 3);

        $csv = "Parent's Name,Child's Name\nRosa Reyes,  ana   reyes \n";

        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertSame($child->id, $rows[0]['learner_id'], 'Spacing and case must not break the name match.');
        $this->assertSame([3], $rows[0]['grades'], "The child's own grade stands in for a missing grade column.");
    }

    /** A parent belongs to every grade their children are in, whatever the sheet says. */
    public function test_the_childs_grade_is_added_when_the_sheet_disagrees(): void
    {
        $admin = $this->admin();
        $this->learner('Leo', 'Tan', 6, '999888777666');

        $csv = "Parent Name,LRN,Grade Level\nPedro Tan,999888777666,3\n";

        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertSame([3, 6], $rows[0]['grades']);
        $this->assertNotNull($rows[0]['grade_note']);

        $this->actingAs($admin)->post(route('admin.users.import.confirm'), ['rows' => [0]])->assertOk();

        $this->assertSame([3, 6], User::byEmail('Pedro Tan')->first()->grades());
    }

    public function test_a_child_who_already_has_a_parent_is_flagged(): void
    {
        $admin = $this->admin();
        $child = $this->learner('Mia', 'Lim', 2, '111222333444');
        $existing = User::factory()->create(['role' => 'parent', 'is_active' => true, 'email_verified_at' => now()]);
        $child->users()->attach($existing->id, ['relationship' => 'parent']);

        $csv = "Parent Name,LRN\nNew Lim,111222333444\n";
        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertTrue($rows[0]['already_linked'], 'The admin must be warned before adding a second parent.');
    }

    public function test_two_parents_with_the_same_name_get_separate_logins(): void
    {
        $admin = $this->admin();

        $csv = "Parent Name,Phone\nMaria Cruz,0917\nMaria Cruz,0918\n";
        $rows = collect($this->upload($admin, $csv)->assertOk()->viewData('importRows'));

        $this->assertSame('Maria Cruz', $rows[0]['login']);
        $this->assertSame('Maria Cruz (2)', $rows[1]['login']);

        $this->actingAs($admin)->post(route('admin.users.import.confirm'), ['rows' => [0, 1]])->assertOk();

        $this->assertSame(2, User::where('role', 'parent')->count());
        Auth::logout();
        $this->assertTrue(Auth::attempt([
            'email' => 'Maria Cruz (2)', 'password' => ParentImportController::DEFAULT_PASSWORD,
        ]));
    }

    /** An existing account must never be overwritten by an import row. */
    public function test_a_name_already_in_use_gets_a_suffix_instead_of_colliding(): void
    {
        $admin = $this->admin();
        User::factory()->create([
            'name' => 'Jose Rizal', 'email' => 'Jose Rizal',
            'role' => 'parent', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        $rows = collect($this->upload($admin, "Parent Name,Phone\nJose Rizal,0917\n")->assertOk()->viewData('importRows'));

        $this->assertSame('Jose Rizal (2)', $rows[0]['login']);
    }

    public function test_only_the_confirmed_rows_are_created(): void
    {
        $admin = $this->admin();

        $csv = "Parent Name,Phone\nKeep Me,0917\nSkip Me,0918\n";
        $this->upload($admin, $csv)->assertOk();

        $this->actingAs($admin)->post(route('admin.users.import.confirm'), ['rows' => [0]])->assertOk();

        $this->assertNotNull(User::byEmail('Keep Me')->first());
        $this->assertNull(User::byEmail('Skip Me')->first());
    }

    public function test_a_file_with_no_parent_name_column_is_refused(): void
    {
        $admin = $this->admin();

        $this->upload($admin, "Town,Phone\nSagay,0917\n")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, User::count(), 'Only the admin should exist.');
    }

    /** The preview lives in the session; a stale confirm must not half-import. */
    public function test_confirm_without_a_preview_is_sent_back_to_the_form(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.import.confirm'), ['rows' => [0]])
            ->assertRedirect(route('admin.users.import'))
            ->assertSessionHas('error');

        $this->assertSame(1, User::count());
    }

    public function test_the_preview_writes_nothing(): void
    {
        $admin = $this->admin();
        $this->learner('Nina', 'Cruz', 1, '555666777888');

        $this->upload($admin, "Parent Name,LRN\nLola Cruz,555666777888\n")->assertOk();

        $this->assertSame(1, User::count(), 'Preview must not create accounts.');
    }
}
