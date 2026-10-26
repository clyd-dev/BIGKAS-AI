<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Learner;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\PinIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A teacher can issue the student-portal PIN to a whole section at once.
 *
 * The student login finds a child by PIN alone, so the thing that must never
 * happen is two children holding the same PIN: the second would sign in as the
 * first. Most of this file is about that, and about never replacing a PIN a
 * child already has without the teacher saying so.
 */
class BulkPinTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = $this->user('teacher');
        $this->class = $this->sectionFor($this->teacher, 'Rizal');
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function sectionFor(User $teacher, string $section): SchoolClass
    {
        $school = School::firstOrCreate(['name' => 'Old Sagay ES']);

        return SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $teacher->id,
            'grade_level' => 4, 'section' => $section, 'school_year' => '2026-2027', 'is_active' => true,
        ]);
    }

    private function learners(int $count, ?SchoolClass $class = null, array $attributes = []): \Illuminate\Support\Collection
    {
        // The factory hands every learner a PIN; these start without one, as an
        // imported or newly added learner does.
        return Learner::factory()->count($count)->create(array_merge(
            ['class_id' => ($class ?? $this->class)->id, 'grade_level' => 4, 'is_active' => true,
                'pin' => null, 'pin_created_at' => null],
            $attributes
        ));
    }

    private function givePin(Learner $learner, string $pin): Learner
    {
        $learner->pin = $pin;
        $learner->pin_created_at = now()->subDays(10);
        $learner->save();

        return $learner->fresh();
    }

    /** A PIN held the way they were stored before PINs could be viewed. */
    private function giveLegacyPin(Learner $learner, string $pin): Learner
    {
        Learner::whereKey($learner->id)->update([
            'pin' => Hash::make($pin, ['rounds' => 4]),
            'pin_created_at' => now()->subDays(40),
        ]);

        return $learner->fresh();
    }

    private function issueTo(iterable $learners, array $extra = [])
    {
        return $this->actingAs($this->teacher)->post(route('learners.pins.store'), array_merge([
            'learner_ids' => collect($learners)->pluck('id')->all(),
        ], $extra));
    }

    private function issuer(): PinIssuer
    {
        return app(PinIssuer::class);
    }

    /** A PIN source that yields these candidates in order, then fails loudly. */
    private function draws(string ...$pins): callable
    {
        $queue = $pins;

        return function () use (&$queue): string {
            $this->assertNotEmpty($queue, 'the issuer asked for more candidates than the test expected');

            return array_shift($queue);
        };
    }

    // ── Issuing ───────────────────────────────────────────────────────

    public function test_it_issues_a_readable_unique_pin_to_every_learner_without_one(): void
    {
        $learners = $this->learners(30);

        $this->issueTo($learners)->assertRedirect();

        $pins = Learner::whereIn('id', $learners->pluck('id'))->get()->map->pin;

        $this->assertCount(30, $pins->filter());
        $this->assertCount(30, $pins->unique(), 'two children were given the same PIN');

        foreach (Learner::whereIn('id', $learners->pluck('id'))->get() as $learner) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $learner->pin);
            $this->assertTrue($learner->checkPin($learner->pin));
            $this->assertNotNull($learner->pin_created_at);
            $this->assertNotSame($learner->pin, $learner->getRawOriginal('pin'), 'stored in the clear');
        }
    }

    public function test_it_redirects_to_a_printable_sheet_of_exactly_the_issued_learners(): void
    {
        $learners = $this->learners(3);

        $response = $this->issueTo($learners);

        $response->assertRedirect(route('learners.pins.sheet', ['ids' => $learners->pluck('id')->all()]));
        $response->assertSessionHas('success');
    }

    public function test_a_learner_who_already_has_a_pin_keeps_it_unless_the_teacher_says_otherwise(): void
    {
        [$has, $needs] = $this->learners(2);
        $has = $this->givePin($has, '482915');

        $response = $this->issueTo([$has, $needs]);

        $this->assertSame('482915', $has->fresh()->pin, 'an existing PIN was replaced without being asked');
        $this->assertNotNull($needs->fresh()->pin);
        $response->assertSessionHas('success');
        $this->assertStringContainsString('already ha', session('warning') ?? '');
    }

    public function test_replacing_existing_pins_works_when_asked_and_the_old_one_stops_working(): void
    {
        $learner = $this->givePin($this->learners(1)->first(), '482915');

        $this->issueTo([$learner], ['replace_existing' => 1])->assertRedirect();

        $learner->refresh();
        $this->assertNotSame('482915', $learner->pin);
        $this->assertFalse($learner->checkPin('482915'));
        $this->assertTrue($learner->checkPin($learner->pin));
    }

    public function test_an_unreadable_legacy_pin_counts_as_having_one(): void
    {
        $learner = $this->giveLegacyPin($this->learners(1)->first(), '555123');

        $this->issueTo([$learner]);

        $this->assertTrue($learner->fresh()->hasLegacyPin(), 'a PIN the child still uses was replaced without being asked');
        $this->assertTrue($learner->fresh()->checkPin('555123'));
    }

    public function test_inactive_learners_are_never_issued_a_pin(): void
    {
        $inactive = $this->learners(1, null, ['is_active' => false])->first();

        $this->issueTo([$inactive]);

        $this->assertNull($inactive->fresh()->pin);
    }

    public function test_each_issue_is_written_to_the_activity_log(): void
    {
        $learners = $this->learners(3);

        $this->issueTo($learners);

        $this->assertSame(3, ActivityLog::where('action', 'generate_pin')->count());
    }

    // ── Who may do it ─────────────────────────────────────────────────

    public function test_a_teacher_cannot_issue_pins_to_another_teachers_learners(): void
    {
        $other = $this->user('teacher');
        $theirs = $this->learners(2, $this->sectionFor($other, 'Bonifacio'));
        $mine = $this->learners(1);

        $this->issueTo($theirs->concat($mine));

        $this->assertNull($theirs[0]->fresh()->pin);
        $this->assertNull($theirs[1]->fresh()->pin);
        $this->assertNotNull($mine[0]->fresh()->pin);
    }

    public function test_the_admin_and_parents_cannot_issue_pins(): void
    {
        $learner = $this->learners(1)->first();

        foreach (['admin', 'parent'] as $role) {
            $this->actingAs($this->user($role))
                ->post(route('learners.pins.store'), ['learner_ids' => [$learner->id]])
                ->assertForbidden();
        }

        $this->assertNull($learner->fresh()->pin);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->post(route('learners.pins.store'), ['learner_ids' => [1]])->assertRedirect();
    }

    public function test_it_needs_at_least_one_learner_and_not_an_unreasonable_number(): void
    {
        $this->actingAs($this->teacher)->post(route('learners.pins.store'), [])
            ->assertSessionHasErrors('learner_ids');

        $this->actingAs($this->teacher)->post(route('learners.pins.store'), ['learner_ids' => range(1, 201)])
            ->assertSessionHasErrors('learner_ids');
    }

    // ── Two children must never share a PIN ───────────────────────────

    public function test_a_candidate_that_another_learner_already_has_is_rejected(): void
    {
        $this->givePin($this->learners(1)->first(), '482915');
        $target = $this->learners(1)->first();

        $result = $this->issuer()->issue(collect([$target]), draw: $this->draws('482915', '730164'));

        $this->assertSame('730164', $target->fresh()->pin);
        $this->assertCount(1, $result['issued']);
    }

    public function test_a_candidate_already_given_out_in_the_same_batch_is_rejected(): void
    {
        [$a, $b] = $this->learners(2);

        $this->issuer()->issue(collect([$a, $b]), draw: $this->draws('111222', '111222', '333444'));

        $this->assertSame('111222', $a->fresh()->pin);
        $this->assertSame('333444', $b->fresh()->pin);
    }

    public function test_the_common_pins_are_never_handed_out(): void
    {
        $learner = $this->learners(1)->first();

        $this->issuer()->issue(collect([$learner]), draw: $this->draws('123456', '000000', '111111', '902317'));

        $this->assertSame('902317', $learner->fresh()->pin);
    }

    public function test_a_candidate_that_matches_an_unreadable_legacy_pin_is_rejected(): void
    {
        $this->giveLegacyPin($this->learners(1)->first(), '555123');
        $target = $this->learners(1)->first();

        $this->issuer()->issue(collect([$target]), draw: $this->draws('555123', '640218'));

        $this->assertSame('640218', $target->fresh()->pin);
    }

    public function test_the_old_pin_of_a_learner_being_replaced_no_longer_blocks_anyone(): void
    {
        // Their old PIN is going away in this very batch, so it is free to reuse -
        // and, for a legacy hash, not worth the slow check against.
        [$replaced, $other] = $this->learners(2);
        $this->givePin($replaced, '482915');

        $this->issuer()->issue(collect([$replaced, $other]), draw: $this->draws('777888', '482915'));

        $this->assertSame('482915', $other->fresh()->pin);
        $this->assertSame('777888', $replaced->fresh()->pin);
    }

    // ── Time budget ───────────────────────────────────────────────────

    public function test_it_stops_at_the_time_budget_and_a_second_run_finishes_the_rest(): void
    {
        $learners = $this->learners(5);

        // A budget of zero still makes progress: one learner per run at least.
        $first = $this->issuer()->issue($learners, budgetSeconds: 0);

        $this->assertCount(1, $first['issued']);
        $this->assertCount(4, $first['deferred']);
        $this->assertSame(1, Learner::whereIn('id', $learners->pluck('id'))->whereNotNull('pin')->count());

        $left = Learner::whereIn('id', $first['deferred']->pluck('id'))->get();
        $second = $this->issuer()->issue($left);

        $this->assertCount(4, $second['issued']);
        $this->assertCount(0, $second['deferred']);
        $this->assertCount(5, Learner::whereIn('id', $learners->pluck('id'))->get()->map->pin->unique());
    }

    public function test_the_page_says_how_many_are_left_when_it_stops_early(): void
    {
        $learners = $this->learners(4);
        $this->mock(PinIssuer::class, function ($mock) use ($learners) {
            $mock->shouldReceive('issue')->once()->andReturn([
                'issued' => $learners->take(1),
                'deferred' => $learners->slice(1),
            ]);
        });

        $response = $this->issueTo($learners);

        $this->assertStringContainsString('3', session('warning') ?? '');
        $this->assertStringContainsString('again', session('warning') ?? '');
        $response->assertRedirect();
    }

    // ── The management page ───────────────────────────────────────────

    public function test_the_page_lists_the_teachers_learners_with_their_pin_status(): void
    {
        [$none, $has, $legacy] = $this->learners(3);
        $this->givePin($has, '482915');
        $this->giveLegacyPin($legacy, '555123');

        $page = $this->actingAs($this->teacher)->get(route('learners.pins.index'));

        $page->assertOk()
            ->assertSee($none->getFullName())
            ->assertSee($has->getFullName())
            ->assertSee($legacy->getFullName())
            ->assertSee('No PIN')
            ->assertSee('Has a PIN')
            ->assertSee('cannot be shown');
    }

    public function test_the_page_never_prints_a_pin_itself(): void
    {
        $has = $this->givePin($this->learners(1)->first(), '482915');

        $this->actingAs($this->teacher)->get(route('learners.pins.index'))
            ->assertOk()->assertDontSee('482915');
    }

    public function test_the_page_does_not_list_another_teachers_learners_or_inactive_ones(): void
    {
        $other = $this->user('teacher');
        $theirs = $this->learners(1, $this->sectionFor($other, 'Bonifacio'))->first();
        $inactive = $this->learners(1, null, ['is_active' => false])->first();

        $this->actingAs($this->teacher)->get(route('learners.pins.index'))
            ->assertOk()->assertDontSee($theirs->getFullName())->assertDontSee($inactive->getFullName());
    }

    public function test_the_learners_page_links_to_it_for_a_teacher(): void
    {
        $this->learners(1);

        $this->actingAs($this->teacher)->get(route('learners.index'))
            ->assertOk()->assertSee(route('learners.pins.index'), false);
    }

    // ── The printable sheet ───────────────────────────────────────────

    public function test_the_sheet_shows_each_childs_name_and_pin_and_is_not_cached(): void
    {
        [$a, $b] = $this->learners(2);
        $a = $this->givePin($a, '482915');
        $b = $this->givePin($b, '730164');

        $response = $this->actingAs($this->teacher)->get(route('learners.pins.sheet', ['ids' => [$a->id, $b->id]]));

        $response->assertOk()
            ->assertSee($a->getFullName())->assertSee('482915')
            ->assertSee($b->getFullName())->assertSee('730164')
            ->assertSee(route('student.login'), false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_sheet_leaves_out_other_teachers_learners_and_unreadable_pins(): void
    {
        $other = $this->user('teacher');
        $theirs = $this->givePin($this->learners(1, $this->sectionFor($other, 'Bonifacio'))->first(), '999888');
        $legacy = $this->giveLegacyPin($this->learners(1)->first(), '555123');
        $mine = $this->givePin($this->learners(1)->first(), '482915');

        $response = $this->actingAs($this->teacher)
            ->get(route('learners.pins.sheet', ['ids' => [$theirs->id, $legacy->id, $mine->id]]));

        $response->assertOk()->assertSee('482915')
            ->assertDontSee('999888')->assertDontSee($theirs->getFullName())
            ->assertDontSee($legacy->getFullName());
    }

    public function test_opening_the_sheet_is_logged_because_it_lists_children_by_name_and_pin(): void
    {
        $learner = $this->givePin($this->learners(1)->first(), '482915');

        $this->actingAs($this->teacher)->get(route('learners.pins.sheet', ['ids' => [$learner->id]]))->assertOk();

        $this->assertSame(1, ActivityLog::where('action', 'print_pin_sheet')->count());
    }

    public function test_only_a_teacher_can_open_the_sheet(): void
    {
        $learner = $this->givePin($this->learners(1)->first(), '482915');

        foreach (['admin', 'parent'] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('learners.pins.sheet', ['ids' => [$learner->id]]))
                ->assertForbidden();
        }
    }

    public function test_the_sheet_can_cover_a_whole_section_without_listing_ids(): void
    {
        $learners = $this->learners(3);
        foreach ($learners as $i => $learner) {
            $this->givePin($learner, '48291' . $i);
        }

        $this->actingAs($this->teacher)
            ->get(route('learners.pins.sheet', ['class_id' => $this->class->id]))
            ->assertOk()->assertSee('482910')->assertSee('482912');
    }

    public function test_the_existing_single_learner_flow_still_works(): void
    {
        $learner = $this->learners(1)->first();

        $this->actingAs($this->teacher)->post(route('learners.generate-pin', $learner))->assertRedirect();

        $this->assertNotNull($learner->fresh()->pin);
    }
}
