<?php

namespace App\Policies;

use App\Models\InboundMessage;
use App\Models\User;

class InboundMessagePolicy
{
    public function view(User $user, InboundMessage $m): bool
    {
        $m->loadMissing('account');

        return (int) $m->account?->user_id === (int) $user->id;
    }

    public function update(User $user, InboundMessage $m): bool
    {
        return $this->view($user, $m);
    }
}
