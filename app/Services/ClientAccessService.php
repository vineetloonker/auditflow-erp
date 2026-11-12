<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enforces role-based client & work visibility across staff and client portal users.
 */
class ClientAccessService
{
    public function visibleClientsQuery(User $user): Builder
    {
        $query = Client::query();

        if ($user->isAdminOrPartner()) {
            return $query;
        }

        if ($user->isClient()) {
            return $query->where('user_id', $user->id);
        }

        // Managers and employees see clients assigned to them (primary CA or secondary staff)
        if ($user->hasRole(User::ROLE_MANAGER)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_ca_id', $user->id)
                ->orWhereHas('staff', fn (Builder $sq) => $sq->where('users.id', $user->id));
        });
    }

    public function canAccessClient(User $user, Client $client): bool
    {
        if ($user->isAdminOrPartner() || $user->hasRole(User::ROLE_MANAGER)) {
            return true;
        }

        if ($user->isClient()) {
            return (int) $client->user_id === (int) $user->id;
        }

        return (int) $client->assigned_ca_id === (int) $user->id
            || $client->staff()->where('users.id', $user->id)->exists();
    }

    public function resolveClientForPortalUser(User $user): Client
    {
        return Client::query()
            ->where('user_id', $user->id)
            ->firstOrFail();
    }
}

