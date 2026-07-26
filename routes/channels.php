<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Personal channel — used for per-user notifications (chat unread badge).
Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

// A 1:1 conversation. Authorized for either participant, or anyone allowed to
// monitor chats (so admins can watch a thread live).
Broadcast::channel('conversation.{conversationId}', function (User $user, $conversationId) {
    $conversation = Conversation::find($conversationId);

    if (!$conversation) {
        return false;
    }

    return $conversation->hasParticipant($user->id) || $user->can('monitor chats');
});

// App-wide presence — drives online indicators.
Broadcast::channel('online', function (User $user) {
    return ['id' => $user->id, 'name' => $user->name];
});
