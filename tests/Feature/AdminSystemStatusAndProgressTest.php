<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\User;
use App\Services\SchoolReadingProgress;
use App\Services\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSystemStatusAndProgressTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'email_verified_at' => now()]);
    }

    private function config(bool $ml, bool $localWhisper, string $key = ''): void
    {
        config(['services.ml_api.enabled' => $ml, 'services.ml_api.url' => 'http://ml.test:5000',
                'services.whisper.use_local' => $localWhisper, 'services.openai.api_key' => $key]);
        Cache::flush();
    }

    private function state(array $status, string $key): array
    {
        return collect($status['services'])->firstWhere('key', $key);
    }

    // ── Service status ──

    public function test_both_services_online_when_the_flask_service_has_everything_loaded(): void
    {
        $this->config(true, true);
        Http::fake(['ml.test:5000/*' => Http::response(['status' => 'ok', 'model_version' => 'rf-v3', 'classifier_loaded' => true, 'whisper_loaded' => true])]);

        $status = SystemStatus::all();

        $this->assertSame('online', $this->state($status, 'ml')['state']);
        $this->assertStringContainsString('rf-v3', $this->state($status, 'ml')['detail']);
        $this->assertSame('online', $this->state($status, 'whisper')['state']);
        $this->assertSame('Local faster-whisper', $this->state($status, 'whisper')['mode']);
    }

    public function test_service_up_but_models_missing_is_degraded_not_online(): void
    {
        $this->config(true, true);
        Http::fake(['ml.test:5000/*' => Http::response(['status' => 'ok', 'classifier_loaded' => false, 'whisper_loaded' => false])]);

        $status = SystemStatus::all();

        $this->assertSame('degraded', $this->state($status, 'ml')['state']);
        $this->assertSame('degraded', $this->state($status, 'whisper')['state']);
    }

    public function test_unreachable_service_is_offline_for_both_and_says_assessments_still_work(): void
    {
        $this->config(true, true);
        Http::fake(fn () => throw new ConnectionException('refused'));

        $status = SystemStatus::all();

        $this->assertSame('offline', $this->state($status, 'ml')['state']);
        $this->assertSame('offline', $this->state($status, 'whisper')['state']);
        $this->assertStringContainsString('still work', $this->state($status, 'ml')['detail']);
        $this->assertStringContainsString('http://ml.test:5000', $this->state($status, 'ml')['detail']);
    }

    public function test_ml_switched_off_shows_disabled_and_does_not_call_the_service(): void
    {
        $this->config(false, false, '');
        Http::fake();

        $status = SystemStatus::all();

        $this->assertSame('disabled', $this->state($status, 'ml')['state']);
        Http::assertNothingSent();   // no local whisper and no ML: nothing to probe
        $this->assertSame('degraded', $this->state($status, 'whisper')['state']);   // cloud mode without an API key
        $this->assertSame('No API key', $this->state($status, 'whisper')['headline']);
    }

    public function test_cloud_whisper_with_a_key_checks_the_openai_api(): void
    {
        $this->config(false, false, 'sk-test');
        Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);

        $this->assertSame('online', $this->state(SystemStatus::all(), 'whisper')['state']);
    }

    public function test_results_are_cached_briefly_and_can_be_refreshed(): void
    {
        $this->config(true, true);
        Http::fake(['ml.test:5000/*' => Http::response(['classifier_loaded' => true, 'whisper_loaded' => true])]);

        SystemStatus::all();
        SystemStatus::all();
        Http::assertSentCount(1);          // second call came from the cache

        SystemStatus::all(true);
        Http::assertSentCount(2);          // "Check again" bypasses it
    }

    public function test_status_endpoint_is_for_admins_only(): void
    {
        $this->config(true, true);
        Http::fake(['ml.test:5000/*' => Http::response(['classifier_loaded' => true, 'whisper_loaded' => true])]);

        $this->actingAs($this->user('admin'))->getJson(route('admin.system-status'))->assertOk()
            ->assertJsonStructure(['checked_at', 'services' => [['key', 'label', 'state', 'headline', 'detail', 'mode']]])
            ->assertJsonPath('services.0.key', 'ml')
            ->assertJsonPath('services.1.key', 'whisper');

        $this->actingAs($this->user('teacher'))->getJson(route('admin.system-status'))->assertForbidden();
    }

    // ── School reading progress ──

    private function assess(Learner $learner, string $level, float $accuracy, int $wpm, string $when): void
    {
        $material = ReadingMaterial::firstOrCreate(['title' => 'Passage'], ['content' => 'x', 'language' => 'en', 'grade_level' => 4]);
        $a = Assessment::create(['learner_id' => $learner->id, 'material_id' => $material->id,
            'assessor_id' => $this->user('teacher')->id, 'language' => 'en', 'status' => 'completed']);
        DB::table('assessments')->where('id', $a->id)->update(['created_at' => $when]);
        AssessmentResult::create(['assessment_id' => $a->id, 'accuracy_rate' => $accuracy, 'words_per_minute' => $wpm, 'reading_level' => $level]);
    }

    public function test_progress_counts_coverage_movement_and_the_monthly_trend(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        [$up, $steady, $down, $once, $never] = Learner::factory()->count(5)->create()->all();

        $this->assess($up,     'frustration',   60, 50, '2026-08-05');
        $this->assess($up,     'independent',   98, 90, '2026-10-05');   // moved up
        $this->assess($steady, 'instructional', 92, 70, '2026-08-10');
        $this->assess($steady, 'instructional', 94, 75, '2026-10-10');   // held
        $this->assess($down,   'independent',   97, 95, '2026-08-12');
        $this->assess($down,   'instructional', 91, 80, '2026-10-12');   // slipped
        $this->assess($once,   'frustration',   70, 55, '2026-09-15');   // only one assessment: not counted in movement

        $p = SchoolReadingProgress::summary(4);   // Jul, Aug, Sep, Oct

        $this->assertSame(['improved' => 1, 'steady' => 1, 'declined' => 1, 'total' => 3], $p['movement']);
        $this->assertSame(['learners' => 5, 'assessed' => 4, 'percent' => 80], $p['coverage']);

        $this->assertSame(['Jul', 'Aug', 'Sep', 'Oct'], $p['trend']['labels']);
        $this->assertSame([0, 3, 1, 3], $p['trend']['counts']);
        $this->assertSame([null, round((60 + 92 + 97) / 3, 1), 70.0, round((98 + 94 + 91) / 3, 1)], $p['trend']['accuracy']);
        $this->assertSame(round((98 + 94 + 91) / 3 - (60 + 92 + 97) / 3, 1), $p['trend']['change']);
        $this->assertTrue($p['trend']['has_data']);
    }

    public function test_progress_is_empty_and_safe_with_no_assessments(): void
    {
        $p = SchoolReadingProgress::summary();

        $this->assertFalse($p['trend']['has_data']);
        $this->assertNull($p['trend']['change']);
        $this->assertSame(0, $p['movement']['total']);
        $this->assertSame(0, $p['coverage']['percent']);
    }

    // ── The dashboard itself ──

    public function test_dashboard_shows_both_new_cards_and_not_the_removed_widgets(): void
    {
        $this->config(true, true);
        Http::fake(['ml.test:5000/*' => Http::response(['classifier_loaded' => true, 'whisper_loaded' => true])]);

        $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk()
            ->assertSee('School Reading Progress')
            ->assertSee('AI &amp; Speech Services', false)
            ->assertSee('Moved up a level')
            ->assertSee('admin\/system-status', false)     // the page fetches the status after it loads (JSON-escaped URL)
            ->assertDontSee('Reading Level Distribution')
            ->assertDontSee('Assessments This Month')
            ->assertDontSee('Recent Assessments');

        Http::assertNothingSent();   // opening the dashboard never waits on the services
    }

    // ── Reading movement is judged within one language ──

    public function test_movement_never_compares_across_languages(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $learner = Learner::factory()->create();

        // English Frustration, later a Filipino Instructional: different languages, so this is NOT progress.
        $this->assessIn($learner, 'en', 'frustration', 50, 50, '2026-08-05');
        $this->assessIn($learner, 'fil', 'instructional', 92, 70, '2026-10-05');
        $this->assertSame(0, SchoolReadingProgress::summary()['movement']['total']);

        // A second English reading makes a real comparison possible: Frustration -> Independent.
        $this->assessIn($learner, 'en', 'independent', 98, 90, '2026-10-10');
        $this->assertSame(['improved' => 1, 'steady' => 0, 'declined' => 0, 'total' => 1], SchoolReadingProgress::summary()['movement']);
    }

    private function assessIn(Learner $learner, string $lang, string $level, float $accuracy, int $wpm, string $when): void
    {
        $material = ReadingMaterial::firstOrCreate(['title' => "Passage {$lang}"], ['content' => 'x', 'language' => $lang, 'grade_level' => 4]);
        $a = Assessment::create(['learner_id' => $learner->id, 'material_id' => $material->id,
            'assessor_id' => $this->user('teacher')->id, 'language' => $lang, 'status' => 'completed']);
        DB::table('assessments')->where('id', $a->id)->update(['created_at' => $when]);
        AssessmentResult::create(['assessment_id' => $a->id, 'accuracy_rate' => $accuracy, 'words_per_minute' => $wpm, 'reading_level' => $level]);
    }

    // ── The "Needs your attention" container ──

    public function test_attention_container_sits_under_the_tiles_and_lists_items_most_urgent_first(): void
    {
        $this->config(true, true);
        Http::fake(['ml.test:5000/*' => Http::response(['classifier_loaded' => true, 'whisper_loaded' => true])]);

        $school = \App\Models\School::create(['name' => 'Old Sagay ES']);
        \App\Models\User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);   // no section yet
        \App\Models\SchoolClass::create(['school_id' => $school->id, 'teacher_id' => null, 'grade_level' => 4, 'section' => 'Rizal', 'school_year' => '2026-2027', 'is_active' => true]);
        Learner::factory()->create(['reading_level' => 'frustration']);

        $html = $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Needs your attention', $html);
        $this->assertStringContainsString('2 to do', $html);                              // teachers waiting + sections without adviser
        $this->assertStringContainsString('Assign now', $html);

        // ordered danger -> warning -> info
        $order = [strpos($html, 'Needs your attention'), strpos($html, 'Teachers waiting for a grade'),
                  strpos($html, 'Sections without an adviser'), strpos($html, 'Learners at Frustration level')];
        $this->assertSame($order, collect($order)->sort()->values()->all());

        // page order: count tiles -> attention box -> reading progress / AI services
        $this->assertLessThan($order[0], strpos($html, 'Manage users'));
        $this->assertLessThan(strpos($html, 'School Reading Progress'), $order[0]);
    }

    public function test_attention_container_says_all_clear_when_nothing_is_waiting(): void
    {
        $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk()
            ->assertSee('All clear')
            ->assertSee('Nothing needs your attention right now')
            ->assertDontSee('to do</span>', false);
    }

    public function test_only_awareness_items_do_not_count_as_things_to_do(): void
    {
        Learner::factory()->create(['reading_level' => 'frustration']);

        $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk()
            ->assertSee('Learners at Frustration level')
            ->assertSee('Nothing urgent')
            ->assertDontSee('Assign now');
    }

    public function test_service_problems_can_join_the_attention_list(): void
    {
        // the page carries the script that turns an offline/degraded service into an attention item
        $this->actingAs($this->user('admin'))->get(route('dashboard'))->assertOk()
            ->assertSee('data-service-alert', false)
            ->assertSee('id="attentionItems"', false)
            ->assertSee('id="attentionClear"', false);
    }
}
