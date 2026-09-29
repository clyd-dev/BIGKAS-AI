<?php

namespace Tests\Feature;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VerificationCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_for_stores_hashed_code_with_expiry(): void
    {
        $user = User::factory()->unverified()->create();

        $code = EmailVerificationCode::issueFor($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $row = EmailVerificationCode::where('user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check($code, $row->code_hash));
        $this->assertFalse($row->isExpired());
        $this->assertFalse($row->hasMaxAttempts());
    }
}
