<?php

namespace App\Http\Middleware;

use App\Models\ContractorDelegation;
use App\Models\Scope;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveContractorActor
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $scope = $request->route('scope');
        $contractorId = $request->session()->get('contractor.actor_id');
        $actingScopeId = $request->session()->get('contractor.scope_id');

        if (! $scope instanceof Scope || $contractorId === null || $actingScopeId !== $scope->id) {
            return $next($request);
        }

        $contractor = User::query()
            ->whereKey($contractorId)
            ->where('type', 'virtual')
            ->where('status', 'active')
            ->where('is_active', true)
            ->first();

        $isMember = $contractor !== null && $scope->members()
            ->where('user_id', $contractor->id)
            ->where('is_active', true)
            ->exists();
        $isDelegated = $contractor !== null && ContractorDelegation::query()
            ->where('scope_id', $scope->id)
            ->where('operator_id', $request->user()->id)
            ->where('contractor_id', $contractor->id)
            ->where('is_active', true)
            ->exists();

        if (! $isMember || ! $isDelegated) {
            $request->session()->forget(['contractor.actor_id', 'contractor.scope_id']);

            return $next($request);
        }

        $request->attributes->set('contractor_actor', $contractor);
        $request->attributes->set('contractor_operator', $request->user());

        return $next($request);
    }
}
