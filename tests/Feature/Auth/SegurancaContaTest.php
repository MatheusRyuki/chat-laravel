<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SegurancaContaTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_bloqueia_apos_cinco_erros_e_libera_apos_prazo(): void
    {
        Event::fake([Lockout::class]);
        $usuario = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $usuario->email, 'password' => 'errada'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $usuario->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        Event::assertDispatched(Lockout::class);
        $outro = User::factory()->create();
        $this->post('/login', ['email' => $outro->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($outro);
        $this->post('/logout');
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $usuario->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($usuario);
    }

    public static function cadastrosInvalidos(): array
    {
        return [
            'nome ausente' => [['name' => ''], 'name'],
            'email invalido' => [['email' => 'invalido'], 'email'],
            'senha curta' => [['password' => 'abc', 'password_confirmation' => 'abc'], 'password'],
            'confirmacao diferente' => [['password_confirmation' => 'diferente'], 'password'],
        ];
    }

    #[DataProvider('cadastrosInvalidos')]
    public function test_cadastro_invalido_nao_cria_conta(array $alteracoes, string $campo): void
    {
        $this->post('/register', array_replace(['name' => 'Ana', 'email' => 'ana@example.test', 'password' => 'senha-segura', 'password_confirmation' => 'senha-segura'], $alteracoes))->assertSessionHasErrors($campo);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_email_duplicado_nao_sobrescreve_conta(): void
    {
        $usuario = User::factory()->create();
        $this->post('/register', ['name' => 'Outra pessoa', 'email' => $usuario->email, 'password' => 'senha-segura', 'password_confirmation' => 'senha-segura'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($usuario->name, $usuario->fresh()->name);
    }

    public function test_reenvio_da_verificacao_e_idempotencia_para_usuario_verificado(): void
    {
        Notification::fake();
        $usuario = User::factory()->unverified()->create();
        $this->actingAs($usuario)->post('/email/verification-notification')->assertSessionHas('status', 'verification-link-sent');
        Notification::assertSentTo($usuario, VerifyEmail::class);
        $usuario->markEmailAsVerified();
        Notification::fake();
        $this->post('/email/verification-notification')->assertRedirect(route('dashboard', absolute: false));
        $this->get('/verify-email')->assertRedirect(route('dashboard', absolute: false));
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), ['id' => $usuario->id, 'hash' => sha1($usuario->email)]);
        $this->get($url)->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        Notification::assertNothingSent();
    }

    public function test_link_expirado_nao_verifica_email(): void
    {
        $usuario = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $usuario->id, 'hash' => sha1($usuario->email)]);
        $this->actingAs($usuario)->get($url)->assertForbidden();
        $this->assertFalse($usuario->fresh()->hasVerifiedEmail());
    }

    public function test_token_de_senha_invalido_expirado_e_reutilizado_nao_altera_senha(): void
    {
        $usuario = User::factory()->create();
        $dados = ['email' => $usuario->email, 'password' => 'nova-senha-segura', 'password_confirmation' => 'nova-senha-segura'];
        $this->post('/reset-password', $dados + ['token' => 'invalido'])->assertSessionHasErrors('email');
        $token = Password::createToken($usuario);
        $this->travel(61)->minutes();
        $this->post('/reset-password', $dados + ['token' => $token])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $usuario->fresh()->password));
        $token = Password::createToken($usuario);
        $this->post('/reset-password', $dados + ['token' => $token])->assertSessionHasNoErrors();
        $this->post('/reset-password', array_replace($dados, ['password' => 'outra-senha-segura', 'password_confirmation' => 'outra-senha-segura', 'token' => $token]))->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('nova-senha-segura', $usuario->fresh()->password));
    }
}
