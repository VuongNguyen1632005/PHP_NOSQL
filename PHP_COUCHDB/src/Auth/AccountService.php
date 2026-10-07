<?php

declare(strict_types=1);

namespace App\Auth;

final class AccountService
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    /** @return array<string, mixed> */
    public function register(string $fullName, string $email, string $password, string $confirmation): array
    {
        $fullName = trim($fullName);
        $email = self::normalizeEmail($email);
        if ($fullName === '' || mb_strlen($fullName) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $fullName) === 1) {
            throw new AuthException('Vui lòng nhập họ tên hợp lệ (tối đa 100 ký tự).');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new AuthException('Email không hợp lệ.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new AuthException('Mật khẩu xác nhận không khớp.');
        }
        if (strlen($password) < 8 || strlen($password) > 128
            || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
            || preg_match('/[^a-zA-Z0-9]/', $password) !== 1) {
            throw new AuthException('Mật khẩu cần ít nhất 8 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt.');
        }

        if ($this->matchesUsername($email)) {
            throw new AuthException('Email này đã được đăng ký.');
        }

        $emailHash = hash('sha256', $email);
        $account = [
            '_id' => 'customer:REG:' . $emailHash,
            'type' => 'customer',
            'schema_version' => 2,
            'legacy_id' => 'REG' . $emailHash,
            'auth' => [
                'username' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'requires_password_reset' => false,
            ],
            'profile' => ['name' => $fullName, 'phone' => '', 'email' => $email, 'addresses' => []],
            'active' => true,
            'meta' => ['source' => 'PHP registration', 'created_at' => gmdate('c')],
        ];
        $this->accounts->create($account);
        return $account;
    }

    /** @return array<string, mixed> */
    public function authenticate(string $email, string $password): array
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || strlen($email) > 254 || preg_match('/[\s\x00-\x1F]/', $email) === 1 || $password === '') {
            throw new AuthException('Email hoặc mật khẩu không chính xác.');
        }

        $matches = array_merge(
            $this->accounts->byUsername('customer', $email),
            $this->accounts->byUsername('staff', $email),
        );
        if (count($matches) !== 1) {
            throw new AuthException('Email hoặc mật khẩu không chính xác.');
        }
        $account = $matches[0];
        $auth = is_array($account['auth'] ?? null) ? $account['auth'] : [];
        $hash = $auth['password_hash'] ?? null;
        if (($account['active'] ?? false) !== true) {
            throw new AuthException('Tài khoản đã bị khóa.');
        }
        if (!is_string($hash) || $hash === '') {
            throw new AuthException('Tài khoản chưa được cấp mật khẩu. Hãy liên hệ cửa hàng để được hỗ trợ.');
        }
        if (!password_verify($password, $hash)) {
            throw new AuthException('Email hoặc mật khẩu không chính xác.');
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $account['auth']['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->accounts->update($account);
        }
        return $account;
    }

    /** @return array<string, mixed> */
    public function changeCustomerPassword(string $documentId, string $currentPassword, string $newPassword, string $confirmation): array
    {
        $account = $this->accounts->byDocumentId($documentId);
        if ($account === null || ($account['active'] ?? false) !== true) {
            throw new AuthException('Không thể cập nhật tài khoản này. Hãy đăng nhập lại.');
        }
        $auth = is_array($account['auth'] ?? null) ? $account['auth'] : [];
        $hash = $auth['password_hash'] ?? null;
        if (!is_string($hash) || $hash === '' || !password_verify($currentPassword, $hash)) {
            throw new AuthException('Mật khẩu hiện tại không chính xác.');
        }
        if (!hash_equals($newPassword, $confirmation)) {
            throw new AuthException('Mật khẩu xác nhận không khớp.');
        }
        if (strlen($newPassword) < 12 || strlen($newPassword) > 128
            || preg_match('/[a-z]/', $newPassword) !== 1
            || preg_match('/[A-Z]/', $newPassword) !== 1
            || preg_match('/\d/', $newPassword) !== 1
            || preg_match('/[^a-zA-Z0-9]/', $newPassword) !== 1) {
            throw new AuthException('Mật khẩu mới cần 12–128 ký tự, gồm chữ hoa, chữ thường, số và ký tự đặc biệt.');
        }
        if (password_verify($newPassword, $hash)) {
            throw new AuthException('Mật khẩu mới phải khác mật khẩu hiện tại.');
        }

        $now = gmdate('c');
        $account['auth']['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $account['auth']['requires_password_reset'] = false;
        $account['auth']['password_updated_at'] = $now;
        $account['meta']['password_changed_at'] = $now;
        $account['meta']['password_changed_by'] = 'customer';
        $this->accounts->update($account);
        return $account;
    }

    private function matchesUsername(string $email): bool
    {
        return $this->accounts->byUsername('customer', $email) !== []
            || $this->accounts->byUsername('staff', $email) !== [];
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
