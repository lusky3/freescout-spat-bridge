<?php

namespace Tests\Unit;

use Modules\SpatBridge\Services\ActionPermissions;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeOptionStore;

class ActionPermissionsTest extends TestCase
{
    private function user(int $id, bool $admin)
    {
        return new class ($id, $admin) {
            public $id;
            private $admin;

            public function __construct(int $id, bool $admin)
            {
                $this->id = $id;
                $this->admin = $admin;
            }

            public function isAdmin(): bool
            {
                return $this->admin;
            }
        };
    }

    private function permissions(?string $json = null, string $key = 'etransfers'): ActionPermissions
    {
        $store = new FakeOptionStore();
        if ($json !== null) {
            $store->set('spatbridge.features.' . $key . '.actors', $json);
        }

        return new ActionPermissions($store);
    }

    public function test_defaults_are_admins_for_player_and_etransfers_users_for_waitlist(): void
    {
        $p = $this->permissions();

        $this->assertSame('admins', $p->mode('player'));
        $this->assertSame('admins', $p->mode('etransfers'));
        $this->assertSame('users', $p->mode('waitlist'));
        $this->assertSame('admins', $p->mode('something_new'));
        $this->assertSame([], $p->selectedUserIds('etransfers'));
    }

    public function test_admins_mode_allows_only_admins(): void
    {
        $p = $this->permissions('{"mode":"admins","user_ids":[7]}');

        $this->assertTrue($p->allows($this->user(1, true), 'etransfers'));
        $this->assertFalse($p->allows($this->user(7, false), 'etransfers'));
    }

    public function test_users_mode_allows_every_user(): void
    {
        $p = $this->permissions('{"mode":"users","user_ids":[]}');

        $this->assertTrue($p->allows($this->user(2, false), 'etransfers'));
        $this->assertTrue($p->allows($this->user(1, true), 'etransfers'));
    }

    public function test_selected_mode_allows_listed_users_and_always_admins(): void
    {
        $p = $this->permissions('{"mode":"selected","user_ids":[7,"8"]}');

        $this->assertSame([7, 8], $p->selectedUserIds('etransfers'));
        $this->assertTrue($p->allows($this->user(7, false), 'etransfers'));
        $this->assertTrue($p->allows($this->user(8, false), 'etransfers'));
        $this->assertFalse($p->allows($this->user(9, false), 'etransfers'));
        $this->assertTrue($p->allows($this->user(1, true), 'etransfers'));
    }

    public function test_no_user_is_never_allowed(): void
    {
        $this->assertFalse($this->permissions('{"mode":"users","user_ids":[]}')->allows(null, 'etransfers'));
    }

    /**
     * @dataProvider garbage
     */
    public function test_garbage_option_falls_back_to_the_default($raw): void
    {
        $store = new FakeOptionStore();
        $store->set('spatbridge.features.waitlist.actors', $raw);
        $p = new ActionPermissions($store);

        $this->assertSame('users', $p->mode('waitlist'));
        $this->assertSame([], $p->selectedUserIds('waitlist'));
    }

    public function garbage(): array
    {
        return [
            'not json' => ['{nope'],
            'empty string' => [''],
            'json scalar' => ['5'],
            'unknown mode' => ['{"mode":"everyone","user_ids":[1]}'],
            'mode not a string' => ['{"mode":["admins"]}'],
            'array value' => [['mode' => 'admins']],
        ];
    }

    public function test_bad_user_ids_are_dropped(): void
    {
        $p = $this->permissions('{"mode":"selected","user_ids":[3,"x",null,[4],2.5,"5"]}');

        $this->assertSame([3, 5], $p->selectedUserIds('etransfers'));
    }

    public function test_missing_user_ids_yield_an_empty_list(): void
    {
        $this->assertSame([], $this->permissions('{"mode":"selected"}')->selectedUserIds('etransfers'));
    }
}
