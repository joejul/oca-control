<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Validator;

final class AuthController
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function login(Request $r): Response
    {
        $username = Validator::requireString($r->input('username'), 'username', 1, 40);
        $secret   = $r->input('pin') ?? $r->input('secret') ?? $r->input('password');
        if (!is_string($secret) && !is_int($secret)) {
            throw HttpException::unprocessable('El PIN es obligatorio.', ['field' => 'pin']);
        }

        $result = $this->auth->login($username, (string) $secret);

        return Response::json([
            'token' => $result['token'],
            'user'  => $result['user'],
        ]);
    }

    public function logout(Request $r): Response
    {
        $this->auth->logout($r->bearerToken());
        return Response::noContent();
    }

    /** @param array<string,mixed> $user */
    public function changePin(Request $r, array $user): Response
    {
        $current = $r->input('current_pin');
        if (!is_string($current) && !is_int($current)) {
            throw HttpException::unprocessable('El PIN actual es obligatorio.', ['field' => 'current_pin']);
        }
        $new = Validator::requirePin($r->input('new_pin'), 'new_pin');

        $this->auth->changePin($user, (string) $current, $new, $r->bearerToken());

        return Response::json(['ok' => true]);
    }

    /** @param array<string,mixed> $user */
    public function me(array $user): Response
    {
        return Response::json(['user' => $this->auth->publicUser($user)]);
    }
}
