<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocsPageTest extends TestCase
{
    public function test_docs_page_renders_without_a_database_and_pins_scalar(): void
    {
        // The docs must load even when the database is down (they are how visitors find out what the API does).
        config(['database.connections.pgsql.port' => 1]);
        DB::purge('pgsql');

        $this->get('/docs')
            ->assertOk()
            ->assertSee('@scalar/api-reference@1.74.0', false)
            ->assertSee('Try as demo customer')
            ->assertSee('demo@bookly.test')
            ->assertDontSee('admin@bookly.test')
            ->assertDontSee('swagger-ui', false);
    }
}
