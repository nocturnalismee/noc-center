<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Table-driven policy matrix (deferred from Tasks 2/3).
 *
 * Every ability is asserted through Gate::forUser() so the registrations in
 * AppServiceProvider are exercised instead of the policy methods directly.
 */
class PolicyMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['admin', 'operator', 'viewer'];

    #[DataProvider('policyMatrix')]
    public function test_policy_ability_for_role(string $ability, string $subject, string $role, bool $expected): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::from($role)])->save();

        $this->assertSame(
            $expected,
            Gate::forUser($user)->allows($ability, $this->resolveSubject($subject)),
            sprintf('%s::%s should %s for %s.', $subject, $ability, $expected ? 'pass' : 'fail', $role),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function policyMatrix(): array
    {
        // [ability, subject kind, admin, operator, viewer]
        $matrix = [
            'ServerPolicy viewAny' => ['viewAny', 'serverClass', true, true, true],
            'ServerPolicy view' => ['view', 'server', true, true, true],
            'ServerPolicy create' => ['create', 'serverClass', true, true, false],
            'ServerPolicy update' => ['update', 'server', true, true, false],
            'ServerPolicy delete' => ['delete', 'server', true, true, false],
            'ServerPolicy manageTokens' => ['manageTokens', 'serverClass', true, true, false],
            'ServerPolicy viewSnapshot' => ['viewSnapshot', 'server', true, false, false],
            'ServerPolicy purge' => ['purge', 'server', true, false, false],

            'ServerTokenPolicy viewAny' => ['viewAny', 'tokenClass', true, true, false],
            'ServerTokenPolicy create' => ['create', 'tokenClass', true, true, false],
            'ServerTokenPolicy revoke' => ['revoke', 'token', true, true, false],
            'ServerTokenPolicy rotate' => ['rotate', 'token', true, true, false],

            'AlertPolicy viewAny' => ['viewAny', 'alertClass', true, true, true],
            'AlertPolicy view' => ['view', 'alert', true, true, true],
            'AlertPolicy acknowledge' => ['acknowledge', 'alert', true, true, false],
            'AlertPolicy resolve' => ['resolve', 'alert', true, true, false],

            'SettingPolicy viewAny' => ['viewAny', 'settingClass', true, false, false],
            'SettingPolicy view' => ['view', 'setting', true, false, false],
            'SettingPolicy update' => ['update', 'setting', true, false, false],

            'UserPolicy viewAny' => ['viewAny', 'userClass', true, false, false],
            'UserPolicy view' => ['view', 'user', true, false, false],
            'UserPolicy create' => ['create', 'userClass', true, false, false],
            'UserPolicy update' => ['update', 'user', true, false, false],
            'UserPolicy delete' => ['delete', 'user', true, false, false],

            'AuditPolicy viewAny' => ['viewAny', 'auditClass', true, true, false],
            'AuditPolicy view' => ['view', 'audit', true, false, false],
        ];

        $cases = [];

        foreach ($matrix as $label => [$ability, $subject, $admin, $operator, $viewer]) {
            $expected = ['admin' => $admin, 'operator' => $operator, 'viewer' => $viewer];

            foreach (self::ROLES as $role) {
                $cases[sprintf('%s as %s', $label, $role)] = [$ability, $subject, $role, $expected[$role]];
            }
        }

        return $cases;
    }

    private function resolveSubject(string $kind): object|string
    {
        return match ($kind) {
            'server' => new Server,
            'serverClass' => Server::class,
            'token' => new ServerToken,
            'tokenClass' => ServerToken::class,
            'alert' => new Alert,
            'alertClass' => Alert::class,
            'setting' => new Setting,
            'settingClass' => Setting::class,
            'user' => new User,
            'userClass' => User::class,
            'audit' => new AuditEvent,
            'auditClass' => AuditEvent::class,
            default => throw new \InvalidArgumentException("Unknown policy subject [{$kind}]."),
        };
    }
}
