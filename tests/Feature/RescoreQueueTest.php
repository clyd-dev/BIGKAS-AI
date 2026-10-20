<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Learner;
use App\Models\ReadingMaterial;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\ServiceHealthEvent;
use App\Models\User;
use App\Services\RescoreQueue;
use App\Services\ServiceHealthMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An outage must cost nothing but time: a recording saved while the engine was
 * down is scored again by the scheduler once it is back, with the same
 * pipeline as the Analyze button, and nothing is scored in the meantime.
 */
class RescoreQueueTest extends TestCase
{
    use RefreshDatabase;

    private const PASSAGE = 'I have a dog his name is Max Max is brown he has a long tail';

    private User $teacher;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Cache::flush();
        config(['services.whisper.use_local' => true, 'services.openai.api_key' => '', 'services.health_alert.email' => null]);

        $this->teacher = User::factory()->create(['role' => 'teacher', 'is_active' => true, 'email_verified_at' => now()]);
        $school = School::firstOrCreate(['name' => 'RQ School'], ['address' => 'Sagay', 'division' => 'Sagay']);
        $this->class = SchoolClass::create([
            'school_id' => $school->id, 'teacher_id' => $this->teacher->id,
            'grade_level' => 4, 'section' => 'RQ', 'school_year' => '2026-2027',
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────

    /** A recording that was saved but never scored. */
    private function unscored(string $status = Assessment::STATUS_FAILED, array $attributes = []): Assessment
    {
        $material = ReadingMaterial::firstOrCreate(['title' => 'RQ Passage'], [
            'content' => self::PASSAGE, 'language' => 'en', 'grade_level' => 4, 'difficulty' => 'easy',
            'type' => ReadingMaterial::TYPE_ORAL_READING, 'word_count' => 15,
        ]);

        $assessment = Assessment::create(array_merge([
            'learner_id' => Learner::factory()->create(['class_id' => $this->class->id, 'grade_level' => 4])->id,
            'material_id' => $material->id,
            'assessor_id' => $this->teacher->id,
            'language' => 'en',
            'assessment_type' => Assessment::TYPE_ORAL_READING,
            'status' => $status,
        ], $attributes));

        $path = 'assessments/audio/a' . $assessment->id . '.webm';
        Storage::disk('public')->put($path, 'audio');
        $assessment->update(['audio_file' => $path]);

        return $assessment->fresh();
    }

    private function words(): array
    {
        $t = 0.0;

        return array_map(function ($w) use (&$t) {
            $word = ['word' => $w, 'start' => round($t, 2), 'end' => round($t + 0.4, 2), 'confidence' => 0.95];
            $t += 0.45;

            return $word;
        }, explode(' ', self::PASSAGE));
    }

    /** Flask up, with the given parts loaded. */
    private function engine(bool $whisper = true, bool $classifier = true, ?bool $classifyWorks = null): void
    {
        $classifyWorks ??= $classifier;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/api/health' => Http::response([
                'whisper_loaded' => $whisper, 'classifier_loaded' => $classifier,
                'model_stale' => false, 'model_version' => '3.0.0-test',
            ]),
            '*/api/transcribe' => Http::response([
                'text' => self::PASSAGE, 'words' => $this->words(), 'duration' => 6.5, 'language' => 'en',
            ]),
            '*/api/classify' => $classifyWorks
                ? Http::response([
                    'primary_weakness' => 'Independent Reader', 'secondary_weakness' => null,
                    'confidence' => 0.8, 'scores' => [], 'model_version' => '3.0.0-test',
                ])
                : Http::response('no model', 503),
        ]);
    }

    private function down(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
    }

    private function queue(): RescoreQueue
    {
        return app(RescoreQueue::class);
    }

    // ── The queue ─────────────────────────────────────────────────────

    public function test_nothing_is_scored_while_the_engine_is_down(): void
    {
        $assessment = $this->unscored();
        $this->down();

        $summary = $this->queue()->run();

        $this->assertNotNull($summary['skipped']);
        $this->assertSame(0, $summary['scored']);
        $this->assertDatabaseCount('assessment_results', 0);
        $this->assertSame(0, $assessment->fresh()->rescore_attempts, 'an outage must not use up the recording\'s tries');
        $this->assertNull($assessment->fresh()->last_rescore_at);
    }

    public function test_a_saved_recording_is_scored_once_the_engine_is_back(): void
    {
        $assessment = $this->unscored();
        $this->engine();

        $summary = $this->queue()->run();

        $this->assertSame(1, $summary['scored']);

        $result = $assessment->fresh()->result;
        $this->assertNotNull($result);
        $this->assertSame(AssessmentResult::STATUS_CLASSIFIED, $result->classification_status);
        $this->assertSame('local_whisper', $result->ml_analysis_json['provenance']['stt_engine']);
        $this->assertSame(Assessment::STATUS_COMPLETED, $assessment->fresh()->status);
        $this->assertSame(0, $this->queue()->waiting()->count());
    }

    public function test_a_recording_left_processing_is_picked_up_only_once_it_is_clearly_stuck(): void
    {
        $fresh = $this->unscored(Assessment::STATUS_PROCESSING);
        $stuck = $this->unscored(Assessment::STATUS_PROCESSING);
        Assessment::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);
        $this->engine();

        $this->queue()->run();

        $this->assertNull($fresh->fresh()->result, 'a run that may still be in progress is left alone');
        $this->assertNotNull($stuck->fresh()->result);
    }

    public function test_a_failure_while_the_engine_is_up_counts_and_is_eventually_given_up(): void
    {
        $assessment = $this->unscored();
        config(['services.openai.api_key' => 'sk-test']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            '*/api/health' => Http::response(['whisper_loaded' => true, 'classifier_loaded' => true, 'model_stale' => false]),
            '*/api/transcribe' => Http::response('cannot decode', 500),
            '*/audio/transcriptions' => Http::response(['error' => ['message' => 'bad file']], 400),
        ]);
        Log::shouldReceive('error', 'warning', 'info')->andReturnNull();

        for ($i = 0; $i < RescoreQueue::MAX_ATTEMPTS; $i++) {
            Assessment::whereKey($assessment->id)->update(['last_rescore_at' => now()->subHour()]);
            $this->queue()->run();
        }

        $this->assertSame(RescoreQueue::MAX_ATTEMPTS, $assessment->fresh()->rescore_attempts);
        $this->assertSame(0, $this->queue()->waiting()->count());
        $this->assertSame(1, $this->queue()->givenUp()->count());

        // Given up means left alone, not deleted: the recording is still there.
        Assessment::whereKey($assessment->id)->update(['last_rescore_at' => now()->subHour()]);
        $this->assertSame(0, $this->queue()->run()['scored'] + $this->queue()->run()['failed']);
        $this->assertTrue($assessment->fresh()->hasAudio());
    }

    public function test_a_recording_is_not_retried_again_straight_away(): void
    {
        $assessment = $this->unscored();
        $assessment->update(['last_rescore_at' => now()->subMinutes(2)]);
        $this->engine();

        $this->queue()->run();

        $this->assertNull($assessment->fresh()->result);
        $this->assertSame(1, $this->queue()->waiting()->count(), 'still waiting, just not due yet');
    }

    public function test_a_result_the_model_could_not_classify_is_redone_when_the_model_is_back(): void
    {
        $assessment = $this->unscored();
        $this->engine(classifier: false);

        $this->queue()->run();
        $this->assertSame(AssessmentResult::STATUS_UNAVAILABLE, $assessment->fresh()->result->classification_status);
        $this->assertSame(1, $this->queue()->waiting()->count(), 'measured, but still owed a classification');

        // Still no model: leave it alone instead of redoing the speech work for nothing.
        Assessment::whereKey($assessment->id)->update(['last_rescore_at' => now()->subHour()]);
        Cache::flush();
        $this->engine(classifier: false);
        $this->assertSame(0, $this->queue()->run()['scored']);

        // The model returns.
        Cache::flush();
        $this->engine();
        $summary = $this->queue()->run();

        $this->assertSame(1, $summary['scored']);
        $this->assertSame(AssessmentResult::STATUS_CLASSIFIED, $assessment->fresh()->result->classification_status);
        $this->assertSame(0, $this->queue()->waiting()->count());
    }

    public function test_a_teachers_verdict_is_never_overwritten_by_a_rescore(): void
    {
        $assessment = $this->unscored();
        $this->engine(classifier: false);
        $this->queue()->run();
        $assessment->verdict()->create([
            'decided_by' => $this->teacher->id, 'decision' => 'accepted', 'decided_at' => now(), 'manual_scoring' => false,
        ]);
        Assessment::whereKey($assessment->id)->update(['last_rescore_at' => now()->subHour()]);
        Cache::flush();
        $this->engine();

        $this->assertSame(0, $this->queue()->waiting()->count());
        $this->assertSame(0, $this->queue()->run()['scored']);
    }

    public function test_a_recording_with_a_result_is_not_in_the_queue(): void
    {
        $assessment = $this->unscored();
        $this->engine();
        $this->queue()->run();
        $before = $assessment->fresh()->result->id;

        $this->queue()->run();

        $this->assertSame($before, $assessment->fresh()->result->id);
        $this->assertDatabaseCount('assessment_results', 1);
    }

    public function test_the_limit_caps_how_much_one_run_scores(): void
    {
        foreach (range(1, 4) as $_) {
            $this->unscored();
        }
        $this->engine();

        $this->assertSame(2, $this->queue()->run(2)['scored']);
        $this->assertSame(2, $this->queue()->waiting()->count());
    }

    public function test_the_command_runs_and_reports(): void
    {
        $this->unscored();
        $this->engine();

        $this->artisan('assessments:rescore')->expectsOutputToContain('Scored 1')->assertSuccessful();
    }

    // ── The monitor ───────────────────────────────────────────────────

    private function monitor(): ServiceHealthMonitor
    {
        return app(ServiceHealthMonitor::class);
    }

    public function test_a_change_in_health_is_recorded_once(): void
    {
        $this->engine();
        $this->monitor()->check();
        $this->monitor()->check();

        $this->down();
        $this->monitor()->check();
        $this->monitor()->check();

        $this->assertSame(
            ['healthy', 'down'],
            ServiceHealthEvent::orderBy('id')->pluck('level')->all()
        );
    }

    public function test_a_short_blip_does_not_alert_but_a_lasting_outage_does(): void
    {
        config(['services.health_alert.email' => 'principal@example.test']);
        Mail::fake();
        Log::spy();

        $this->down();
        $this->monitor()->check();
        Mail::assertNothingSent();

        $this->travel(ServiceHealthMonitor::GRACE_MINUTES + 1)->minutes();
        $this->monitor()->check();
        $this->monitor()->check();

        $this->assertNotNull(ServiceHealthEvent::latest('id')->first()->alerted_at);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_recovery_is_announced_only_for_an_outage_that_was_reported(): void
    {
        Log::spy();

        // A blip that cleared inside the grace period: nobody was told it was down.
        $this->down();
        $this->monitor()->check();
        $this->engine();
        $this->monitor()->check();
        Log::shouldNotHaveReceived('warning');

        // A real outage, reported, then fixed.
        $this->down();
        $this->monitor()->check();
        $this->travel(ServiceHealthMonitor::GRACE_MINUTES + 1)->minutes();
        $this->monitor()->check();
        $this->engine();
        $this->monitor()->check();

        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_broken_mail_server_does_not_stop_the_monitor(): void
    {
        config(['services.health_alert.email' => 'principal@example.test']);
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('smtp down'));
        Log::spy();

        $this->down();
        $this->monitor()->check();
        $this->travel(ServiceHealthMonitor::GRACE_MINUTES + 1)->minutes();

        $event = $this->monitor()->check();

        $this->assertSame('down', $event->level);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_no_model_is_degraded_not_down(): void
    {
        $this->engine(classifier: false);

        $this->assertSame('degraded', $this->monitor()->check()->level);
    }

    // ── The admin's view ──────────────────────────────────────────────

    public function test_only_the_admin_sees_the_engine_page_and_it_shows_the_queue(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        $this->unscored();
        $this->engine();
        ServiceHealthEvent::create(['state' => 'unreachable', 'level' => 'down', 'message' => 'The analysis service is not running.']);

        $this->actingAs($this->teacher)->get(route('admin.system'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.system'))
            ->assertOk()
            ->assertSee('Waiting to be scored')
            ->assertSee('Engine history')
            ->assertSee('The analysis service is not running.');
    }

    public function test_the_dashboard_flags_recordings_that_are_waiting(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
        $this->unscored();
        $this->engine();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recordings waiting to be scored');
    }

    public function test_the_status_card_no_longer_claims_rules_stand_in_for_the_model(): void
    {
        $this->engine(classifier: false);

        $this->assertStringNotContainsString(
            'built-in rules',
            json_encode(\App\Services\SystemStatus::all(fresh: true))
        );
    }
}
