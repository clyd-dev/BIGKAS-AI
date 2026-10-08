<?php

namespace Tests\Feature;

use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Student PINs are stored encrypted (readable by the teacher), never plain and no longer hashed. */
class PinHashingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pin_is_encrypted_in_database_and_readable_through_the_model(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456']);
        $raw = DB::table('learners')->where('id', $learner->id)->value('pin');

        $this->assertNotSame('123456', $raw);                       // not plain text
        $this->assertStringNotContainsString('123456', $raw);
        $this->assertFalse(str_starts_with($raw, '$2y$'));          // not a hash
        $this->assertSame('123456', Crypt::decryptString($raw));    // reversible with the app key
        $this->assertSame('123456', $learner->fresh()->pin);        // what the teacher sees
    }

    public function test_checkPin_validates_correctly(): void
    {
        $learner = Learner::factory()->create(['pin' => '123456']);
        $this->assertTrue($learner->checkPin('123456'));
        $this->assertFalse($learner->checkPin('654321'));
        $this->assertFalse(Learner::factory()->create(['pin' => null])->checkPin('123456'));
    }

    public function test_generated_pins_are_unique_among_all_learners(): void
    {
        Learner::factory()->count(5)->create();
        $pin = Learner::generatePin();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertFalse(Learner::whereNotNull('pin')->get()->contains(fn ($l) => $l->checkPin($pin)));
    }

    public function test_old_bcrypt_pins_still_work_but_cannot_be_shown(): void
    {
        $learner = Learner::factory()->create();
        DB::table('learners')->where('id', $learner->id)->update(['pin' => password_hash('482915', PASSWORD_BCRYPT)]);
        $learner = $learner->fresh();

        $this->assertTrue($learner->hasLegacyPin());
        $this->assertNull($learner->pin);                           // cannot be read back
        $this->assertTrue($learner->checkPin('482915'));            // login keeps working
        $this->assertFalse($learner->checkPin('000001'));
    }

    public function test_legacy_pin_is_upgraded_to_encrypted_when_the_child_logs_in(): void
    {
        $learner = Learner::factory()->create(['is_active' => true]);
        DB::table('learners')->where('id', $learner->id)->update(['pin' => password_hash('482915', PASSWORD_BCRYPT)]);

        $this->post('/student/login', ['pin' => '482915']);

        $learner = $learner->fresh();
        $this->assertFalse($learner->hasLegacyPin());
        $this->assertSame('482915', $learner->pin);                 // now visible to the teacher
        $this->assertTrue($learner->checkPin('482915'));
    }
}
