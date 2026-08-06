<?php
/**
 * Integration tests for access control.
 *
 * These run against a real WordPress with a real database, which is the only
 * way to test the things that actually broke: permission resolution across a
 * folder tree, and throttling under concurrency. A stand-in database cannot
 * reproduce either, because both depend on how the real one behaves.
 *
 * @package HilgVault
 */

declare(strict_types=1);

namespace ClarityWeb\HilgVault\Tests\Integration;

use ClarityWeb\HilgVault\Access\AccessPolicy;
use ClarityWeb\HilgVault\Install\Schema;
use ClarityWeb\HilgVault\Model\FolderRepository;
use WP_UnitTestCase;

final class AccessPolicyTest extends WP_UnitTestCase
{
    private int $administrator;
    private int $member;

    public function set_up(): void
    {
        parent::set_up();

        Schema::installTables();
        Schema::registerRoles();

        $this->administrator = self::factory()->user->create(['role' => 'administrator']);
        $this->member        = self::factory()->user->create(['role' => 'hilg_network_member']);

        AccessPolicy::flushCache();
    }

    public function test_public_folder_is_visible_to_anonymous_visitors(): void
    {
        $folder = FolderRepository::create(['name' => 'Public', 'access_mode' => 'public']);

        $this->assertIsInt($folder);
        $this->assertTrue(AccessPolicy::canViewFolder($folder, 0));
    }

    public function test_private_folder_is_hidden_from_anonymous_visitors(): void
    {
        $folder = FolderRepository::create(['name' => 'Private', 'access_mode' => 'private']);

        $this->assertFalse(AccessPolicy::canViewFolder($folder, 0));
    }

    public function test_private_folder_is_hidden_from_a_member_without_a_grant(): void
    {
        $folder = FolderRepository::create(['name' => 'Private', 'access_mode' => 'private']);

        $this->assertFalse(AccessPolicy::canViewFolder($folder, $this->member));
    }

    public function test_a_grant_opens_a_private_folder_for_that_role(): void
    {
        global $wpdb;

        $folder = FolderRepository::create(['name' => 'Members only', 'access_mode' => 'private']);

        $wpdb->insert(Schema::tableFolderRoles(), [
            'folder_id' => $folder,
            'role_slug' => 'hilg_network_member',
            'can_view'  => 1,
        ]);

        AccessPolicy::flushCache();

        $this->assertTrue(AccessPolicy::canViewFolder($folder, $this->member));
    }

    public function test_grants_are_inherited_by_child_folders(): void
    {
        global $wpdb;

        $parent = FolderRepository::create(['name' => 'Parent', 'access_mode' => 'private']);
        $child  = FolderRepository::create(['name' => 'Child', 'parent_id' => $parent, 'access_mode' => 'inherit']);

        $wpdb->insert(Schema::tableFolderRoles(), [
            'folder_id' => $parent,
            'role_slug' => 'hilg_network_member',
            'can_view'  => 1,
        ]);

        AccessPolicy::flushCache();

        $this->assertTrue(
            AccessPolicy::canViewFolder($child, $this->member),
            'A child folder should inherit the grant from its parent.'
        );
    }

    public function test_closing_a_parent_closes_its_children(): void
    {
        $parent = FolderRepository::create(['name' => 'Parent', 'access_mode' => 'public']);
        $child  = FolderRepository::create(['name' => 'Child', 'parent_id' => $parent, 'access_mode' => 'inherit']);

        AccessPolicy::flushCache();
        $this->assertTrue(AccessPolicy::canViewFolder($child, 0));

        FolderRepository::update($parent, ['access_mode' => 'private']);
        AccessPolicy::flushCache();

        $this->assertFalse(
            AccessPolicy::canViewFolder($child, 0),
            'Closing a parent must close everything inheriting from it.'
        );
    }

    public function test_an_unresolvable_folder_fails_closed(): void
    {
        $this->assertFalse(AccessPolicy::canViewFolder(999999, $this->administrator));
        $this->assertFalse(AccessPolicy::canViewFolder(0, $this->administrator));
        $this->assertFalse(AccessPolicy::canViewFolder(-1, $this->administrator));
    }

    public function test_a_manager_sees_folders_without_an_explicit_grant(): void
    {
        $folder = FolderRepository::create(['name' => 'Private', 'access_mode' => 'private']);

        $this->assertTrue(AccessPolicy::canViewFolder($folder, $this->administrator));
    }

    public function test_a_grant_for_a_role_that_does_not_exist_grants_nothing(): void
    {
        global $wpdb;

        $folder = FolderRepository::create(['name' => 'Ghost', 'access_mode' => 'private']);

        $wpdb->insert(Schema::tableFolderRoles(), [
            'folder_id' => $folder,
            'role_slug' => 'role_that_never_existed',
            'can_view'  => 1,
        ]);

        AccessPolicy::flushCache();

        $this->assertFalse(AccessPolicy::canViewFolder($folder, $this->member));
    }

    /**
     * The defect this test exists for: the counter used to be read and written
     * as two separate operations, so parallel attempts each missed the others'
     * increment and the limit never tripped. Counting log rows removes the race.
     */
    public function test_repeated_failures_close_the_gate(): void
    {
        $folder = FolderRepository::create([
            'name'        => 'Locked',
            'access_mode' => 'password',
            'password'    => 'correct-horse-battery',
        ]);

        for ($attempt = 0; $attempt < 12; $attempt++) {
            AccessPolicy::attemptPasswordUnlock($folder, 'wrong-' . $attempt);
        }

        $this->assertFalse(
            AccessPolicy::attemptPasswordUnlock($folder, 'correct-horse-battery'),
            'The correct password must be refused once the attempt limit is reached.'
        );
    }

    public function test_the_correct_password_is_accepted_before_the_limit(): void
    {
        $folder = FolderRepository::create([
            'name'        => 'Locked',
            'access_mode' => 'password',
            'password'    => 'correct-horse-battery',
        ]);

        AccessPolicy::attemptPasswordUnlock($folder, 'wrong-once');

        $this->assertTrue(AccessPolicy::attemptPasswordUnlock($folder, 'correct-horse-battery'));
    }

    public function test_changing_the_password_invalidates_the_old_one(): void
    {
        $folder = FolderRepository::create([
            'name'        => 'Locked',
            'access_mode' => 'password',
            'password'    => 'first-password',
        ]);

        $this->assertTrue(AccessPolicy::attemptPasswordUnlock($folder, 'first-password'));

        FolderRepository::update($folder, ['password' => 'second-password']);
        AccessPolicy::flushCache();

        $this->assertFalse(AccessPolicy::attemptPasswordUnlock($folder, 'first-password'));
        $this->assertTrue(AccessPolicy::attemptPasswordUnlock($folder, 'second-password'));
    }

    public function test_leaving_password_mode_clears_the_stored_password(): void
    {
        global $wpdb;

        $folder = FolderRepository::create([
            'name'        => 'Locked',
            'access_mode' => 'password',
            'password'    => 'to-be-cleared',
        ]);

        FolderRepository::update($folder, ['access_mode' => 'public']);

        $hash = $wpdb->get_var(
            $wpdb->prepare('SELECT password_hash FROM ' . Schema::tableFolders() . ' WHERE id = %d', $folder)
        );

        $this->assertNull($hash, 'A dormant password must not survive a change of access mode.');
    }
}
