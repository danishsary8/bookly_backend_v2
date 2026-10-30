<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocsPageTest extends TestCase
{
    public function test_docs_page_renders_without_a_database_and_offers_the_demo_sign_in(): void
    {
        // The docs must load even when the database is down (they are how visitors find out what the API does).
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->get('/docs')
            ->assertOk()
            ->assertSee('swagger-ui-dist@5.17.14', false)
            ->assertSee('Sign in as demo customer')
            ->assertSee('/demo/token', false)
            // The button needs no password, so no password is published on the page.
            ->assertDontSee('Password123!')
            ->assertDontSee('admin@bookly.test');
    }

    public function test_docs_page_explains_auth_errors_and_postman(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('Authentication (customers and staff)')
            ->assertSee('/staff/auth/two-factor/challenge')
            ->assertSee('Errors and status codes')
            ->assertSee('Test with Postman')
            ->assertSee(url('/openapi.yaml'));
    }
}
