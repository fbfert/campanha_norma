<?php

namespace App\Services\Conversations;

use App\Models\Conversation;
use App\Models\ConversationMessage;

class ConversationMetricsService
{
    public function summary(): array
    {
        return [
            'new' => Conversation::where('status', 'new')->count(),
            'waiting_operator' => Conversation::where('status', 'waiting_operator')->count(),
            'waiting_contact' => Conversation::where('status', 'waiting_contact')->count(),
            'unread' => Conversation::where('unread_count', '>', 0)->count(),
            'unassigned' => Conversation::whereNull('assigned_user_id')->count(),
            'resolved_today' => Conversation::where('status', 'resolved')->whereDate('updated_at', today())->count(),
            'archived' => Conversation::where('is_archived', true)->count(),
            'manual_reply_failures' => ConversationMessage::where('direction', 'outgoing')->where('status', 'failed')->count(),
            'received_today' => ConversationMessage::where('direction', 'incoming')->whereDate('received_at', today())->count(),
            'manual_sent_today' => ConversationMessage::where('direction', 'outgoing')->where('status', 'sent')->whereDate('sent_at', today())->count(),
        ];
    }
}
