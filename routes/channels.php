<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(prefixo_canal_broadcast().'App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel(prefixo_canal_broadcast().'presenca.chat', function (User $user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
