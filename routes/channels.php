<?php

use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppConversation;
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

/*
|--------------------------------------------------------------------------
| WhatsApp
|--------------------------------------------------------------------------
|
| Deliberately prefixed, so a WhatsApp channel can never be confused with the
| internal chat's `conversation.{id}`. Each one authorises through the same
| policy the HTTP layer uses — a websocket is not a way around authorization.
|
*/

// One customer thread. Same rule as opening it over HTTP.
Broadcast::channel('whatsapp.conversation.{conversationId}', function (User $user, $conversationId) {
    $conversation = WhatsAppConversation::find($conversationId);

    return $conversation !== null && $user->can('view', $conversation);
});

// An agent's own channel: threads assigned to them, and their unread badge.
Broadcast::channel('whatsapp.user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

// The global inbox feed. Only those who may see every conversation may listen,
// because the payload names conversations across all brands.
Broadcast::channel('whatsapp.inbox', function (User $user) {
    return $user->can('view all whatsapp');
});
