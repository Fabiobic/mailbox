<?php

declare(strict_types=1);

namespace Mailbox;

class Auth
{
    public static function login(string $username, string $password): bool
    {
        $pdo  = getDB();
        $stmt = $pdo->prepare('SELECT id, password, is_admin FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $username;
        $_SESSION['is_admin']  = (bool)$user['is_admin'];

        // Aggiorna last_login
        $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
            ->execute([$user['id']]);

        return true;
    }

    public static function logout(): void
    {
        session_destroy();
        session_start();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function require(): void
    {
        if (!self::check()) {
            // Redirect relativo: funziona sia con Apache che con PHP built-in server
            header('Location: login.php');
            exit;
        }
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function isAdmin(): bool
    {
        return (bool)($_SESSION['is_admin'] ?? false);
    }
}
