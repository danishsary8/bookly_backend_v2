<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiSpecTest extends TestCase
{
    /** "GET /books/{id}" for every API route, with route parameter names normalised to {id}. */
    private function apiRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '{id}', substr($route->uri(), strlen('api/v1')));
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $routes[] = $method.' '.$path;
                }
            }
        }

        return array_unique($routes);
    }

    private function documented(): array
    {
        $spec = Yaml::parseFile(public_path('openapi.yaml'));
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{id}', $path);
            }
        }

        return $documented;
    }

    public function test_the_spec_is_valid_yaml_with_the_basics(): void
    {
        $spec = Yaml::parseFile(public_path('openapi.yaml'));

        $this->assertStringStartsWith('3.', $spec['openapi']);
        $this->assertSame('/api/v1', $spec['servers'][0]['url']);
        $this->assertArrayHasKey('bearer', $spec['components']['securitySchemes']);
    }

    public function test_every_documented_endpoint_exists(): void
    {
        $missing = array_diff($this->documented(), $this->apiRoutes());

        $this->assertSame([], array_values($missing), 'Documented but not a real route');
    }

    public function test_every_api_route_is_documented(): void
    {
        $undocumented = array_diff($this->apiRoutes(), $this->documented());

        $this->assertSame([], array_values($undocumented), 'Route missing from public/openapi.yaml');
    }

    public function test_docs_page_loads_the_spec(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('/openapi.yaml', false)
            ->assertSee('swagger-ui', false);
    }
}
