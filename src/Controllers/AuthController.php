<?php

declare(strict_types=1);

namespace Kuyash\Controllers;

use Kuyash\Auth\Auth;
use Kuyash\Auth\LoginResult;
use Kuyash\Core\Csrf;
use Kuyash\Core\Response;
use Kuyash\Core\View;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
    ) {
    }

    /** @param array<string, string> $params */
    public function showLogin(array $params = []): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        return $this->loginPage();
    }

    /** @param array<string, string> $params */
    public function attemptLogin(array $params = []): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/dashboard');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        $result = $this->auth->attempt($email, $password, $ip);

        return match ($result) {
            LoginResult::Ok => Response::redirect('/dashboard', 303),
            // both messages stay generic: neither reveals whether the account
            // exists nor which throttle counter tripped
            LoginResult::Locked => $this->loginPage('Too many attempts. Please try again later.', $email),
            LoginResult::Invalid => $this->loginPage('Invalid email or password.', $email),
        };
    }

    /** @param array<string, string> $params */
    public function logout(array $params = []): Response
    {
        $this->auth->logout();

        return Response::redirect('/login', 303);
    }

    private function loginPage(?string $error = null, string $email = ''): Response
    {
        return Response::html($this->view->render('auth/login', [
            'title' => 'Sign in — Kuyash',
            'csrfField' => $this->csrf->field(),
            'error' => $error,
            'email' => $email,
        ]));
    }
}
