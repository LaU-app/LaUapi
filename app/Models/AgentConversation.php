<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Chat de un estudiante con el agente de compras. */
class AgentConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'assistant_decision_id', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(AgentMessage::class)->orderBy('id');
    }

    public function decision()
    {
        return $this->belongsTo(AssistantDecision::class, 'assistant_decision_id');
    }
}
