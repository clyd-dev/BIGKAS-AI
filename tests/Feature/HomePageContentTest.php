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

    public function test_home_page_hero_and_benefits()
    {
        $response = $this->get('/');
        
        $response->assertSee('AN INTELLIGENT READING PROGRESS ASSESSMENT AND INTERVENTION SYSTEM', false);
        $response->assertSee('Save Hours of Grading');
    }

    public function test_home_page_how_it_works_and_portals()
    {
        $response = $this->get('/');
        
        $response->assertSee('Instant AI Analysis');
        $response->assertSee('For Parents');
    }
}