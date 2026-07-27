<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageContentTest extends TestCase
{
    public function test_home_page_contains_correct_brand_and_footer()
    {
        $response = $this->get('/');
        
        $response->assertStatus(200);
        $response->assertSee('BIGKAS-AI');
        $response->assertSee('Privacy Policy');
    }
}
