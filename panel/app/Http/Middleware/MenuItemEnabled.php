<?php

namespace App\Http\Middleware;

use App\Ocm\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Onderdelen die op "uit" staan (Instellingen → Menu) zijn niet bereikbaar. */
class MenuItemEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = Menu::keyForRoute($request->route()?->getName());
        if ($key !== null && !Menu::enabled($key)) {
            abort(404);
        }

        return $next($request);
    }
}
