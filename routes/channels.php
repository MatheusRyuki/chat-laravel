<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('presenca.chat', function (User $user) {
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
