<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_from_environment_configuration(): void
    {
        config([
            'app.admin_email' => 'fabara224@gmail.com',
            'app.admin_password' => 'Test-Admin-Password-1',
        ]);

        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'fabara224@gmail.com')->firstOrFail();
        $this->assertSame('Administrateur', $admin->name);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('Test-Admin-Password-1', $admin->password));
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($admin->terms_accepted_at);
    }

    public function test_it_promotes_an_existing_user_without_creating_a_duplicate(): void
    {
        $user = User::factory()->create([
            'email' => 'fabara224@gmail.com',
            'password' => 'old-password',
        ]);
        config([
            'app.admin_email' => 'fabara224@gmail.com',
            'app.admin_password' => 'Test-Admin-Password-1',
        ]);

        $this->seed(AdminSeeder::class);

        $this->assertSame(1, User::where('email', 'fabara224@gmail.com')->count());
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertTrue(Hash::check('Test-Admin-Password-1', $user->fresh()->password));
    }
}
