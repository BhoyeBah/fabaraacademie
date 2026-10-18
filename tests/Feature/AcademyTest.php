<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    private function course(): Course
    {
        return Course::where('code', 'INF-01')->firstOrFail();
    }

    public function test_catalogue_public_sans_lien_admin(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Excel du débutant au pro')
            ->assertSee('Connexion')->assertSee('Inscription')
            ->assertDontSee('Espace admin')
            ->assertDontSee(config('app.admin_path'));
    }

    public function test_recherche_et_filtre(): void
    {
        $this->get('/?q=excel')->assertSee('Excel du débutant au pro')->assertDontSee('Anglais professionnel');
        $this->get('/?domaine=langues')->assertSee('Anglais professionnel')->assertDontSee('Excel du débutant au pro');
    }

    public function test_le_lien_drive_n_apparait_jamais_publiquement(): void
    {
        $course = $this->course();
        $course->update(['drive_url' => 'https://drive.google.com/drive/folders/SECRET123']);
        $this->get('/')->assertDontSee('SECRET123');
        $this->get(route('catalog.show', $course))->assertOk()->assertDontSee('SECRET123');
        $this->assertStringNotContainsString('SECRET123', \DB::table('courses')->where('id', $course->id)->value('drive_url'), 'Le lien doit être chiffré en base');
    }

    public function test_inscription_exige_le_consentement(): void
    {
        $data = ['name' => 'Aïssatou Bah', 'email' => 'aissatou@example.com', 'phone' => '224 620 11 22 33', 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'];
        $this->post('/inscription', $data)->assertSessionHasErrors('terms');
        $this->assertGuest();

        $this->post('/inscription', $data + ['terms' => '1'])->assertRedirect(route('account.dashboard'));
        $user = User::where('email', 'aissatou@example.com')->first();
        $this->assertFalse($user->isAdmin());
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame('224620112233', $user->phone);
    }

    public function test_impossible_de_devenir_admin_par_l_inscription(): void
    {
        $this->post('/inscription', ['name' => 'X', 'email' => 'x@example.com', 'phone' => '224620000000', 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1', 'terms' => '1', 'is_admin' => '1']);
        $this->assertFalse(User::where('email', 'x@example.com')->first()->isAdmin());
    }

    public function test_espace_admin_invisible_pour_client_et_visiteur(): void
    {
        $admin = '/'.config('app.admin_path');
        $this->get($admin)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($admin)->assertNotFound();
        $this->get($admin.'/formations')->assertNotFound();
    }

    public function test_admin_se_connecte_par_le_meme_formulaire(): void
    {
        User::factory()->admin()->create(['email' => 'admin@example.com']);
        $this->post('/connexion', ['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Tableau de bord');
    }

    public function test_connexion_bloquee_apres_trop_d_essais(): void
    {
        User::factory()->create(['email' => 'c@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/connexion', ['email' => 'c@example.com', 'password' => 'faux']);
        }
        $this->post('/connexion', ['email' => 'c@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_parcours_complet_commande_paiement_acces(): void
    {
        $client = User::factory()->create();
        $course = $this->course();
        $course->update(['drive_url' => 'https://drive.google.com/drive/folders/ABC']);

        $this->actingAs($client)->post(route('cart.add', $course))->assertRedirect();
        $this->post(route('checkout.store'))->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame(Order::PENDING, $order->status);
        $this->assertSame(60000, $order->total);

        // Avant paiement : pas d'accès
        $this->get(route('orders.show', $order))->assertOk()->assertSee('wa.me', false);
        $this->get(route('account.access', $course))->assertNotFound();

        // L'admin valide le paiement
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.orders.paid', $order), ['payment_method' => 'Orange Money'])->assertRedirect();
        $this->get(route('admin.orders.show', $order))->assertSee('Envoyer les formations sur WhatsApp');

        // Après paiement : le client ouvre le Drive
        $this->actingAs($client)->get(route('account.dashboard'))->assertSee('Excel du débutant au pro');
        $this->get(route('account.access', $course))->assertRedirect('https://drive.google.com/drive/folders/ABC');
    }

    public function test_un_client_ne_voit_pas_la_commande_d_un_autre(): void
    {
        $a = User::factory()->create();
        $order = Order::create(['user_id' => $a->id, 'reference' => 'CMD-TEST01', 'total' => 1000, 'status' => 'pending']);
        $this->actingAs(User::factory()->create())->get(route('orders.show', $order))->assertNotFound();
    }

    public function test_export_des_donnees(): void
    {
        $client = User::factory()->create(['name' => 'Mamadou Diallo']);
        $response = $this->actingAs($client)->get(route('account.export'));
        $response->assertOk();
        $this->assertStringContainsString('Mamadou Diallo', $response->streamedContent());
    }

    public function test_suppression_du_compte_anonymise_les_commandes(): void
    {
        $client = User::factory()->create();
        $order = Order::create(['user_id' => $client->id, 'reference' => 'CMD-TEST02', 'total' => 1000, 'status' => 'paid']);

        $this->actingAs($client)->delete(route('account.destroy'), ['password_delete' => 'mauvais'])->assertSessionHasErrors('password_delete');
        $this->actingAs($client)->delete(route('account.destroy'), ['password_delete' => 'password'])->assertRedirect(route('catalog.index'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $client->id]);
        $this->assertNull($order->fresh()->user_id);
    }

    public function test_nouvelles_conditions_a_accepter(): void
    {
        $client = User::factory()->create(['terms_version' => 'ancienne']);
        $this->actingAs($client)->get(route('account.dashboard'))->assertRedirect(route('terms.accept'));
        $this->post(route('terms.accept'), ['terms' => '1'])->assertRedirect(route('account.dashboard'));
        $this->get(route('account.dashboard'))->assertOk();
    }

    public function test_admin_gere_une_formation(): void
    {
        $admin = User::factory()->admin()->create();
        $cat = \App\Models\Category::first();
        $this->actingAs($admin)->post(route('admin.courses.store'), [
            'code' => 'tst-01', 'category_id' => $cat->id, 'title' => 'Formation test', 'description' => 'Desc',
            'level' => 'Débutant', 'price' => 50000, 'program' => "Chap 1\nChap 2", 'drive_url' => 'https://drive.google.com/drive/folders/X', 'is_published' => '1',
        ])->assertRedirect(route('admin.courses.index'));
        $course = Course::where('code', 'TST-01')->firstOrFail();
        $this->assertSame(['Chap 1', 'Chap 2'], $course->program);

        $this->post(route('admin.courses.store'), ['code' => 'TST-02', 'category_id' => $cat->id, 'title' => 'T', 'description' => 'D', 'level' => 'Débutant', 'price' => 1, 'drive_url' => 'https://exemple.com/x'])
            ->assertSessionHasErrors('drive_url');
    }

    public function test_pages_legales_et_en_tetes_de_securite(): void
    {
        foreach (['/confidentialite', '/conditions', '/suppression-compte'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_assets_utilisent_https_derriere_un_proxy_securise(): void
    {
        $response = $this->call('GET', '/', server: [
            'HTTP_HOST' => 'fabara-academie.com',
            'HTTP_X_FORWARDED_HOST' => 'fabara-academie.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $response->assertOk()
            ->assertSee('href="https://fabara-academie.com/css/app.css"', false)
            ->assertSee('src="https://fabara-academie.com/js/app.js"', false);
    }

    public function test_les_pages_admin_s_affichent(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        foreach (['admin.dashboard', 'admin.courses.index', 'admin.courses.create', 'admin.categories.index', 'admin.orders.index', 'admin.users.index', 'admin.settings.edit'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.courses.edit', $this->course()))->assertOk();
        $this->put(route('admin.settings.update'), ['brand' => 'Mon École', 'whatsapp' => '224 611 22 33 44', 'contact_email' => 'a@b.co', 'city' => 'Conakry'])->assertRedirect();
        $this->get('/')->assertSee('Mon École');
    }

    public function test_visiteur_doit_creer_un_compte_pour_obtenir_un_cours(): void
    {
        $course = $this->course();

        $this->get('/')->assertSee('Obtenir')->assertDontSee('Panier');
        $this->get('/panier')->assertRedirect(route('login'));

        // Le visiteur clique « Obtenir » : il est envoyé vers l'inscription
        $this->post(route('cart.add', $course))->assertRedirect(route('register'));
        $this->get(route('register'))->assertSee('Crée ton compte gratuit pour obtenir');

        // Après l'inscription, la formation est dans son panier
        $this->post('/inscription', ['name' => 'Fatou Camara', 'email' => 'fatou@example.com', 'phone' => '224620445566',
            'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1', 'terms' => '1'])
            ->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertSee('Excel du débutant au pro')->assertSee('Valider ma commande');
    }

    public function test_client_existant_retrouve_sa_formation_apres_connexion(): void
    {
        User::factory()->create(['email' => 'moussa@example.com']);
        $this->post(route('cart.add', $this->course()))->assertRedirect(route('register'));
        $this->post('/connexion', ['email' => 'moussa@example.com', 'password' => 'password'])->assertRedirect(route('cart.index'));
        $this->get(route('cart.index'))->assertSee('Excel du débutant au pro');
    }

    public function test_admin_modifie_son_login(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ancien@example.com']);
        $this->actingAs($admin)->get(route('admin.profile.edit'))->assertOk()->assertSee('ancien@example.com');

        // Mauvais mot de passe actuel : refusé
        $this->put(route('admin.profile.update'), ['name' => 'Admin', 'email' => 'nouveau@example.com', 'current_password' => 'faux'])
            ->assertSessionHasErrors('current_password');
        $this->assertSame('ancien@example.com', $admin->fresh()->email);

        $this->put(route('admin.profile.update'), ['name' => 'Messou', 'email' => 'nouveau@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('nouveau@example.com', $admin->fresh()->email);

        // Mot de passe trop court : refusé, puis changement valide
        $this->put(route('admin.profile.password'), ['current_password_pw' => 'password', 'password' => 'court1', 'password_confirmation' => 'court1'])
            ->assertSessionHasErrors('password');
        $this->put(route('admin.profile.password'), ['current_password_pw' => 'password', 'password' => 'NouveauMotDePasse2026', 'password_confirmation' => 'NouveauMotDePasse2026'])
            ->assertSessionHasNoErrors();

        // Connexion avec les nouveaux identifiants
        auth()->logout();
        $this->post('/connexion', ['email' => 'nouveau@example.com', 'password' => 'NouveauMotDePasse2026'])->assertRedirect(route('admin.dashboard'));
    }

    public function test_un_client_n_accede_pas_a_la_page_login_admin(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.profile.edit'))->assertNotFound();
    }
}
