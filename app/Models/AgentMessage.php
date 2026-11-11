<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentMessage extends Model
{
    protected $fillable = ['agent_conversation_id', 'role', 'content', 'cards', 'steps', 'meta'];

    protected $casts = ['cards' => 'array', 'steps' => 'array', 'meta' => 'array'];

    // La auditoría (juicios de Jev, modelo) no sale por la API del estudiante.
    protected $hidden = ['meta'];

    public function conversation()
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }
}
