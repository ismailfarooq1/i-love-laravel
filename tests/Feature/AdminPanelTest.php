<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    public function test_admin_login_page_is_available(): void
    {
        $response = $this->get('/admin/login');

        $response->assertStatus(200);
    }

    public function test_guests_are_redirected_to_admin_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirectToRoute('filament.admin.auth.login');
    }
}
