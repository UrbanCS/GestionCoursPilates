<?php
/** @package Memi.Component.Memipilates */

declare(strict_types=1);

namespace Memi\Component\Memipilates\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;
use Joomla\Input\Input;

/**
 * Creates a Joomla account and its Memi client profile together.
 *
 * The Joomla user remains the authentication authority. This service only
 * stores studio-specific fields in #__memi_client_profiles and never logs or
 * returns a password.
 */
final class ClientManagementService
{
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly DatabaseTools $tools,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @return array{user_id:int,client_id:int,username:string}
     */
    public function createClient(Input $input, int $actorId): array
    {
        $name = $this->clean($input->getString('name', ''), 255);
        $username = $this->clean($input->getString('username', ''), 150);
        $email = strtolower($this->clean($input->getString('email', ''), 100));
        // Passwords are deliberately read without Joomla's text filtering so
        // a valid special character is never silently changed before the
        // password policy or confirmation check runs.
        $password = (string) $input->get('password', '', 'raw');
        $passwordConfirmation = (string) $input->get('password_confirm', '', 'raw');
        $phone = $this->clean($input->getString('phone', ''), 64);
        $locale = $input->getCmd('preferred_locale', 'fr-FR');

        if (
            $name === ''
            || !preg_match('/^[A-Za-z0-9._-]{3,150}$/D', $username)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($password) < 12
            || !hash_equals($password, $passwordConfirmation)
        ) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
        if (!in_array($locale, ['fr-FR', 'en-GB'], true)) {
            $locale = 'fr-FR';
        }

        $this->assertUserAvailable($username, $email);

        $user = new User();
        $userData = [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'password2' => $passwordConfirmation,
            'block' => 0,
            'sendEmail' => 0,
            'groups' => [$this->newUserGroupId()],
        ];
        if (!$user->bind($userData) || !$user->save()) {
            // Joomla validates its own password policy and account constraints.
            // Do not disclose a raw validation error to an administrator page.
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $userId = (int) $user->id;
        if ($userId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        try {
            if (!UserHelper::setUserGroups($userId, [$this->newUserGroupId()])) {
                throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
            }
            $profile = $this->tools->lockClientProfile($userId);
            $now = gmdate('Y-m-d H:i:s');
            $profileId = (int) $profile['id'];
            $identifier = $profileId;
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__memi_client_profiles'))
                ->set($this->db->quoteName('phone') . ' = :phone')
                ->set($this->db->quoteName('preferred_locale') . ' = :preferred_locale')
                ->set($this->db->quoteName('updated_at') . ' = :updated_at')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':phone', $phone)
                ->bind(':preferred_locale', $locale)
                ->bind(':updated_at', $now)
                ->bind(':id', $identifier, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();
            $this->audit->log($actorId, 'client.create', 'client', $profileId, null, [
                'user_id' => $userId,
                'username' => $username,
            ]);

            return ['user_id' => $userId, 'client_id' => $profileId, 'username' => $username];
        } catch (\Throwable $error) {
            // A Memi profile can be recreated lazily for a Joomla account, but
            // remove an account created moments ago when profile setup fails.
            try {
                $user->delete();
            } catch (\Throwable) {
                // Preserve the original failure; the user can be audited in
                // Joomla should an exceptional rollback also fail.
            }

            throw $error;
        }
    }

    /**
     * Prevent an administrator from booking an arbitrary Joomla account when
     * a form is tampered with. Manual booking is restricted to active Memi
     * client profiles, which is also the set displayed by the admin view.
     */
    public function assertActiveClient(int $userId): void
    {
        if ($userId <= 0) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }

        $identifier = $userId;
        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName('#__memi_client_profiles', 'cp'))
            ->join('INNER', $this->db->quoteName('#__users', 'u') . ' ON u.id = cp.user_id')
            ->where('cp.user_id = :user_id')
            ->where('cp.archived_at IS NULL')
            ->where('u.block = 0')
            ->bind(':user_id', $identifier, ParameterType::INTEGER);
        $this->db->setQuery($query, 0, 1);

        if (!(bool) $this->db->loadResult()) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
    }

    private function assertUserAvailable(string $username, string $email): void
    {
        $name = $username;
        $address = $email;
        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName('#__users'))
            ->where('(' . $this->db->quoteName('username') . ' = :username OR ' . $this->db->quoteName('email') . ' = :email)')
            ->bind(':username', $name)
            ->bind(':email', $address);
        $this->db->setQuery($query, 0, 1);
        if ((bool) $this->db->loadResult()) {
            throw new DomainException('COM_MEMIPILATES_ERROR_INVALID_REQUEST');
        }
    }

    private function newUserGroupId(): int
    {
        $configured = (int) Factory::getApplication()->get('new_usertype', 2);

        return $configured > 0 ? $configured : 2;
    }

    private function clean(string $value, int $length): string
    {
        return trim(mb_substr($value, 0, $length));
    }
}
