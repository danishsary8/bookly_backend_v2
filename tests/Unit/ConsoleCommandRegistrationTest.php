<?php

namespace Tests\Unit;

use App\Console\Commands\ConfigureEducationCategories;
use App\Console\Commands\ReshapeEducationCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ConsoleCommandRegistrationTest extends TestCase
{
    public function test_education_commands_are_registered_separately(): void
    {
        $commands = Artisan::all();

        $this->assertInstanceOf(ReshapeEducationCategories::class, $commands['catalog:education-categories']);
        $this->assertInstanceOf(ConfigureEducationCategories::class, $commands['catalog:education-setup']);
        $this->assertTrue($commands['catalog:education-setup']->getDefinition()->hasOption('import-batch'));
    }

    public function test_application_command_names_are_unique_before_registration(): void
    {
        $names = [];
        foreach (File::allFiles(app_path('Console/Commands')) as $file) {
            $relative = str_replace(['/', '\\'], '\\', $file->getRelativePathname());
            $class = 'App\\Console\\Commands\\'.substr($relative, 0, -4);
            if (! is_subclass_of($class, Command::class)) {
                continue;
            }

            $name = $this->app->make($class)->getName();
            $this->assertNotEmpty($name, "Missing command name for {$class}");
            $this->assertArrayNotHasKey($name, $names, "Duplicate Artisan command name: {$name}");
            $names[$name] = $class;
        }
    }
}
