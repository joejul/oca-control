<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\ClosureController;
use App\Controllers\ConsumptionController;
use App\Controllers\EventsController;
use App\Controllers\OvertimeController;
use App\Controllers\VacationController;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

final class Kernel
{
    private Router $router;
    private Auth $auth;
    /** @var array<string,mixed>|null */
    private ?array $currentUser = null;
    private bool $userResolved = false;

    public function __construct(private readonly Config $config)
    {
        Database::init($config);
        Mailer::init($config);
        $this->auth   = new Auth($config);
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function handle(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                return new Response(204);
            }
            return $this->router->dispatch($request);
        } catch (HttpException $e) {
            return Response::json(
                array_filter([
                    'error'   => $e->getMessage(),
                    'details' => $e->details ?: null,
                ], static fn ($v) => $v !== null),
                $e->getCode() ?: 500
            );
        } catch (\Throwable $e) {
            error_log('[oca-control] ' . $e);
            $isLocal = ($this->config->get('cors_origins') !== []);
            return Response::json([
                'error' => 'Error interno del servidor.',
                'debug' => $isLocal ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function registerRoutes(): void
    {
        $authC        = new AuthController($this->auth);
        $overtimeC    = new OvertimeController();
        $consumptionC = new ConsumptionController();
        $usersC       = new AdminUserController();
        $closureC     = new ClosureController();
        $vacationC    = new VacationController();
        $eventsC      = new EventsController();

        // --- Publicas ---
        $this->router->post('/auth/login', fn (Request $r) => $authC->login($r));

        // --- Autenticadas (cualquier rol) ---
        $this->router->post('/auth/logout', $this->protect(fn (Request $r) => $authC->logout($r)));
        $this->router->get('/me', $this->protect(fn (Request $r) => $authC->me($this->currentUser)));
        $this->router->get('/collaborators', $this->protect(
            fn (Request $r) => $usersC->collaborators()
        ));

        $this->router->post('/overtime', $this->protect(
            fn (Request $r) => $overtimeC->create($r, $this->currentUser)
        ));
        $this->router->get('/overtime/mine', $this->protect(
            fn (Request $r) => $overtimeC->mine($this->currentUser)
        ));
        $this->router->post('/consumption', $this->protect(
            fn (Request $r) => $consumptionC->create($r, $this->currentUser)
        ));
        $this->router->get('/consumption/mine', $this->protect(
            fn (Request $r) => $consumptionC->mine($this->currentUser)
        ));
        $this->router->post('/vacation', $this->protect(
            fn (Request $r) => $vacationC->create($r, $this->currentUser)
        ));
        $this->router->get('/vacation/mine', $this->protect(
            fn (Request $r) => $vacationC->mine($this->currentUser)
        ));

        // --- Agenda (cualquier usuario autenticado) ---
        $this->router->get('/agenda/events', $this->protect(
            fn (Request $r) => $eventsC->list($r)
        ));
        $this->router->post('/agenda/events', $this->protect(
            fn (Request $r) => $eventsC->create($r, $this->currentUser)
        ));
        $this->router->patch('/agenda/events/{id}', $this->protect(
            fn (Request $r, array $a) => $eventsC->update($this->currentUser, (int) $a['id'], $r)
        ));
        $this->router->post('/agenda/events/{id}/delete', $this->protect(
            fn (Request $r, array $a) => $eventsC->delete($this->currentUser, (int) $a['id'])
        ));
        $this->router->post('/agenda/generate', $this->protect(
            fn (Request $r) => $eventsC->generate($this->currentUser, $r)
        ));

        // --- Solo admin ---
        $this->router->get('/admin/overtime', $this->adminOnly(
            fn (Request $r) => $overtimeC->adminList($r)
        ));
        $this->router->post('/admin/overtime/{id}/approve', $this->adminOnly(
            fn (Request $r, array $a) => $overtimeC->review($this->currentUser, (int) $a['id'], 'approved', $r)
        ));
        $this->router->post('/admin/overtime/{id}/reject', $this->adminOnly(
            fn (Request $r, array $a) => $overtimeC->review($this->currentUser, (int) $a['id'], 'rejected', $r)
        ));
        $this->router->post('/admin/overtime/{id}/void', $this->adminOnly(
            fn (Request $r, array $a) => $overtimeC->void($this->currentUser, (int) $a['id'], $r)
        ));
        $this->router->get('/admin/vacation', $this->adminOnly(
            fn (Request $r) => $vacationC->adminList($r)
        ));
        $this->router->post('/admin/vacation/{id}/approve', $this->adminOnly(
            fn (Request $r, array $a) => $vacationC->review($this->currentUser, (int) $a['id'], 'approved', $r)
        ));
        $this->router->post('/admin/vacation/{id}/reject', $this->adminOnly(
            fn (Request $r, array $a) => $vacationC->review($this->currentUser, (int) $a['id'], 'rejected', $r)
        ));
        $this->router->post('/admin/vacation/{id}/void', $this->adminOnly(
            fn (Request $r, array $a) => $vacationC->void($this->currentUser, (int) $a['id'], $r)
        ));
        $this->router->post('/admin/consumption/{id}/void', $this->adminOnly(
            fn (Request $r, array $a) => $consumptionC->void($this->currentUser, (int) $a['id'], $r)
        ));

        $this->router->get('/admin/users', $this->adminOnly(fn () => $usersC->list()));
        $this->router->post('/admin/users', $this->adminOnly(
            fn (Request $r) => $usersC->create($this->currentUser, $r)
        ));
        $this->router->patch('/admin/users/{id}', $this->adminOnly(
            fn (Request $r, array $a) => $usersC->update($this->currentUser, (int) $a['id'], $r)
        ));
        $this->router->post('/admin/users/{id}/reset-pin', $this->adminOnly(
            fn (Request $r, array $a) => $usersC->resetPin($this->currentUser, (int) $a['id'], $r)
        ));

        $this->router->get('/admin/closure/preview', $this->adminOnly(
            fn (Request $r) => $closureC->preview($r)
        ));
        $this->router->post('/admin/closure/generate', $this->adminOnly(
            fn (Request $r) => $closureC->generate($this->currentUser, $r)
        ));
        $this->router->get('/admin/closures', $this->adminOnly(fn () => $closureC->list()));
        $this->router->get('/admin/closures/{id}', $this->adminOnly(
            fn (Request $r, array $a) => $closureC->show((int) $a['id'])
        ));
        $this->router->get('/admin/closures/{id}/export.csv', $this->adminOnly(
            fn (Request $r, array $a) => $closureC->exportCsv((int) $a['id'])
        ));
        $this->router->get('/admin/closures/{id}/export.pdf', $this->adminOnly(
            fn (Request $r, array $a) => $closureC->exportPdf((int) $a['id'])
        ));
    }

    private function protect(callable $handler): callable
    {
        return function (Request $r, array $a = []) use ($handler) {
            $this->resolveUser($r);
            if ($this->currentUser === null) {
                throw HttpException::unauthorized();
            }
            return $handler($r, $a);
        };
    }

    private function adminOnly(callable $handler): callable
    {
        return function (Request $r, array $a = []) use ($handler) {
            $this->resolveUser($r);
            if ($this->currentUser === null) {
                throw HttpException::unauthorized();
            }
            if (($this->currentUser['role'] ?? null) !== 'admin') {
                throw HttpException::forbidden('Solo el administrador puede hacer esto.');
            }
            return $handler($r, $a);
        };
    }

    private function resolveUser(Request $r): void
    {
        if ($this->userResolved) {
            return;
        }
        $this->currentUser  = $this->auth->userFromToken($r->bearerToken());
        $this->userResolved = true;
    }
}
