<?php

namespace Tests\Unit;

use App\Services\OtpMailer;
use Tests\TestCase;

class OtpMailerTest extends TestCase
{
    public function test_build_message_contains_code_and_recipient_without_sending(): void
    {
        $mailer = new OtpMailer();

        $message = $mailer->buildMessage('juan@gmail.com', 'Juan', '482916');
        $message->preSend();
        $mime = $message->getSentMIMEMessage();

        $this->assertSame('juan@gmail.com', $message->getToAddresses()[0][0]);
        $this->assertStringContainsString('482916', $mime);
        $this->assertStringContainsString('BIGKAS-AI', $message->Subject);
    }
}
