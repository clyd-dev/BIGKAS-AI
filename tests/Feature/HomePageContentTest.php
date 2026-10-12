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
        $response->assertSee('Streamlined Reading Assessment');
        $response->assertSee('suggestions that the teacher reviews and confirms');
    }

    public function test_home_page_how_it_works_and_portals()
    {
        $response = $this->get('/');
        
        $response->assertSee('AI-Assisted Reading Analysis');
        $response->assertSee('For Parents');
    }

    public function test_home_page_does_not_overclaim_forms_or_diagnosis()
    {
        $response = $this->get('/');

        $response->assertDontSee('diagnoses exactly');
        $response->assertDontSee('replacing manual Phil-IRI tallying completely');
        $response->assertDontSee('Forms 1–4');
    }

    public function test_privacy_page_is_reachable_and_linked_from_the_footer()
    {
        $this->get('/')->assertSee(route('privacy'), false);
        $this->get(route('privacy'))->assertStatus(200)->assertSee('Privacy Notice');
    }
}
